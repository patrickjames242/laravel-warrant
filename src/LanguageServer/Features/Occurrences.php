<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\LanguageServer\Documents\AnalyzedFragment;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Workspace;

/**
 * Every place a name that no PHP member declares is written, for an editor
 * finding its usages or renaming it: a context key, or the name a frame is
 * given by `as`.
 *
 * A context key is the same key wherever rule text names it, since a check
 * carries one context and a `with` map hands keys from one check to another, so
 * its occurrences are every one in the project's rule text. A frame name is the
 * `as` that gives it and each `@column` that refers to that frame, all in the
 * one rule.
 */
final class Occurrences
{
    /**
     * The context key or frame name at $position, and every place it is
     * written. Null when the position is on neither, or on a frame named by its
     * schema's key, which no `as` gives.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{line?: int, character?: int} $position An LSP position.
     * @return array{kind: string, name: string, occurrences: list<array{uri: string, range: array<string, mixed>}>}|null
     */
    public static function at(Workspace $workspace, Document $document, array $position): ?array
    {
        $located = Located::at($workspace, $document, $position);
        $name = $located?->context->name;

        if ($located === null || $name === null) {
            return null;
        }

        if ($located->context->slot === SlotKind::ContextKey) {
            return ['kind' => 'context', 'name' => $name, 'occurrences' => self::ofContextKey($workspace, $name)];
        }

        $frame = $located->frameNamedBy();

        if ($frame === null) {
            return null;
        }

        $occurrences = [];

        foreach (self::located($workspace, $located->fragment, $name) as $other) {
            $span = $other->span();

            if ($span !== null && $other->frameNamedBy() === $frame) {
                $occurrences[] = ['uri' => $document->uri, 'range' => $other->rangeOf($document, $span)];
            }
        }

        return ['kind' => 'frame', 'name' => $name, 'occurrences' => $occurrences];
    }

    /**
     * Every place the project's rule text names the context key $name.
     *
     * @param Workspace $workspace What the server knows.
     * @param string $name The key.
     * @return list<array{uri: string, range: array<string, mixed>}>
     */
    public static function ofContextKey(Workspace $workspace, string $name): array
    {
        return $workspace->remember("context:{$name}", static function () use ($workspace, $name): array {
            $occurrences = [];

            foreach ($workspace->projectDocumentsHolding($name) as $document) {
                foreach ($workspace->analyze($document) as $fragment) {
                    foreach (self::located($workspace, $fragment, $name) as $located) {
                        $span = $located->span();

                        if ($span !== null && $located->context->slot === SlotKind::ContextKey) {
                            $occurrences[] = ['uri' => $document->uri, 'range' => $located->rangeOf($document, $span)];
                        }
                    }
                }
            }

            return $occurrences;
        });
    }

    /**
     * Each name node in $fragment that writes $name, located as a cursor on it
     * would be.
     *
     * @return list<Located>
     */
    public static function located(Workspace $workspace, AnalyzedFragment $fragment, string $name): array
    {
        return array_values(array_filter(array_map(
            static fn (NameNode $node): ?Located => $node->location->span === null ? null : new Located(
                $fragment,
                $node->location->span->offset,
                CursorContext::at($fragment->analysis->syntax, $node->location->span->offset, $workspace->catalog(), $fragment->fragment->schemaKey),
            ),
            $fragment->namesWriting($name),
        )));
    }
}
