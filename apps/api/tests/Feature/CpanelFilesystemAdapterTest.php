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
