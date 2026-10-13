<?php

namespace Warrant\LanguageServer\Features;

use Warrant\DSL\Parsing\DiagnosticCode;
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
    /** How many replacements are offered for one name. */
    private const MOST_SUGGESTIONS = 3;

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

            if (! in_array($code, [DiagnosticCode::UnknownSchema, DiagnosticCode::UnknownAbility, DiagnosticCode::UnknownCondition, DiagnosticCode::UnknownTemplate], true)) {
                continue;
            }

            $range = $diagnostic['range'] ?? null;

            if (! is_array($range) || ! isset($range['start'], $range['end'])) {
                continue;
            }

            $start = $document->offsetAt($range['start']);
            $written = substr($document->text, $start, $document->offsetAt($range['end']) - $start);

            foreach (self::closest($written, self::declaredNames($workspace, $document, $range['start'], $code)) as $index => $name) {
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
        $schema = $context?->nameSchemaKey === null ? null : $vocabulary->find($context->nameSchemaKey);

        return match (true) {
            $schema === null => [],
            $context->slot === SlotKind::Ability => array_map('strval', array_keys($schema->abilities)),
            $context->slot === SlotKind::Condition => array_map('strval', array_keys($schema->conditions)),
            $context->slot === SlotKind::Template => array_map('strval', array_keys($schema->templates)),
            default => [],
        };
    }

    /**
     * The names among $candidates close enough to $written to be what was
     * meant, closest first.
     *
     * @param string $written The name as written.
     * @param list<string> $candidates The declared names.
     * @return list<string>
     */
    private static function closest(string $written, array $candidates): array
    {
        $allowed = max(2, intdiv(strlen($written), 3));
        $distances = [];

        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($written), strtolower($candidate));

            if ($distance > 0 && $distance <= $allowed) {
                $distances[$candidate] = $distance;
            }
        }

        asort($distances);

        return array_slice(array_map('strval', array_keys($distances)), 0, self::MOST_SUGGESTIONS);
    }
}
