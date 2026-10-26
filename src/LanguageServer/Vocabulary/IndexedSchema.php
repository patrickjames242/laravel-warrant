<?php

namespace Warrant\LanguageServer\Vocabulary;

use Warrant\Schema\Abilities\AbilityDefinition;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\RuleTemplates\RuleTemplateDefinition;
use Warrant\Schema\Vocabulary;

/**
 * What the language server knows of one schema, read from its source or from the
 * running application, without the schema class itself being loaded.
 *
 * Its {@see $vocabulary} answers validation as the schema itself would, so rule
 * text is checked against it exactly as against the schema. Beside it, it keeps
 * where each name is declared, for hover and go-to-definition.
 */
final readonly class IndexedSchema
{
    /**
     * What rule text may say about the schema, built from the declarations.
     */
    public Vocabulary $vocabulary;

    /**
     * @param string|null $key The key the schema is registered under; null for a
     *   global schema, which has none.
     * @param string $className The schema's class.
     * @param string|null $file Absolute path of the file the class is declared in.
     * @param int|null $line 1-based line of the class declaration.
     * @param string|null $description The summary of the class docblock.
     * @param bool $hasRows Whether the schema has rows: a model schema's or a
     *   query schema's.
     * @param string|null $model The model a model schema names, or null for any
     *   other schema.
     * @param int $keyArgumentCount How many arguments a row-bound handle on the
     *   schema must pass.
     * @param array<string, Declaration> $abilities By name.
     * @param array<string, Declaration> $conditions By key.
     * @param array<string, Declaration> $templates By key.
     * @param list<string> $requiredContextKeys The context keys every check
     *   against the schema must carry.
     */
    public function __construct(
        public ?string $key,
        public string $className,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $description = null,
        public bool $hasRows = true,
        public ?string $model = null,
        public int $keyArgumentCount = 1,
        public array $abilities = [],
        public array $conditions = [],
        public array $templates = [],
        public array $requiredContextKeys = [],
    ) {
        $definitions = static fn (array $declarations, string $type): array => array_values(array_filter(
            array_map(static fn (Declaration $declaration): mixed => $declaration->definition, $declarations),
            static fn (mixed $definition): bool => $definition instanceof $type,
        ));

        $this->vocabulary = new Vocabulary(
            $className,
            $definitions($abilities, AbilityDefinition::class),
            $definitions($conditions, ConditionDefinition::class),
            $definitions($templates, RuleTemplateDefinition::class),
            $hasRows ? new ConditionDefinition('matchKey', 'matchKey', ConditionKind::Row, $keyArgumentCount) : null,
        );
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
            $this->model,
            $this->keyArgumentCount,
            $fill($this->abilities, $other->abilities),
            $fill($this->conditions, $other->conditions),
            $fill($this->templates, $other->templates),
            $this->requiredContextKeys,
        );
    }
}
