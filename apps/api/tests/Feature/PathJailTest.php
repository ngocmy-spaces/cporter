<?php

use App\Domain\Storage\PathJail;
use App\Domain\Storage\PathOutsideJailException;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/cporter_jail_'.uniqid();
    File::makeDirectory($this->root.'/releases', 0777, true, true);
    $this->jail = new PathJail([$this->root]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('allows the root and its descendants', function () {
    expect($this->jail->assertInside($this->root))->toBe(realpath($this->root))
        ->and($this->jail->isInside($this->root.'/releases/20260718_001'))->toBeTrue()
        ->and($this->jail->isInside($this->root.'/./releases//x/../y'))->toBeTrue();
});

it('rejects paths outside the jail', function () {
    expect($this->jail->isInside('/etc/passwd'))->toBeFalse();
});

it('throws for a path outside the jail', function () {
    $this->jail->assertInside('/etc/passwd');
})->throws(PathOutsideJailException::class);

it('blocks ../ traversal escapes', function () {
    expect($this->jail->isInside($this->root.'/releases/../../../../etc/passwd'))->toBeFalse();
});

it('blocks sibling prefix confusion', function () {
    // e.g. root = /x/foo must NOT accept /x/foo-evil
    expect($this->jail->isInside($this->root.'-evil/secret'))->toBeFalse();
});

it('rejects relative and empty paths', function () {
    expect(fn () => $this->jail->normalize('relative/path'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->jail->normalize(''))->toThrow(InvalidArgumentException::class);
});

it('blocks symlink escapes on existing paths', function () {
    $outside = sys_get_temp_dir().'/cporter_outside_'.uniqid();
    File::makeDirectory($outside, 0777, true, true);
    File::put($outside.'/secret', 'x');
    symlink($outside, $this->root.'/evil-link');

    expect($this->jail->isInside($this->root.'/evil-link/secret'))->toBeFalse();

    File::deleteDirectory($outside);
});

it('denies everything when no roots are configured', function () {
    $empty = new PathJail([]);
    expect($empty->isInside('/home/user/anything'))->toBeFalse()
        ->and($empty->roots())->toBe([]);
});
