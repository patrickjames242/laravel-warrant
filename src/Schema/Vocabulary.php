<?php

namespace Warrant\Schema;

use Warrant\Schema\Abilities\AbilityDefinition;
use Warrant\Schema\Abilities\IProvidesAbilities;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Rows\IProvidesRows;
use Warrant\Schema\RuleTemplates\RuleTemplateDefinition;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * What a rule may say about one schema, as data: its abilities, conditions and
 * rule templates by name, and how one of its rows is found.
 *
 * Validation and the language server read this, never a live schema. A running
 * application builds it from a schema with {@see of()}; the language server
 * builds it from what it read in the schema's source, without loading the class.
 */
final readonly class Vocabulary
{
    /**
     * @var array<string, AbilityDefinition>
     */
    private array $abilities;

    /**
     * @var array<string, ConditionDefinition>
     */
    private array $conditions;

    /**
     * @var array<string, RuleTemplateDefinition>
     */
    private array $ruleTemplates;

    /**
     * @param list<AbilityDefinition> $abilities
     * @param list<ConditionDefinition> $conditions Every condition, of every kind.
     * @param list<RuleTemplateDefinition> $ruleTemplates
     * @param ConditionDefinition|null $rowMatcher How one row is found, or null for
     *   a schema with no rows.
     */
    public function __construct(
        array $abilities,
        array $conditions,
        array $ruleTemplates,
        private ?ConditionDefinition $rowMatcher,
    ) {
        $this->abilities = array_column(array_map(static fn (AbilityDefinition $a): array => [$a->name, $a], $abilities), 1, 0);
        $this->conditions = array_column(array_map(static fn (ConditionDefinition $c): array => [$c->key, $c], $conditions), 1, 0);
        $this->ruleTemplates = array_column(array_map(static fn (RuleTemplateDefinition $t): array => [$t->key, $t], $ruleTemplates), 1, 0);
    }

    /**
     * Read once from $schema: abilities when it provides them, every kind of
     * condition it provides, its rule templates, and its row matcher when it has
     * rows.
     */
    public static function of(ISchema $schema): self
    {
        return new self(
            $schema instanceof IProvidesAbilities ? $schema->abilities() : [],
            $schema->conditions(),
            $schema->ruleTemplates(),
            $schema instanceof IProvidesRows ? $schema->rowMatcher() : null,
        );
    }

    public function ability(string $name): ?AbilityDefinition
    {
        return $this->abilities[$name] ?? null;
    }

    public function condition(string $key): ?ConditionDefinition
    {
        return $this->conditions[$key] ?? null;
    }

    public function ruleTemplate(string $key): ?RuleTemplateDefinition
    {
        return $this->ruleTemplates[$key] ?? null;
    }

    /**
     * How one of the schema's rows is found from a handle's arguments, or null
     * exactly when the schema has no rows.
     */
    public function rowMatcher(): ?ConditionDefinition
    {
        return $this->rowMatcher;
    }

    public function hasRows(): bool
    {
        return $this->rowMatcher !== null;
    }
}
