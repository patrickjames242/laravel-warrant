<?php

namespace Warrant\LanguageServer\Features;

use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Fragments\FragmentFormatter;

/**
 * Formats the rule text in a document: a whole `.warrant` file, or each piece
 * of rule text in a PHP file, leaving the PHP around it untouched.
 *
 * It answers `textDocument/formatting`, which the server registers for
 * `.warrant` files only, so an editor never offers it as a PHP formatter, and
 * the {@see KIND} code action, which formats the rule text in a PHP file and
 * which an editor can run on save.
 */
final class Formatting
{
    /** The kind of the code action that formats a document's rule text. */
    public const KIND = 'source.format.warrant';

    /**
     * The LSP `TextEdit`s that format $document's rule text.
     *
     * @return list<array{range: array<string, mixed>, newText: string}>
     */
    public static function edits(Document $document): array
    {
        return array_map(
            static fn (array $edit): array => ['range' => $document->rangeOf($edit[0], $edit[1]), 'newText' => $edit[2]],
            (new FragmentFormatter)->edits($document->path, $document->text),
        );
    }

    /**
     * The code action that formats $document's rule text, when the editor asks
     * for that kind of action and there is anything to change.
     *
     * @param array{only?: list<string>} $context The LSP `CodeActionContext`.
     * @return list<array<string, mixed>> LSP `CodeAction` objects.
     */
    public static function codeActions(Document $document, array $context): array
    {
        $asked = array_filter(
            $context['only'] ?? [],
            static fn (mixed $kind): bool => is_string($kind) && ($kind === self::KIND || str_starts_with(self::KIND, $kind.'.')),
        );

        if ($asked === []) {
            return [];
        }

        $edits = self::edits($document);

        return $edits === [] ? [] : [[
            'title' => 'Format Warrant rule text',
            'kind' => self::KIND,
            'edit' => ['changes' => [$document->uri => $edits]],
        ]];
    }
}
