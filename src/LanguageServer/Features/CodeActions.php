<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\ClosestNames;
use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Workspace;

/**
 * Quick fixes for the diagnostics the editor shows: for a schema, ability,
 * condition or template name that is not declared, the declared names closest
 * to it.
 */
final class CodeActions
{
    /**
     * The quick fixes for the diagnostics in $context.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{diagnostics?: list<array<string, mixed>>} $context The LSP
     *   `CodeActionContext` the editor sent.
     * @return list<array<string, mixed>> LSP `CodeAction` objects.
     */
    public static function for(Workspace $workspace, Document $document, array $context): array
    {
        $actions = [];

        foreach ($context['diagnostics'] ?? [] as $diagnostic) {
            $code = DiagnosticCode::tryFrom((string) ($diagnostic['code'] ?? ''));

            if (! in_array($code, [DiagnosticCode::UnknownSchema, DiagnosticCode::UnknownAbility, DiagnosticCode::UnknownCondition, DiagnosticCode::UnknownTemplate, DiagnosticCode::UnknownRelation], true)) {
                continue;
            }

            $range = $diagnostic['range'] ?? null;

            if (! is_array($range) || ! isset($range['start'], $range['end'])) {
                continue;
            }

            $start = $document->offsetAt($range['start']);
            $written = substr($document->text, $start, $document->offsetAt($range['end']) - $start);

            foreach (ClosestNames::to($written, self::declaredNames($workspace, $document, $range['start'], $code)) as $index => $name) {
                $actions[] = [
                    'title' => "Change to '{$name}'",
                    'kind' => 'quickfix',
                    'diagnostics' => [$diagnostic],
                    'isPreferred' => $index === 0,
                    'edit' => ['changes' => [$document->uri => [['range' => $range, 'newText' => $name]]]],
                ];
            }
        }

        return $actions;
    }

    /**
     * The names the misspelt one at $position could have meant.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{line?: int, character?: int} $position Where the name starts.
     * @param DiagnosticCode $code What kind of name was not found.
     * @return list<string>
     */
    private static function declaredNames(Workspace $workspace, Document $document, array $position, DiagnosticCode $code): array
    {
        $vocabulary = $workspace->vocabulary();

        if ($code === DiagnosticCode::UnknownSchema) {
            return array_map('strval', array_keys($vocabulary->schemas));
        }

        /* The position is at the name's first byte; one past it is still on the
           name, and clear of whatever ends just before it. */
        $located = Located::at($workspace, $document, ['line' => $position['line'] ?? 0, 'character' => ($position['character'] ?? 0) + 1]);
        $context = $located?->context;

        if ($located === null || $context === null) {
            return [];
        }

        /* A condition or template may be any the text's names are looked up in:
           its own schema's, and the global schemas'. */
        if ($context->slot === SlotKind::Condition || $context->slot === SlotKind::Template) {
            $writtenIn = $located->writtenIn($vocabulary, $context->nameSchemaKey);
            $names = [];

            foreach ($writtenIn === null ? [] : $vocabulary->searchedFrom($writtenIn) as $schema) {
                array_push($names, ...array_keys($context->slot === SlotKind::Condition ? $schema->conditions : $schema->templates));
            }

            return array_values(array_unique(array_map('strval', $names)));
        }

        $schema = $context->nameSchemaKey === null ? null : $vocabulary->schema($context->nameSchemaKey);

        return match (true) {
            $schema !== null && $context->slot === SlotKind::Ability => array_map('strval', array_keys($schema->abilities)),
            $schema !== null && $context->slot === SlotKind::Relation => array_map('strval', array_keys($schema->relations)),
            default => [],
        };
    }
}
