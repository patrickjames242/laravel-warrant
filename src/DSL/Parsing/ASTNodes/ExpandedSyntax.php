<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Validation\ValidationInput;

/**
 * Rules after expansion: rules and nothing else, every one naming the abilities
 * it is about, for one schema.
 *
 * Parsing keeps what the author wrote — ability blocks, `@include`s, generic
 * can/cannot clauses — so that rules can be written back out as they were read. Everything
 * downstream of expansion wants the opposite: the compiler folds rules, the
 * reachability analyzer reads them, denial diagnosis blames one. Giving that the
 * type of its own is what lets those readers take it as given rather than check
 * for it, and makes handing them rules nobody expanded a type error instead of a
 * template that silently grants nothing.
 *
 * It is no {@see WarrantSyntax}: its rules come from many blocks of syntax — what
 * a schema's rules() and the rule provider answered with, and every template and
 * derived condition they reached — and each node says which it was written in.
 *
 * Only {@see expand()} makes one, and only from {@see ValidSyntax}es, so
 * holding an ExpandedSyntax is proof that its text was validated, then
 * expanded. Expansion puts the value of every binding in its
 * place, so no {@see BindingNode} is left in its rules.
 *
 * @internal
 */
final readonly class ExpandedSyntax
{
    /**
     * @param string $schemaKey The schema the rules are for.
     * @param list<WarrantRuleNode> $rules In source order, each include's rules
     *   where the include stood.
     * @param ValidationInput $input What the rules were expanded against, with
     *   no producer: an expanded syntax holds rules of many producers, and each
     *   rule's source says its own.
     */
    private function __construct(
        public string $schemaKey,
        public array $rules,
        public ValidationInput $input,
    ) {}

    /**
     * The rules in $syntaxes about $schemaKey, expanded against the schemas
     * $input names, joined in the order given. Rules about any other schema are
     * left out.
     */
    public static function expand(string $schemaKey, ValidationInput $input, ValidSyntax ...$syntaxes): self
    {
        $catalog = $input->catalog ?? throw new InvalidArgumentException(
            'Rules validated with no schema catalog have no schemas to be expanded against.',
        );

        return new self($schemaKey, (new Expander($catalog))->expand($schemaKey, ...$syntaxes), $input);
    }
}
