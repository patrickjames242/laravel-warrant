<?php

namespace Warrant\LanguageServer\Features;

use Warrant\LanguageServer\Documents\Document;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Vocabulary\Declaration;
use Warrant\LanguageServer\Vocabulary\IndexedSchema;
use Warrant\LanguageServer\Workspace;
use Warrant\Schema\Abilities\AbilityDefinition;
use Warrant\Schema\Conditions\ConditionDefinition;

/**
 * What the editor shows on hovering over a name in rule text: what it names, and
 * what its declaration says of it.
 */
final class Hover
{
    /**
     * The hover for $position of $document, or null when it is over no name.
     *
     * @param Workspace $workspace What the server knows.
     * @param Document $document An open document.
     * @param array{line?: int, character?: int} $position An LSP position.
     * @return array{contents: array{kind: string, value: string}, range: array<string, mixed>}|null
     */
    public static function at(Workspace $workspace, Document $document, array $position): ?array
    {
        $located = Located::at($workspace, $document, $position);
        $span = $located?->span();

        if ($located === null || $span === null || $located->context->slot === null || $located->context->name === null) {
            return null;
        }

        $context = $located->context;
        $schema = $context->nameSchemaKey === null ? null : $workspace->vocabulary()->schema($context->nameSchemaKey);
        $markdown = self::describe($context->slot, $context->name, $context->nameSchemaKey, $schema, $workspace);

        return [
            'contents' => ['kind' => 'markdown', 'value' => $markdown],
            'range' => $located->rangeOf($document, $span),
        ];
    }

    /**
     * Markdown describing the name $name of kind $slot.
     *
     * @param SlotKind $slot What kind of name it is.
     * @param string $name The name.
     * @param string|null $schemaKey The schema that declares it.
     * @param IndexedSchema|null $schema That schema, when known.
     * @param Workspace $workspace What the server knows.
     */
    private static function describe(SlotKind $slot, string $name, ?string $schemaKey, ?IndexedSchema $schema, Workspace $workspace): string
    {
        $on = $schemaKey === null ? '' : " on `{$schemaKey}`";

        return match ($slot) {
            SlotKind::Condition => self::declared(
                $schema?->conditions[$name] ?? null,
                "condition `{$name}`{$on}",
                fn (Declaration $declaration): string => self::conditionDetail($declaration),
            ),
            SlotKind::Ability => self::declared(
                $name === '*' ? null : ($schema?->abilities[$name] ?? null),
                $name === '*' ? 'every ability'.$on : "ability `{$name}`{$on}",
                fn (Declaration $declaration): string => self::abilityDetail($declaration),
            ),
            SlotKind::Template => self::declared(
                $schema?->templates[$name] ?? null,
                "rule template `{$name}`{$on}",
                fn (Declaration $declaration): string => sprintf('`@include %s(%s)`', $name, implode(', ', $declaration->parameters)),
            ),
            SlotKind::Schema => self::schemaDetail($name, $workspace->vocabulary()->schema($name)),
            SlotKind::ContextKey => self::contextKeyDetail($name, $schemaKey, $schema),
            SlotKind::Alias => $schemaKey === null
                ? "frame `{$name}`"
                : "frame `{$name}`: rows of `{$schemaKey}`",
            SlotKind::Column => $schemaKey === null
                ? "column `{$name}`"
                : "column `{$name}` of the rows of `{$schemaKey}`",
        };
    }

    /**
     * $title in bold, then $detail of the declaration and its description, or a
     * note that the name is not declared.
     *
     * @param Declaration|null $declaration The declaration, when found.
     * @param string $title What the name is.
     * @param callable(Declaration): string $detail What to say of the declaration.
     */
    private static function declared(?Declaration $declaration, string $title, callable $detail): string
    {
        if ($declaration === null) {
            return "**{$title}**";
        }

        return implode("\n\n", array_filter([
            "**{$title}**",
            $detail($declaration),
            $declaration->description,
        ]));
    }

    /**
     * @param Declaration $declaration A condition.
     */
    private static function conditionDetail(Declaration $declaration): string
    {
        $definition = $declaration->definition;
        $kind = $definition instanceof ConditionDefinition ? strtolower($definition->kind->name).' condition' : 'condition';

        return sprintf('`%s(%s)` · %s', $declaration->name, implode(', ', $declaration->parameters), $kind);
    }

    /**
     * @param Declaration $declaration An ability.
     */
    private static function abilityDetail(Declaration $declaration): string
    {
        $definition = $declaration->definition;
        $context = $definition instanceof AbilityDefinition ? $definition->requiredContext : [];

        return $context === []
            ? ''
            : 'Requires context: '.implode(', ', array_map(static fn (string $key): string => "`{$key}`", $context));
    }

    /**
     * @param string $name The schema key as written.
     * @param IndexedSchema|null $schema The schema, when known.
     */
    private static function schemaDetail(string $name, ?IndexedSchema $schema): string
    {
        if ($schema === null) {
            return "**schema `{$name}`**";
        }

        $rows = match (true) {
            $schema->model !== null => "rows from `{$schema->model}`",
            $schema->hasRows => 'rows from a query',
            default => 'no rows',
        };

        return implode("\n\n", array_filter([
            "**schema `{$name}`** · `{$schema->className}`",
            ucfirst($rows).'.',
            $schema->description,
        ]));
    }

    /**
     * @param string $name The context key.
     * @param string|null $schemaKey The schema whose context it is in.
     * @param IndexedSchema|null $schema That schema, when known.
     */
    private static function contextKeyDetail(string $name, ?string $schemaKey, ?IndexedSchema $schema): string
    {
        $title = $schemaKey === null ? "**context key `{$name}`**" : "**context key `{$name}`** of `{$schemaKey}`";

        return $schema !== null && in_array($name, $schema->requiredContextKeys, true)
            ? $title."\n\nRequired on every check."
            : $title;
    }
}
