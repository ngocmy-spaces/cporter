<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Storage\PathJail;
use App\Enums\ReleaseState;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_keep_'.uniqid();
    File::makeDirectory($this->base.'/releases', 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $this->admin = User::factory()->create();
    $this->project = Project::factory()->create([
        'slug' => 'shop',
        'base_path' => $this->base,
        'keep_releases' => 5,
    ]);

    // Seed 5 release dirs (rel1 oldest … rel5 newest) with matching DB rows. Later index = newer.
    // Distinct mtimes make retention's newest-N ordering deterministic (production dirs are minutes
    // apart; a test creates them in the same second).
    $this->releases = [];
    foreach (range(1, 5) as $i) {
        $dir = $this->base.'/releases/rel'.$i;
        File::makeDirectory($dir, 0777, true, true);
        File::put($dir.'/index.html', "v{$i}");
        touch($dir, time() - (5 - $i) * 100);
        $this->releases[$i] = Release::create([
            'project_id' => $this->project->id,
            'path' => $dir,
            'state' => $i === 5 ? ReleaseState::Active : ReleaseState::Superseded,
        ]);
    }

    // `current` points at the live release, exactly as a real deploy leaves it — this is what
    // protects the active release from pruning.
    symlink($this->base.'/releases/rel5', $this->base.'/current');
});

afterEach(fn () => File::deleteDirectory($this->base));

/** Model a rollback to an older release: repoint `current` + fix the release states. */
function makeLive(object $ctx, int $n): void
{
    @unlink($ctx->base.'/current');
    symlink($ctx->base.'/releases/rel'.$n, $ctx->base.'/current');

    foreach ($ctx->releases as $i => $release) {
        $release->forceFill(['state' => $i === $n ? ReleaseState::Active : ReleaseState::Superseded])->save();
    }
}

it('allows lowering keep_releases even when the live release falls outside the new window', function () {
    // docs/SPEC.md §21.4: the edit is always allowed; pruning never deletes the active release, so
    // the list may briefly hold more than keep_releases.
    makeLive($this, 3); // live is the #3 newest (index 2)

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/projects/shop', ['keep_releases' => 2])
        ->assertOk();

    // The setting is applied, and the live release survives even though it's outside the newest-2
    // window (rel4/rel5 are the newest two; rel3 is protected as the active release).
    expect($this->project->fresh()->keep_releases)->toBe(2)
        ->and(File::isDirectory($this->base.'/releases/rel3'))->toBeTrue()
        ->and($this->releases[3]->fresh()->state)->toBe(ReleaseState::Active)
        ->and(File::isDirectory($this->base.'/releases/rel5'))->toBeTrue()
        ->and(File::isDirectory($this->base.'/releases/rel4'))->toBeTrue()
        // Releases older than the window (and not live) are still pruned.
        ->and(File::isDirectory($this->base.'/releases/rel1'))->toBeFalse()
        ->and(File::isDirectory($this->base.'/releases/rel2'))->toBeFalse();
});

it('allows lowering keep_releases while the live release stays within the window', function () {
    makeLive($this, 3); // live is index 2, keep=3 keeps rel5, rel4, rel3

    $this->actingAs($this->admin)
        ->patchJson('/api/v1/projects/shop', ['keep_releases' => 3])
        ->assertOk();

    // Oldest two dirs pruned + rows marked; live + newer kept.
    expect(File::isDirectory($this->base.'/releases/rel1'))->toBeFalse()
        ->and(File::isDirectory($this->base.'/releases/rel2'))->toBeFalse()
        ->and(File::isDirectory($this->base.'/releases/rel3'))->toBeTrue()
        ->and($this->releases[1]->fresh()->state)->toBe(ReleaseState::Pruned)
        ->and($this->releases[2]->fresh()->state)->toBe(ReleaseState::Pruned)
        ->and($this->releases[3]->fresh()->state)->toBe(ReleaseState::Active);
});

it('prunes down to the newest N when lowering keep_releases with the newest live', function () {
    // live is rel5 (newest) from setup.
    $this->actingAs($this->admin)
        ->patchJson('/api/v1/projects/shop', ['keep_releases' => 2])
        ->assertOk();

    expect(File::isDirectory($this->base.'/releases/rel5'))->toBeTrue()
        ->and(File::isDirectory($this->base.'/releases/rel4'))->toBeTrue()
        ->and(File::isDirectory($this->base.'/releases/rel3'))->toBeFalse()
        ->and($this->releases[3]->fresh()->state)->toBe(ReleaseState::Pruned);
});

it('rejects keep_releases below 1', function () {
    $this->actingAs($this->admin)
        ->patchJson('/api/v1/projects/shop', ['keep_releases' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('keep_releases');
});

it('lists only re-activatable releases, hiding pruned ones', function () {
    $this->releases[1]->forceFill(['state' => ReleaseState::Pruned])->save();
    $this->releases[2]->forceFill(['state' => ReleaseState::Failed])->save();

    $res = $this->actingAs($this->admin)->getJson('/api/v1/projects/shop/releases')->assertOk();

    $ids = collect($res->json('data'))->pluck('id')->all();
    expect($ids)->toHaveCount(3)
        ->and($ids)->not->toContain($this->releases[1]->id)
        ->and($ids)->not->toContain($this->releases[2]->id);
});

it('reconciles superseded releases whose directory is gone (historical data self-heal)', function () {
    // Simulate a release pruned before this bookkeeping existed: row still superseded, dir removed.
    File::deleteDirectory($this->base.'/releases/rel1');

    $res = $this->actingAs($this->admin)->getJson('/api/v1/projects/shop/releases')->assertOk();

    $ids = collect($res->json('data'))->pluck('id')->all();
    expect($ids)->not->toContain($this->releases[1]->id)
        ->and($this->releases[1]->fresh()->state)->toBe(ReleaseState::Pruned)
        // A release whose dir still exists stays listed.
        ->and($ids)->toContain($this->releases[2]->id);
});
