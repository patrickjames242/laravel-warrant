<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Documents\Uri;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Vocabulary\Declaration;
use Warrant\LanguageServer\Workspace;

/**
 * Where the name under the cursor is declared: a schema's class, an ability's
 * constant, a condition's or template's method, or the `as` that names a frame.
 */
final class Definition
{
    /**
     * The location of the declaration of the name at $position, or null when it
     * is on no name or the declaration is not known.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{line?: int, character?: int} $position An LSP position.
     * @return array{uri: string, range: array<string, mixed>}|null An LSP `Location`.
     */
    public static function at(Workspace $workspace, Document $document, array $position): ?array
    {
        $located = Located::at($workspace, $document, $position);
        $context = $located?->context;

        if ($located === null || $context === null || $context->slot === null || $context->name === null) {
            return null;
        }

        $vocabulary = $workspace->vocabulary();
        $schema = $context->nameSchemaKey === null ? null : $vocabulary->schema($context->nameSchemaKey);

        if ($context->slot === SlotKind::Alias) {
            /* A frame name written in a `@column` is declared by the `as` of the
               innermost `check(...)` around it that gives that name; a frame named
               by its schema's key is declared by the schema. */
            foreach (array_reverse($context->nodes) as $node) {
                if ($node instanceof CrossSchemaConditionNode && $node->alias?->name === $context->name) {
                    $span = $node->alias->location->span;

                    if ($span !== null) {
                        return ['uri' => $document->uri, 'range' => $located->rangeOf($document, $span)];
                    }
                }
            }

            $schema = $vocabulary->schema($context->name) ?? $schema;

            return $schema === null ? null : self::location($schema->file, $schema->line);
        }

        if ($context->slot === SlotKind::Schema) {
            $named = $vocabulary->schema($context->name);

            return $named === null ? null : self::location($named->file, $named->line);
        }

        $declaration = match ($context->slot) {
            SlotKind::Condition => $schema?->conditions[$context->name] ?? null,
            SlotKind::Ability => $schema?->abilities[$context->name] ?? null,
            SlotKind::Template => $schema?->templates[$context->name] ?? null,
            default => null,
        };

        return $declaration instanceof Declaration ? self::location($declaration->file, $declaration->line) : null;
    }

    /**
     * The LSP location of line $line of $file, or null when either is unknown.
     *
     * @param string|null $file Absolute path.
     * @param int|null $line 1-based line.
     * @return array{uri: string, range: array<string, mixed>}|null
     */
    private static function location(?string $file, ?int $line): ?array
    {
        if ($file === null || $line === null) {
            return null;
        }

        $position = ['line' => max(0, $line - 1), 'character' => 0];

        return ['uri' => Uri::fromPath($file), 'range' => ['start' => $position, 'end' => $position]];
    }
}
