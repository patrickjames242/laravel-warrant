<?php

/*
 * What the language server's tests share: the fixture project under
 * Fixtures/project, and the server's view of it.
 */

use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Documents\Uri;
use Warrant\LanguageServer\Vocabulary\ClassFileLocator;
use Warrant\LanguageServer\Vocabulary\StaticSchemaScanner;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;
use Warrant\LanguageServer\VocabularyMode;
use Warrant\LanguageServer\Workspace;

function fixtureRoot(): string
{
    return __DIR__.'/Fixtures/project';
}

/**
 * The fixture project's schemas, read from its source.
 */
function fixtureVocabulary(): VocabularyIndex
{
    return (new StaticSchemaScanner(fixtureRoot(), ClassFileLocator::forProject(fixtureRoot())))->scan();
}

/**
 * A workspace over the fixture project, with $text open as the document at
 * $relativePath.
 *
 * @return array{0: Workspace, 1: Document}
 */
function openInFixture(string $relativePath, string $text): array
{
    $workspace = new Workspace(fixtureRoot(), VocabularyMode::Static, fixtureVocabulary());
    $path = fixtureRoot().'/'.$relativePath;
    $document = new Document(Uri::fromPath($path), $path, $text, 1);
    $workspace->open($document);

    return [$workspace, $document];
}

/**
 * The LSP position of the `|` in $text, and $text without it.
 *
 * @return array{0: string, 1: array{line: int, character: int}}
 */
function positionOfBar(string $text): array
{
    $offset = (int) strpos($text, '|');
    $before = substr($text, 0, $offset);
    $line = substr_count($before, "\n");
    $lineStart = strrpos($before, "\n");

    return [
        str_replace('|', '', $text),
        ['line' => $line, 'character' => $offset - ($lineStart === false ? 0 : $lineStart + 1)],
    ];
}
