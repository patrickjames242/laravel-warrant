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
 * generated files, other checkouts of the project, or none of its source.
 */
final class ProjectFiles
{
    /** Directories never looked in, at any depth. */
    private const SKIPPED = ['.git', '.idea', 'node_modules', 'storage', 'vendor'];

    /**
     * The absolute paths of the `.warrant` and PHP files under $root.
     *
     * A git worktree inside the project is another checkout of the same code,
     * so it is left out wherever it is, though $root itself is always searched,
     * since a worktree may be the project an editor has open.
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
            static fn (SplFileInfo $file): bool => ! $file->isDir()
                || (! in_array($file->getFilename(), self::SKIPPED, true) && ! self::isWorktree($file->getPathname())),
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

    /**
     * Whether $directory is the top of a linked git worktree.
     *
     * A worktree's top directory holds a `.git` file, `gitdir: <path>`, naming
     * the directory git keeps the worktree's state in, and that directory always
     * holds a `commondir` file pointing back to the repository the worktree
     * belongs to. A submodule's `.git` file names a repository of its own, which
     * has none, and a clone has a `.git` directory rather than a file. When the
     * named directory is missing, because the repository was moved or deleted,
     * a path under a `worktrees` directory, where git keeps every worktree's
     * state, is read as a worktree's.
     *
     * @param string $directory Absolute path of a directory.
     */
    private static function isWorktree(string $directory): bool
    {
        $marker = $directory.'/.git';

        if (! is_file($marker)) {
            return false;
        }

        $contents = @file_get_contents($marker, length: 4096);

        if ($contents === false || preg_match('/^gitdir:[ \t]*(\S.*?)\s*$/m', $contents, $match) !== 1) {
            return false;
        }

        $gitDirectory = rtrim(str_replace('\\', '/', $match[1]), '/');

        if (! str_starts_with($gitDirectory, '/') && preg_match('/^[A-Za-z]:\//', $gitDirectory) !== 1) {
            $gitDirectory = $directory.'/'.$gitDirectory;
        }

        if (is_dir($gitDirectory)) {
            return is_file($gitDirectory.'/commondir');
        }

        return basename(dirname($gitDirectory)) === 'worktrees';
    }
}
