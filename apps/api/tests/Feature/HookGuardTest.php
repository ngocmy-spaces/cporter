<?php

use App\Adapters\Command\CommandRunner;
use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Deploy\HookGuard;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeCommandRunner;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_guard_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $this->cmd = new FakeCommandRunner;
    app()->instance(CommandRunner::class, $this->cmd);

    $this->admin = User::factory()->create();

    // Self-contained artifact builder (kept local so this file runs standalone).
    $this->artifact = function (): UploadedFile {
        $path = sys_get_temp_dir().'/cporter_guard_up_'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('public/index.php', '<?php echo "hi";');
        $zip->close();

        return new UploadedFile($path, 'artifact.zip', 'application/zip', null, true);
    };
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(storage_path('app/artifacts/guarded'));
});

it('refuses to save a hook that drops the target schema', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Guarded',
        'base_path' => $this->base,
        'type' => 'laravel',
        'docroot_subpath' => 'public',
        'hooks' => ['pre_activate' => ['/usr/local/bin/php artisan migrate:fresh --force']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hooks');

    expect(Project::query()->count())->toBe(0);
});

it('refuses a destructive hook on update too', function () {
    $project = Project::factory()->create(['base_path' => $this->base]);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/projects/{$project->slug}", [
            'hooks' => ['post_activate' => ['php artisan db:wipe']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('hooks');
});

it('leaves ordinary hooks alone', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Normal',
        'base_path' => $this->base,
        'type' => 'laravel',
        'docroot_subpath' => 'public',
        'hooks' => ['pre_activate' => ['/usr/local/bin/php artisan migrate --force', 'php artisan config:cache']],
    ])->assertCreated();
});

it('matches whole tokens only', function () {
    expect(HookGuard::blocked('php artisan migrate --force'))->toBeNull()
        ->and(HookGuard::blocked('php artisan migrate:freshen'))->toBeNull()
        ->and(HookGuard::blocked('php artisan migrate:fresh --seed'))->toBe('migrate:fresh')
        ->and(HookGuard::blocked('php artisan db:wipe'))->toBe('db:wipe');
});

it('can be disabled by config', function () {
    config(['cporter.hooks.blocked_commands' => []]);

    expect(HookGuard::blocked('php artisan migrate:fresh'))->toBeNull();
});

it('refuses to RUN a destructive hook stored before the guard existed', function () {
    $project = Project::factory()->create([
        'slug' => 'guarded',
        'base_path' => $this->base,
        'type' => ProjectType::Laravel,
        'docroot_subpath' => 'public',
        'shared_paths' => [],
        'health_check_url' => null,
        // Written straight to the DB — bypasses the validation guard, as a pre-upgrade row would.
        'hooks' => ['pre_activate' => ['php artisan migrate:fresh --force']],
    ]);

    ['token' => $token] = app(ApiKeyService::class)->generate('ci', ['deploy', 'read'], $project->id);

    $artifact = ($this->artifact)();
    $created = $this->withToken($token)->post('/api/v1/projects/guarded/deployments', [
        'artifact' => $artifact,
        'sha256' => hash_file('sha256', $artifact->getPathname()),
    ])->json('data');

    Artisan::call('cporter:run-jobs');

    $deployment = Deployment::find($created['id']);
    $failed = collect($deployment->steps)->firstWhere('status', 'failed');

    expect($deployment->status->value)->toBe('failed')
        ->and($this->cmd->ran)->toBe([])                          // never shelled out
        ->and(file_exists($this->base.'/current'))->toBeFalse()   // never activated
        ->and($failed['error'])->toContain('migrate:fresh')
        ->and($failed['error'])->toContain('blocked');
});
