<?php

namespace webhubworks\backup\services;

use Craft;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use webhubworks\backup\models\BackupConfig;

/**
 * Resolves config-declared paths to a concrete list of files to put into the archive.
 */
class SourceCollector
{
    /**
     * @return iterable<SplFileInfo>
     */
    public function collect(BackupConfig $config): iterable
    {
        $excludes = $this->normalizeExcludes($config->excludePaths);

        foreach ($config->includePaths as $raw) {
            $resolved = Craft::getAlias($raw);
            if (! is_string($resolved) || $resolved === '') {
                continue;
            }

            if (is_file($resolved)) {
                if (! $this->isExcluded($resolved, $excludes)) {
                    yield new SplFileInfo($resolved);
                }
                continue;
            }

            if (! is_dir($resolved)) {
                continue;
            }

            yield from $this->walk($resolved, $excludes, $config->followSymlinks);
        }
    }

    /**
     * @param array{paths: string[], names: string[]} $excludes
     * @return iterable<SplFileInfo>
     */
    private function walk(string $root, array $excludes, bool $followSymlinks): iterable
    {
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO;
        if ($followSymlinks) {
            $flags |= FilesystemIterator::FOLLOW_SYMLINKS;
        }

        $directoryIterator = new RecursiveDirectoryIterator($root, $flags);

        $filtered = new RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (SplFileInfo $file) use ($excludes): bool {
                return ! $this->isExcluded($file->getPathname(), $excludes);
            },
        );

        $iterator = new RecursiveIteratorIterator($filtered, RecursiveIteratorIterator::LEAVES_ONLY);

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }

    /**
     * @param string[] $patterns
     * @return array{paths: string[], names: string[]}
     */
    private function normalizeExcludes(array $patterns): array
    {
        $paths = [];
        $names = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $resolved = Craft::getAlias($pattern);
            $value = is_string($resolved) ? $resolved : $pattern;

            if (str_contains($value, DIRECTORY_SEPARATOR) || str_starts_with($value, '@')) {
                $paths[] = rtrim($value, DIRECTORY_SEPARATOR);
            } else {
                $names[] = $value;
            }
        }

        return ['paths' => $paths, 'names' => $names];
    }

    /**
     * @param array{paths: string[], names: string[]} $excludes
     */
    private function isExcluded(string $fullPath, array $excludes): bool
    {
        foreach ($excludes['paths'] as $excludedPath) {
            if ($fullPath === $excludedPath || str_starts_with($fullPath, $excludedPath . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        $basename = basename($fullPath);
        foreach ($excludes['names'] as $pattern) {
            if (fnmatch($pattern, $basename)) {
                return true;
            }
        }

        return false;
    }
}
