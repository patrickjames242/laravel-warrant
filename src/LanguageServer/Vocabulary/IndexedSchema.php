<?php

namespace Warrant\LanguageServer\Vocabulary;

use Warrant\DSL\SchemaVocabulary;
use Warrant\Schema\Abilities\AbilityDefinition;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\RuleTemplates\RuleTemplateDefinition;

/**
 * What the language server knows of one schema, read from its source or from the
 * running application, without the schema class itself being loaded.
 *
 * It answers validation as the schema class would, so rule text is checked
 * against it exactly as against the schema, and it keeps where each name is
 * declared for hover and go-to-definition.
 */
final readonly class IndexedSchema implements SchemaVocabulary
{
    /**
     * @param string $key The key the schema is registered under.
     * @param string $className The schema's class.
     * @param string|null $file Absolute path of the file the class is declared in.
     * @param int|null $line 1-based line of the class declaration.
     * @param string|null $description The summary of the class docblock.
     * @param bool $hasRows Whether the schema has rows, from a model or a virtual
     *   table.
     * @param bool $hasRowKey Whether one of its rows can be named.
     * @param int $keyArgumentCount How many arguments a row-bound handle on the
     *   schema must pass.
     * @param array<string, Declaration> $abilities By name.
     * @param array<string, Declaration> $conditions By key.
     * @param array<string, Declaration> $templates By key.
     * @param list<string> $requiredContextKeys The context keys every check
     *   against the schema must carry.
     */
    public function __construct(
        public string $key,
        public string $className,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $description = null,
        public bool $hasRows = true,
        public bool $hasRowKey = true,
        public int $keyArgumentCount = 1,
        public array $abilities = [],
        public array $conditions = [],
        public array $templates = [],
        public array $requiredContextKeys = [],
    ) {
    }

    /**
     * The interface asks the schema class; an index has no class to ask, and
     * answers through {@see describesRows()} instead, which is what validation reads.
     */
    public static function hasRows(): bool
    {
        return true;
    }

    /**
     * The interface asks the schema class; an index has no class to ask, and
     * answers through {@see canNameARow()} instead, which is what validation reads.
     */
    public static function hasRowKey(): bool
    {
        return true;
    }

    public function describesRows(): bool
    {
        return $this->hasRows;
    }

    public function canNameARow(): bool
    {
        return $this->hasRows && $this->hasRowKey;
    }

    /**
     * @param string $abilityKey An ability's name.
     */
    public function getAbilityDefinition(string $abilityKey): ?AbilityDefinition
    {
        $definition = ($this->abilities[$abilityKey] ?? null)?->definition;

        return $definition instanceof AbilityDefinition ? $definition : null;
    }

    /**
     * @param string $conditionKey A condition's key.
     */
    public function getConditionDefinition(string $conditionKey): ?ConditionDefinition
    {
        $definition = ($this->conditions[$conditionKey] ?? null)?->definition;

        return $definition instanceof ConditionDefinition ? $definition : null;
    }

    /**
     * @param string $templateKey A template's key.
     */
    public function getRuleTemplateDefinition(string $templateKey): ?RuleTemplateDefinition
    {
        $definition = ($this->templates[$templateKey] ?? null)?->definition;

        return $definition instanceof RuleTemplateDefinition ? $definition : null;
    }

    public function getKeyDefinition(): ConditionDefinition
    {
        return new ConditionDefinition('matchKey', 'matchKey', ConditionKind::Row, $this->keyArgumentCount);
    }

    /**
     * Every context key the schema names: those required on every check, and
     * those its abilities require.
     *
     * @return list<string>
     */
    public function contextKeys(): array
    {
        $keys = $this->requiredContextKeys;

        foreach ($this->abilities as $ability) {
            if ($ability->definition instanceof AbilityDefinition) {
                array_push($keys, ...$ability->definition->requiredContext);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * This schema, with the locations and descriptions of $other filling in
     * whatever this one does not know, name by name.
     *
     * @param IndexedSchema $other The same schema, read another way.
     */
    public function filledFrom(self $other): self
    {
        $fill = static function (array $mine, array $theirs): array {
            foreach ($mine as $name => $declaration) {
                if (isset($theirs[$name])) {
                    $mine[$name] = $declaration->filledFrom($theirs[$name]);
                }
            }

            return $mine;
        };

        return new self(
            $this->key,
            $this->className,
            $this->file ?? $other->file,
            $this->line ?? $other->line,
            $this->description ?? $other->description,
            $this->hasRows,
            $this->hasRowKey,
            $this->keyArgumentCount,
            $fill($this->abilities, $other->abilities),
            $fill($this->conditions, $other->conditions),
            $fill($this->templates, $other->templates),
            $this->requiredContextKeys,
        );
    }
}
