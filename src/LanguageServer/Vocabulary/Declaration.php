<?php

namespace Warrant\LanguageServer\Vocabulary;

use Warrant\Schema\Abilities\AbilityDefinition;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\RuleTemplates\RuleTemplateDefinition;

/**
 * One name a schema declares, with what an editor shows and jumps to for it: the
 * definition rule text is checked against, where it is written, and what its
 * docblock says.
 */
final readonly class Declaration
{
    /**
     * @param string $name The name rule text uses: an ability, condition or
     *   template key.
     * @param AbilityDefinition|ConditionDefinition|RuleTemplateDefinition $definition
     *   What validation reads for the name.
     * @param string|null $file Absolute path of the file it is declared in, when
     *   known.
     * @param int|null $line 1-based line it is declared on, when known.
     * @param string|null $description The summary of its docblock, when it has
     *   one.
     * @param list<string> $parameters The names of the arguments rule text passes
     *   it, in order; empty for an ability.
     */
    public function __construct(
        public string $name,
        public AbilityDefinition|ConditionDefinition|RuleTemplateDefinition $definition,
        public ?string $file = null,
        public ?int $line = null,
        public ?string $description = null,
        public array $parameters = [],
    ) {
    }

    /**
     * This declaration, with the location and description of $other filling in
     * whatever this one does not know.
     *
     * @param Declaration $other A declaration of the same name, read another way.
     */
    public function filledFrom(self $other): self
    {
        return new self(
            $this->name,
            $this->definition,
            $this->file ?? $other->file,
            $this->line ?? $other->line,
            $this->description ?? $other->description,
            $this->parameters !== [] ? $this->parameters : $other->parameters,
        );
    }
}
