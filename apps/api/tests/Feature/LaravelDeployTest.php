<?php

use App\Adapters\Command\CommandRunner;
use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeCommandRunner;

// buildZip() / upload() are defined in DeployPipelineTest.php (global test helpers).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_lv_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $this->cmd = new FakeCommandRunner;
    app()->instance(CommandRunner::class, $this->cmd);

    $this->project = Project::factory()->create([
        'slug' => 'app',
        'base_path' => $this->base,
        'type' => ProjectType::Laravel,
        'docroot_subpath' => 'public',
        'shared_paths' => ['.env', 'storage'],
        'keep_releases' => 5,
        'health_check_url' => null,
        'hooks' => [
            'pre_activate' => ['php artisan migrate --force'],
            'post_activate' => ['php artisan queue:restart'],
        ],
    ]);

    ['token' => $this->token] = app(ApiKeyService::class)->generate('ci', ['deploy', 'read'], $this->project->id);

    $this->deploy = function () {
        $zip = buildZip(['public/index.php' => '<?php echo "hi";', '.env' => 'APP=1']);

        return $this->withToken($this->token)->post('/api/v1/projects/app/deployments', [
            'artifact' => upload($zip),
            'sha256' => hash_file('sha256', $zip),
        ]);
    };
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(storage_path('app/artifacts/app'));
});

it('stages a Laravel deploy to hooks_pending and holds the lock (no activation yet)', function () {
    ($this->deploy)()
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'hooks_pending');

    expect(file_exists($this->base.'/current'))->toBeFalse()      // not activated yet
        ->and(file_exists($this->base.'/deploy.lock'))->toBeTrue() // lock held for the cron finalize
        ->and($this->cmd->ran)->toBe([]);                          // hooks not run in web context
});

it('finalizes a Laravel deploy via the cron-worker (hooks → activate → success)', function () {
    $created = ($this->deploy)()->json('data');

    Artisan::call('cporter:run-jobs');

    $deployment = Deployment::find($created['id']);
    expect($deployment->status->value)->toBe('success')
        ->and(is_link($this->base.'/current'))->toBeTrue()
        ->and(file_get_contents($this->base.'/current/public/index.php'))->toBe('<?php echo "hi";')
        ->and(file_exists($this->base.'/deploy.lock'))->toBeFalse() // lock released
        ->and(Release::find($created['release_id'])->state->value)->toBe('active');

    // Hooks ran in the release dir, verbatim, in order.
    $commands = collect($this->cmd->ran)->pluck('command')->all();
    expect($commands)->toBe(['php artisan migrate --force', 'php artisan queue:restart'])
        ->and($this->cmd->ran[0]['cwd'])->toBe(realpath($this->base).'/releases/'.basename(Release::find($created['release_id'])->path));

    // A successful hook keeps its output on the step, so a "success but did nothing" hook is visible.
    $hookStep = collect($deployment->steps)->firstWhere('name', 'hook:pre_activate:php artisan migrate --force');
    expect($hookStep['status'])->toBe('success')
        ->and($hookStep['note'] ?? null)->toContain('ok: php artisan migrate --force');
});

it('fails without activating when a pre-activate hook fails', function () {
    $this->cmd->failOn = ['migrate' => 1];

    $created = ($this->deploy)()->json('data');
    Artisan::call('cporter:run-jobs');

    $deployment = Deployment::find($created['id']);
    expect($deployment->status->value)->toBe('failed')
        ->and(file_exists($this->base.'/current'))->toBeFalse()       // never activated
        ->and(file_exists($this->base.'/deploy.lock'))->toBeFalse()   // lock released
        ->and(collect($this->cmd->ran)->pluck('command'))->not->toContain('php artisan queue:restart');
});

it('marks hooks as manual when the shell is unavailable', function () {
    $this->cmd->available = false;

    $created = ($this->deploy)()->json('data');
    Artisan::call('cporter:run-jobs');

    $deployment = Deployment::find($created['id']);
    expect($deployment->status->value)->toBe('failed');
    $failedStep = collect($deployment->steps)->firstWhere('status', 'failed');
    expect($failedStep['error'])->toContain('run manually');
});
