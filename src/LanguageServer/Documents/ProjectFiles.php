<?php

namespace Warrant\LanguageServer\Documents;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The files of a project that may hold rule text: its `.warrant` and PHP files,
 * outside the directories that hold other people's code, the project's
 * generated files, or none of its source.
 */
final class ProjectFiles
{
    /** Directories never looked in, at any depth. */
    private const SKIPPED = ['.git', '.idea', 'node_modules', 'storage', 'vendor'];

    /**
     * The absolute paths of the `.warrant` and PHP files under $root.
     *
     * @param string $root Absolute path of the project.
     * @return list<string>
     */
    public static function under(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => ! $file->isDir() || ! in_array($file->getFilename(), self::SKIPPED, true),
        );

        $paths = [];

        foreach (new RecursiveIteratorIterator($directories) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && in_array($file->getExtension(), ['php', 'warrant'], true)) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
