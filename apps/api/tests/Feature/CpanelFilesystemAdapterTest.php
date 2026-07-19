<?php

use App\Adapters\Storage\CpanelFilesystemAdapter;
use App\Domain\Storage\PathJail;
use App\Domain\Storage\PathOutsideJailException;
use App\Domain\Storage\StorageException;
use Illuminate\Support\Facades\File;

/** @param array<string, string> $entries */
function makeZip(string $path, array $entries): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/cporter_fs_'.uniqid();
    $this->work = sys_get_temp_dir().'/cporter_work_'.uniqid();
    File::makeDirectory($this->root, 0777, true, true);
    File::makeDirectory($this->work, 0777, true, true);

    $this->adapter = new CpanelFilesystemAdapter(new PathJail([$this->root]));
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory($this->work);
    File::deleteDirectory(storage_path('app/artifacts/testproj'));
});

it('stores an uploaded artifact and returns its path', function () {
    $src = $this->work.'/upload.zip';
    makeZip($src, ['index.html' => 'hi']);

    $stored = $this->adapter->putArtifact($src, 'testproj');

    expect($stored)->toEndWith('.zip')
        ->and(is_file($stored))->toBeTrue()
        ->and(is_file($src))->toBeFalse(); // moved, not copied
});

it('rejects a missing artifact source', function () {
    $this->adapter->putArtifact($this->work.'/nope.zip', 'testproj');
})->throws(StorageException::class);

it('rejects an oversized artifact', function () {
    $adapter = new CpanelFilesystemAdapter(new PathJail([$this->root]), maxBytes: 8);
    $src = $this->work.'/big.zip';
    makeZip($src, ['a' => str_repeat('x', 1000)]);

    $adapter->putArtifact($src, 'testproj');
})->throws(StorageException::class);

it('rejects an invalid project slug', function () {
    $src = $this->work.'/u.zip';
    makeZip($src, ['a' => 'b']);

    $this->adapter->putArtifact($src, '../evil');
})->throws(StorageException::class);

it('extracts a zip into a jailed release directory', function () {
    $zip = $this->work.'/release.zip';
    makeZip($zip, ['index.html' => '<h1>hi</h1>', 'assets/app.js' => 'console.log(1)']);
    $dest = $this->root.'/releases/r1';

    $this->adapter->extractZip($zip, $dest);

    expect(file_get_contents($dest.'/index.html'))->toBe('<h1>hi</h1>')
        ->and(is_file($dest.'/assets/app.js'))->toBeTrue();
});

it('refuses to extract to a destination outside the jail', function () {
    $zip = $this->work.'/r.zip';
    makeZip($zip, ['a' => 'b']);

    $this->adapter->extractZip($zip, sys_get_temp_dir().'/cporter_escape_'.uniqid());
})->throws(PathOutsideJailException::class);

it('blocks Zip-Slip entries', function () {
    $zip = $this->work.'/evil.zip';
    makeZip($zip, ['../../evil.txt' => 'pwned', 'ok.txt' => 'fine']);

    $this->adapter->extractZip($zip, $this->root.'/releases/r2');
})->throws(StorageException::class);

it('rejects archives with too many files', function () {
    $adapter = new CpanelFilesystemAdapter(new PathJail([$this->root]), maxFiles: 1);
    $zip = $this->work.'/many.zip';
    makeZip($zip, ['a.txt' => '1', 'b.txt' => '2']);

    $adapter->extractZip($zip, $this->root.'/releases/r3');
})->throws(StorageException::class);

it('rejects archives whose uncompressed size is too large', function () {
    $adapter = new CpanelFilesystemAdapter(new PathJail([$this->root]), maxUncompressedBytes: 4);
    $zip = $this->work.'/bomb.zip';
    makeZip($zip, ['a.txt' => str_repeat('x', 100)]);

    $adapter->extractZip($zip, $this->root.'/releases/r4');
})->throws(StorageException::class);

it('sizes each shared path: dir walked, file stat, missing → 0', function () {
    $base = $this->root.'/proj';
    // A shared directory with two files (12 + 8 = 20 bytes) and a shared config file (5 bytes).
    File::makeDirectory($base.'/shared/storage/logs', 0777, true, true);
    File::put($base.'/shared/storage/app.txt', str_repeat('a', 12));
    File::put($base.'/shared/storage/logs/l.txt', str_repeat('b', 8));
    File::put($base.'/shared/.env', 'ABCDE');

    $sizes = $this->adapter->sharedPathSizes($base, [
        ['path' => 'storage', 'type' => 'dir'],
        ['path' => '.env', 'type' => 'file'],
        ['path' => 'missing', 'type' => 'dir'],
    ]);

    expect($sizes)->toBe([
        'storage' => 20,
        '.env' => 5,
        'missing' => 0,
    ]);
});

it('does not follow symlinks when sizing a shared dir', function () {
    $base = $this->root.'/proj';
    File::makeDirectory($base.'/shared/uploads', 0777, true, true);
    File::put($base.'/shared/uploads/real.bin', str_repeat('x', 10));
    // A symlink inside the shared dir must not be followed (would otherwise inflate the size).
    File::put($this->work.'/outside.bin', str_repeat('y', 999));
    symlink($this->work.'/outside.bin', $base.'/shared/uploads/link.bin');

    $sizes = $this->adapter->sharedPathSizes($base, [['path' => 'uploads', 'type' => 'dir']]);

    expect($sizes['uploads'])->toBe(10);
});

it('returns an empty map when the shared dir does not exist', function () {
    expect($this->adapter->sharedPathSizes($this->root.'/proj', [['path' => 'x', 'type' => 'dir']]))
        ->toBe([]);
});
