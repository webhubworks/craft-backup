<?php

namespace webhubworks\backup\services;

use Craft;
use Symfony\Component\Finder\Finder;
use webhubworks\backup\models\BackupConfig;

/**
 * Resolves config-declared paths to a concrete list of files to put into the archive.
 */
class SourceCollector
{
    /**
     * @return iterable<\SplFileInfo>
     */
    public function collect(BackupConfig $config): iterable
    {
        $roots = array_filter(array_map(
            fn (string $path) => Craft::getAlias($path),
            $config->includePaths,
        ), fn ($path) => is_string($path) && $path !== '');

        if ($roots === []) {
            return [];
        }

        $excludePatterns = array_map(
            fn (string $path) => Craft::getAlias($path) ?: $path,
            $config->excludePaths,
        );

        $finder = (new Finder())
            ->files()
            ->followLinks($config->followSymlinks)
            ->ignoreUnreadableDirs(true);

        foreach ($roots as $root) {
            if (is_dir($root)) {
                $finder->in($root);
            } elseif (is_file($root)) {
                $finder->append([new \SplFileInfo($root)]);
            }
        }

        foreach ($excludePatterns as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $finder->notPath($this->toFinderPattern($pattern));
                $finder->notName(basename($pattern));
            }
        }

        return $finder;
    }

    private function toFinderPattern(string $pattern): string
    {
        $root = Craft::getAlias('@root');
        if (is_string($root) && str_starts_with($pattern, $root)) {
            return ltrim(substr($pattern, strlen($root)), '/');
        }
        return $pattern;
    }
}
