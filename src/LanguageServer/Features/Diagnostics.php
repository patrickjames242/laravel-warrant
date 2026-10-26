<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\DiagnosticSeverity;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Workspace;

/**
 * The problems in a document's rule text, as the editor underlines them, and, in
 * a global schema's file, each condition or template it declares that a global
 * listed before it declares too.
 */
final class Diagnostics
{
    /**
     * Every diagnostic of every fragment of $document, placed in the document.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @return list<array<string, mixed>> LSP `Diagnostic` objects.
     */
    public static function of(Workspace $workspace, Document $document): array
    {
        $found = [];

        foreach ($workspace->analyze($document) as $analyzed) {
            $fragment = $analyzed->fragment;

            foreach ($analyzed->diagnostics as $diagnostic) {
                $found[] = [
                    'range' => $document->rangeOf(
                        $fragment->fileOffsetOf($diagnostic->offset),
                        $fragment->fileOffsetOf($diagnostic->endOffset, isEnd: true),
                    ),
                    'severity' => $diagnostic->severity->value,
                    'code' => $diagnostic->code->value,
                    'source' => 'warrant',
                    'message' => $diagnostic->message,
                ];
            }
        }

        return [...$found, ...self::duplicateGlobalNames($workspace, $document)];
    }

    /**
     * A diagnostic on each condition or template declared in $document that a
     * global schema listed earlier declares too, which no lookup could reach.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @return list<array<string, mixed>> LSP `Diagnostic` objects.
     */
    private static function duplicateGlobalNames(Workspace $workspace, Document $document): array
    {
        $catalog = $workspace->catalog();
        $path = realpath($document->path) ?: $document->path;
        $lines = explode("\n", $document->text);
        $found = [];

        foreach ($catalog === null ? [] : $catalog->constructs()->duplicateGlobalNames() as $duplicate) {
            $schema = $catalog->globalSchema($duplicate->declaredAgain);
            $declaration = $duplicate->kind === 'condition'
                ? $schema?->conditions[$duplicate->name] ?? null
                : $schema?->templates[$duplicate->name] ?? null;

            if ($declaration?->file === null || $declaration->line === null || (realpath($declaration->file) ?: $declaration->file) !== $path) {
                continue;
            }

            $line = $declaration->line - 1;
            $text = $lines[$line] ?? '';
            $start = strlen($text) - strlen(ltrim($text));

            $found[] = [
                'range' => ['start' => ['line' => $line, 'character' => $start], 'end' => ['line' => $line, 'character' => strlen(rtrim($text))]],
                'severity' => DiagnosticSeverity::Error->value,
                'code' => DiagnosticCode::DuplicateGlobalName->value,
                'source' => 'warrant',
                'message' => $duplicate->message(),
            ];
        }

        return $found;
    }
}
