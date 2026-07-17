<?php

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectType;
use App\Enums\ReleaseState;
use App\Models\ApiKey;
use App\Models\Artifact;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('casts enums and JSON, and uses slug as route key', function () {
    $project = Project::factory()->laravel()->create();

    expect($project->type)->toBe(ProjectType::Laravel)
        ->and($project->type->requiresCommandRunner())->toBeTrue()
        ->and($project->shared_paths)->toBe(['.env', 'storage'])
        ->and($project->hooks)->toBeArray()
        ->and($project->getRouteKeyName())->toBe('slug');
});

it('wires up all project relationships', function () {
    $project = Project::factory()->create();

    $artifact = Artifact::create([
        'project_id' => $project->id,
        'filename' => 'cporter-0.1.0.zip',
        'size' => 12345,
        'sha256' => str_repeat('a', 64),
    ]);

    $release = Release::create([
        'project_id' => $project->id,
        'artifact_id' => $artifact->id,
        'version' => '20260718_001',
        'path' => "{$project->base_path}/releases/20260718_001",
    ]);

    $deployment = Deployment::create([
        'project_id' => $project->id,
        'release_id' => $release->id,
    ]);

    ApiKey::create([
        'name' => 'ci-token',
        'prefix' => 'cpk_abc123',
        'hash' => str_repeat('b', 64),
        'scopes' => ['deploy'],
        'project_id' => $project->id,
    ]);

    AuditLog::create([
        'action' => 'deployment.created',
        'subject_type' => Deployment::class,
        'subject_id' => $deployment->id,
        'meta' => ['trigger' => 'api'],
    ]);

    expect($project->releases)->toHaveCount(1)
        ->and($project->artifacts)->toHaveCount(1)
        ->and($project->deployments)->toHaveCount(1)
        ->and($project->apiKeys)->toHaveCount(1)
        ->and($release->project->is($project))->toBeTrue()
        ->and($release->artifact->is($artifact))->toBeTrue()
        ->and($release->state)->toBe(ReleaseState::Pending)
        ->and($deployment->status)->toBe(DeploymentStatus::Queued)
        ->and($deployment->trigger)->toBe(DeploymentTrigger::Api)
        ->and($deployment->release->is($release))->toBeTrue();
});

it('hides the api key hash and defaults an audit log to created_at only', function () {
    $project = Project::factory()->create();

    $key = ApiKey::create([
        'name' => 'ci',
        'prefix' => 'cpk_x',
        'hash' => str_repeat('c', 64),
        'project_id' => $project->id,
    ]);

    expect($key->toArray())->not->toHaveKey('hash');

    $log = AuditLog::create(['action' => 'test.event']);
    expect($log->created_at)->not->toBeNull()
        ->and($log->updated_at)->toBeNull();
});
