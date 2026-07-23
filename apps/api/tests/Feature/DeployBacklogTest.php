<?php

use App\Adapters\Command\CommandRunner;
use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Deploy\DeployDispatcher;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeCommandRunner;

// buildZip() / upload() are global helpers defined in DeployPipelineTest.php.

uses(RefreshDatabase::class);

/** A Laravel project deploys in two stages (hooks_pending → finalize), so a deploy stays "busy"
 *  holding the lock without completing inline — the clean way to observe FIFO backlog under sync. */
function makeLaravelProject(string $slug, string $base): Project
{
    File::makeDirectory($base, 0777, true, true);

    return Project::factory()->create([
        'slug' => $slug,
        'base_path' => $base,
        'type' => ProjectType::Laravel,
        'docroot_subpath' => 'public',
        'shared_paths' => ['.env'],
        'keep_releases' => 5,
        'health_check_url' => null,
        'hooks' => ['pre_activate' => ['php artisan migrate --force'], 'post_activate' => []],
    ]);
}

function deployTo(string $slug, string $token, array $headers = [])
{
    $zip = buildZip(['public/index.php' => '<?php echo 1;', '.env' => 'X=1']);

    return test()->withToken($token)->post("/api/v1/projects/{$slug}/deployments", [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ], $headers);
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/cporter_bk_'.uniqid();
    File::makeDirectory($this->root, 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->root]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);
    app()->instance(CommandRunner::class, new FakeCommandRunner);

    $this->project = makeLaravelProject('app', $this->root.'/app');
    ['token' => $this->token] = app(ApiKeyService::class)->generate('ci', ['deploy', 'read'], $this->project->id);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory(storage_path('app/artifacts/app'));
    File::deleteDirectory(storage_path('app/artifacts/api2'));
});

it('queues a second deploy and runs it only after the first finalizes (FIFO)', function () {
    $d1 = deployTo('app', $this->token)->assertStatus(202)->assertJsonPath('data.status', 'hooks_pending')->json('data');
    $d2 = deployTo('app', $this->token)->assertStatus(202)->assertJsonPath('data.status', 'queued')->json('data');

    // D2 is parked behind D1 — not started while D1 holds the lock.
    expect(Deployment::find($d2['id'])->status->value)->toBe('queued');

    // Finalize pass: D1 → success + lock released; the tail dispatch starts D2 (stages to hooks_pending).
    Artisan::call('cporter:run-jobs');
    expect(Deployment::find($d1['id'])->status->value)->toBe('success')
        ->and(Deployment::find($d2['id'])->status->value)->toBe('hooks_pending');

    // Second pass finalizes D2.
    Artisan::call('cporter:run-jobs');
    expect(Deployment::find($d2['id'])->status->value)->toBe('success');
});

it('runs deploys of different projects independently (cross-project)', function () {
    $p2 = makeLaravelProject('api2', $this->root.'/api2');
    ['token' => $token2] = app(ApiKeyService::class)->generate('ci2', ['deploy', 'read'], $p2->id);

    // Project 1 → busy (hooks_pending, holds its own lock).
    deployTo('app', $this->token)->assertJsonPath('data.status', 'hooks_pending');

    // Project 2 is NOT blocked by project 1 — it starts immediately on its own lock.
    deployTo('api2', $token2)->assertStatus(202)->assertJsonPath('data.status', 'hooks_pending');
});

it('starts exactly one queued deploy per project per dispatch pass (FIFO order)', function () {
    deployTo('app', $this->token)->assertJsonPath('data.status', 'hooks_pending'); // D1 busy
    $d2 = deployTo('app', $this->token)->json('data');
    $d3 = deployTo('app', $this->token)->json('data');
    expect(Deployment::find($d2['id'])->status->value)->toBe('queued')
        ->and(Deployment::find($d3['id'])->status->value)->toBe('queued');

    // While the project is busy, an explicit dispatch pass starts nothing.
    expect(app(DeployDispatcher::class)->dispatchPending())->toBe(0);

    // Freeing the project (finalize D1) starts exactly the OLDEST queued (D2); D3 stays queued.
    Artisan::call('cporter:run-jobs');
    expect(Deployment::find($d2['id'])->status->value)->toBe('hooks_pending')
        ->and(Deployment::find($d3['id'])->status->value)->toBe('queued');
});

it('replays an idempotent retry of a queued deploy without duplicating it', function () {
    deployTo('app', $this->token)->assertJsonPath('data.status', 'hooks_pending'); // busy

    $first = deployTo('app', $this->token, ['Idempotency-Key' => 'K1'])->assertStatus(202)->json('data');
    expect($first['status'])->toBe('queued');

    $countBefore = Deployment::count();
    $replay = deployTo('app', $this->token, ['Idempotency-Key' => 'K1'])->assertStatus(200)->json('data');

    expect($replay['id'])->toBe($first['id'])
        ->and(Deployment::count())->toBe($countBefore); // no duplicate row
});
