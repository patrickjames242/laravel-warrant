<?php

namespace Warrant\LanguageServer\Features;

use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Workspace;

/**
 * The problems in a document's rule text, as the editor underlines them.
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

        return $found;
    }
}
