<?php

namespace Warrant\DSL\Formatting;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The files to format, from the paths a command is given: files, directories
 * and glob patterns.
 *
 * Each argument is read in this order:
 *
 *  - a path to a file or directory that exists is that file or directory, taken
 *    as it is written, so a file named `rules[1].warrant` needs no escaping;
 *  - otherwise, one holding `*`, `?`, `[...]` or `{a,b}` is a glob pattern: `*`
 *    and `?` match within a name, `**` matches any number of directories, and
 *    `{a,b}` either alternative;
 *  - anything else matches nothing.
 *
 * A relative argument is read from the base directory. A directory, and the
 * directories a pattern searches, give their `.warrant` and `.php` files and are
 * never searched in `vendor`, `node_modules`, `storage` or a hidden directory. A
 * file named directly is always given when it is a `.warrant` or `.php` file,
 * wherever it is, and is unsupported otherwise.
 */
final class RuleTextFiles
{
    /** The extensions of files that hold rule text. */
    private const EXTENSIONS = ['warrant', 'php'];

    /** Directories never searched. */
    private const SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'storage'];

    /**
     * @param list<string> $files Every file to format, sorted, each once.
     * @param list<string> $unmatched The arguments that matched nothing.
     * @param list<string> $unsupported The files named directly that hold no rule text.
     */
    private function __construct(
        public readonly array $files,
        public readonly array $unmatched,
        public readonly array $unsupported,
    ) {
    }

    /**
     * The files $arguments name, read from $base; all of $base with none.
     *
     * @param list<string> $arguments Files, directories and glob patterns.
     * @param string $base The directory a relative argument is read from.
     */
    public static function matching(array $arguments, string $base): self
    {
        $base = rtrim($base, '/');
        $files = [];
        $unmatched = [];
        $unsupported = [];

        foreach ($arguments === [] ? ['.'] : $arguments as $argument) {
            $path = str_starts_with($argument, '/') ? $argument : $base.'/'.$argument;

            if (is_file($path)) {
                self::holdsRuleText($path) ? $files[] = $path : $unsupported[] = $argument;
            } elseif (is_dir($path)) {
                array_push($files, ...self::filesUnder($path, null));
            } elseif (strpbrk($argument, '*?[{') !== false) {
                $matched = self::globbed($path);
                $matched === [] ? $unmatched[] = $argument : array_push($files, ...$matched);
            } else {
                $unmatched[] = $argument;
            }
        }

        $files = array_values(array_unique(array_map(self::normalized(...), $files)));
        sort($files);

        return new self($files, $unmatched, $unsupported);
    }

    /**
     * $path relative to $base when it is under it, and as it is otherwise.
     */
    public static function relativeTo(string $path, string $base): string
    {
        $base = rtrim($base, '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * The files a glob pattern matches: the directories before its first
     * wildcard are searched, and what lies under them is matched against the
     * rest.
     *
     * @return list<string>
     */
    private static function globbed(string $pattern): array
    {
        $segments = explode('/', $pattern);
        $root = [];

        while ($segments !== [] && strpbrk($segments[0], '*?[{') === false) {
            $root[] = array_shift($segments);
        }

        $directory = implode('/', $root);

        if ($segments === [] || ! is_dir($directory === '' ? '/' : $directory)) {
            return [];
        }

        return self::filesUnder($directory === '' ? '/' : $directory, '#^'.self::regexOf(implode('/', $segments)).'$#');
    }

    /**
     * The `.warrant` and `.php` files under $directory, outside the directories
     * never searched, whose path under $directory matches $regex when one is
     * given.
     *
     * @return list<string>
     */
    private static function filesUnder(string $directory, ?string $regex): array
    {
        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => ! $file->isDir()
                || ! (str_starts_with($file->getFilename(), '.') || in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, true)),
        );
        $files = [];
        $prefix = rtrim($directory, '/').'/';

        foreach (new RecursiveIteratorIterator($directories) as $file) {
            $path = $file->getPathname();

            if (self::holdsRuleText($path) && ($regex === null || preg_match($regex, substr($path, strlen($prefix))) === 1)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * The regular expression a glob pattern stands for, unanchored.
     */
    private static function regexOf(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*' && ($glob[$i + 1] ?? '') === '*') {
                $i++;

                if (($glob[$i + 1] ?? '') === '/') {
                    $i++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($char === '*') {
                $regex .= '[^/]*';
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[' && ($end = strpos($glob, ']', $i + 1)) !== false) {
                $class = substr($glob, $i + 1, $end - $i - 1);
                $regex .= '['.(str_starts_with($class, '!') ? '^'.substr($class, 1) : $class).']';
                $i = $end;
            } elseif ($char === '{' && ($end = strpos($glob, '}', $i + 1)) !== false) {
                $alternatives = explode(',', substr($glob, $i + 1, $end - $i - 1));
                $regex .= '(?:'.implode('|', array_map(self::regexOf(...), $alternatives)).')';
                $i = $end;
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return $regex;
    }

    private static function holdsRuleText(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * $path with its `.` segments and doubled slashes taken out, so one file
     * named two ways is given once.
     */
    private static function normalized(string $path): string
    {
        $path = (string) preg_replace('#/+#', '/', $path);

        while (str_contains($path, '/./')) {
            $path = str_replace('/./', '/', $path);
        }

        return $path;
    }
}
