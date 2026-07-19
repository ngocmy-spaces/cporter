<?php

use App\Adapters\Storage\CpanelFilesystemAdapter;
use App\Domain\Storage\PathJail;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/cporter_rel_'.uniqid();
    File::makeDirectory($this->root.'/releases', 0777, true, true);
    $this->adapter = new CpanelFilesystemAdapter(new PathJail([$this->root]));
    $this->mkRelease = function (string $id, string $file = 'index.html', string $body = 'x') {
        $dir = $this->root.'/releases/'.$id;
        File::makeDirectory($dir, 0777, true, true);
        File::put($dir.'/'.$file, $body);

        return $dir;
    };
});

afterEach(fn () => File::deleteDirectory($this->root));

it('activates a release via an atomic symlink and reports currentTarget', function () {
    expect($this->adapter->currentTarget($this->root))->toBeNull();

    $r1 = ($this->mkRelease)('r1');
    $this->adapter->activate($r1, $this->root.'/current');

    expect(is_link($this->root.'/current'))->toBeTrue()
        ->and($this->adapter->currentTarget($this->root))->toBe(realpath($r1))
        ->and(file_get_contents($this->root.'/current/index.html'))->toBe('x');

    // Re-activating swaps the symlink in place (atomic replace).
    $r2 = ($this->mkRelease)('r2', body: 'y');
    $this->adapter->activate($r2, $this->root.'/current');

    expect($this->adapter->currentTarget($this->root))->toBe(realpath($r2))
        ->and(file_get_contents($this->root.'/current/index.html'))->toBe('y');
});

it('links shared paths and persists them across releases', function () {
    $shared = $this->root.'/shared';

    // First release ships a .env; storage does not exist yet.
    $r1 = ($this->mkRelease)('r1');
    File::put($r1.'/.env', 'APP=1');

    $this->adapter->linkShared($r1, $shared, ['.env', 'storage']);

    expect(is_link($r1.'/.env'))->toBeTrue()
        ->and(is_file($shared.'/.env'))->toBeTrue()
        ->and(file_get_contents($r1.'/.env'))->toBe('APP=1')  // seeded into shared
        ->and(is_link($r1.'/storage'))->toBeTrue()
        ->and(is_dir($shared.'/storage'))->toBeTrue();

    // A second release with a different .env must NOT clobber the shared copy.
    $r2 = ($this->mkRelease)('r2');
    File::put($r2.'/.env', 'APP=2');

    $this->adapter->linkShared($r2, $shared, ['.env', 'storage']);

    expect(file_get_contents($r2.'/.env'))->toBe('APP=1'); // shared wins
});

it('seeds a typed file entry as a file, not a directory', function () {
    $shared = $this->root.'/shared';

    // Artifact ships .env → it is seeded into shared/ as a FILE.
    $r1 = ($this->mkRelease)('r1');
    File::put($r1.'/.env', 'APP=1');

    $this->adapter->linkShared($r1, $shared, [
        ['path' => '.env', 'type' => 'file'],
        ['path' => 'storage', 'type' => 'dir'],
    ]);

    expect(is_file($shared.'/.env'))->toBeTrue()
        ->and(is_dir($shared.'/.env'))->toBeFalse()   // regression: never a folder
        ->and(is_link($r1.'/.env'))->toBeTrue()
        ->and(file_get_contents($r1.'/.env'))->toBe('APP=1')
        ->and(is_dir($shared.'/storage'))->toBeTrue();
});

it('refuses to create an empty shared file when the artifact ships none', function () {
    $shared = $this->root.'/shared';

    // Release does NOT ship .env and shared/.env does not exist → must fail loudly
    // instead of silently creating a directory (or an empty secret file).
    $r1 = ($this->mkRelease)('r1');

    expect(fn () => $this->adapter->linkShared($r1, $shared, [['path' => '.env', 'type' => 'file']]))
        ->toThrow(App\Domain\Storage\StorageException::class);

    expect(file_exists($shared.'/.env'))->toBeFalse();
});

it('reuses an operator-created shared file across releases', function () {
    $shared = $this->root.'/shared';

    // Operator bootstraps shared/.env by hand (the recommended flow).
    File::makeDirectory($shared, 0777, true, true);
    File::put($shared.'/.env', 'APP=prod');

    $r1 = ($this->mkRelease)('r1');
    $this->adapter->linkShared($r1, $shared, [['path' => '.env', 'type' => 'file']]);

    expect(is_link($r1.'/.env'))->toBeTrue()
        ->and(file_get_contents($r1.'/.env'))->toBe('APP=prod');
});

it('prunes old releases but never the active one', function () {
    foreach (['r1', 'r2', 'r3', 'r4', 'r5'] as $i => $id) {
        $dir = ($this->mkRelease)($id);
        touch($dir, time() - 100 + $i); // r5 newest, r1 oldest
    }
    // Make an OLD release the active one to prove it is protected.
    $this->adapter->activate($this->root.'/releases/r1', $this->root.'/current');

    $removed = $this->adapter->pruneReleases($this->root, 2);

    expect($removed)->toEqualCanonicalizing(['r3', 'r2'])
        ->and(is_dir($this->root.'/releases/r5'))->toBeTrue()
        ->and(is_dir($this->root.'/releases/r4'))->toBeTrue()
        ->and(is_dir($this->root.'/releases/r1'))->toBeTrue()   // active — kept
        ->and(is_dir($this->root.'/releases/r3'))->toBeFalse()
        ->and(is_dir($this->root.'/releases/r2'))->toBeFalse();
});

it('acquires, blocks, releases, and steals a stale lock', function () {
    expect($this->adapter->acquireLock($this->root))->toBeTrue()
        ->and($this->adapter->acquireLock($this->root))->toBeFalse(); // held

    $this->adapter->releaseLock($this->root);
    expect($this->adapter->acquireLock($this->root))->toBeTrue();

    // Age the lock past the TTL → next acquire steals it.
    touch($this->root.'/deploy.lock', time() - 1000);
    expect($this->adapter->acquireLock($this->root))->toBeTrue();
});

it('refuses to activate a target outside the jail', function () {
    $outside = sys_get_temp_dir().'/cporter_out_'.uniqid();
    File::makeDirectory($outside, 0777, true, true);

    try {
        $this->adapter->activate($outside, $this->root.'/current');
        expect(false)->toBeTrue('expected a jail violation');
    } catch (App\Domain\Storage\PathOutsideJailException) {
        expect(true)->toBeTrue();
    } finally {
        File::deleteDirectory($outside);
    }
});
