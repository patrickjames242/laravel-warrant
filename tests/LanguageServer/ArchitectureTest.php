<?php

/*
 * The language server is built on the library and kept apart from it, so it can
 * be lifted into a package of its own. Each file's names are read from its
 * tokens: imports, and names written out in full in the code.
 */

/**
 * Every class name the PHP files under $directory write, by file, leaving out
 * the subdirectories in $except.
 *
 * @param list<string> $except Directory names directly under $directory to skip.
 * @return array<string, list<string>>
 */
function namesWrittenUnder(string $directory, array $except = []): array
{
    $names = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($directory) + 1);

        if ($file->getExtension() !== 'php' || in_array(explode(DIRECTORY_SEPARATOR, $relative)[0], $except, true)) {
            continue;
        }

        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $names[$relative][] = ltrim($token->text, '\\');
            }
        }
    }

    return $names;
}

/**
 * The files among $names that write a name starting with $prefix, with the
 * names they write.
 *
 * @param array<string, list<string>> $names
 * @return array<string, list<string>>
 */
function writingNamesUnder(array $names, string $prefix): array
{
    return array_filter(array_map(
        static fn (array $written): array => array_values(array_unique(array_filter(
            $written,
            static fn (string $name): bool => str_starts_with($name, $prefix),
        ))),
        $names,
    ));
}

it('keeps the library from using the language server', function () {
    $library = namesWrittenUnder(dirname(__DIR__, 2).'/src', except: ['LanguageServer']);

    expect(writingNamesUnder($library, 'Warrant\LanguageServer\\'))->toBe([]);
});

it('keeps the language server from using Laravel', function () {
    $server = namesWrittenUnder(dirname(__DIR__, 2).'/src/LanguageServer');

    expect(writingNamesUnder($server, 'Illuminate\\'))->toBe([]);
});
