<?php

namespace App\Domain\Storage;

use InvalidArgumentException;

/**
 * Confines every filesystem operation to the configured allowed base paths.
 *
 * open_basedir is OFF on the cPanel target (docs/SPEC.md §2.1), so this class — not the OS —
 * is what prevents path traversal and symlink escapes. Every path the storage layer touches
 * MUST pass through assertInside() first (docs/SPEC.md §11, §12).
 */
class PathJail
{
    /** @var list<string> Normalized, symlink-resolved allowed roots. */
    private array $roots;

    /**
     * @param  list<string>  $allowedBasePaths
     */
    public function __construct(array $allowedBasePaths)
    {
        $roots = [];
        foreach ($allowedBasePaths as $path) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }
            $roots[] = $this->resolveReal($this->normalize($path));
        }
        $this->roots = $roots;
    }

    public static function fromConfig(): self
    {
        /** @var list<string> $paths */
        $paths = config('cporter.allowed_base_paths', []);

        return new self($paths);
    }

    /**
     * @return list<string>
     */
    public function roots(): array
    {
        return $this->roots;
    }

    public function isInside(string $path): bool
    {
        try {
            $this->assertInside($path);

            return true;
        } catch (PathOutsideJailException) {
            return false;
        }
    }

    /**
     * Return the resolved absolute path if it lies within a jail root; throw otherwise.
     *
     * @throws PathOutsideJailException
     */
    public function assertInside(string $path): string
    {
        $candidate = $this->resolveReal($this->normalize($path));

        foreach ($this->roots as $root) {
            if ($candidate === $root || str_starts_with($candidate, $root.'/')) {
                return $candidate;
            }
        }

        throw PathOutsideJailException::for($path);
    }

    /**
     * Lexically normalize an absolute path (resolve '.', '..', '//') WITHOUT touching disk,
     * so it works for paths that don't exist yet (e.g. new release dirs). '..' is clamped
     * at the root — it can never escape above '/'.
     */
    public function normalize(string $path): string
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Path contains a null byte.');
        }
        if ($path === '') {
            throw new InvalidArgumentException('Path is empty.');
        }
        if (! str_starts_with($path, '/')) {
            throw new InvalidArgumentException("Path must be absolute: {$path}");
        }

        $out = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($out); // clamp at root

                continue;
            }
            $out[] = $segment;
        }

        return '/'.implode('/', $out);
    }

    /**
     * Resolve symlinks via realpath. For a path that doesn't exist yet, resolve the deepest
     * existing ancestor (catching symlinked parent dirs) and re-append the remaining segments.
     */
    private function resolveReal(string $normalized): string
    {
        $real = realpath($normalized);
        if ($real !== false) {
            return $real;
        }

        $parts = explode('/', ltrim($normalized, '/'));
        $tail = [];
        while ($parts !== []) {
            array_unshift($tail, array_pop($parts));
            $ancestor = '/'.implode('/', $parts);
            $realAncestor = realpath($ancestor === '' ? '/' : $ancestor);
            if ($realAncestor !== false) {
                return rtrim($realAncestor, '/').'/'.implode('/', $tail);
            }
        }

        return $normalized;
    }
}
