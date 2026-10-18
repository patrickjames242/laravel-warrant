<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\Validation\ValidationInput;

/**
 * Rules after expansion: rules and nothing else, every one naming the abilities
 * it is about, for one schema.
 *
 * Parsing keeps what the author wrote — ability blocks, `@include`s, generic
 * clauses — so that rules can be written back out as they were read. Everything
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
 * Only {@see expand()} and {@see combine()} make one, and only from a
 * {@see ValidSyntax}, so holding an ExpandedSyntax is proof that its text was
 * validated, then expanded. Expansion puts the value of every binding in its
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
     * @param ValidationInput $input What the rules were validated under, and
     *   expanded against.
     */
    private function __construct(
        public string $schemaKey,
        public array $rules,
        public ValidationInput $input,
    ) {}

    /**
     * $syntax expanded against the schemas its validation input names: the
     * rules with no `for` header against the schema they were validated in, and
     * a `for` body against the schema its header names.
     */
    public static function expand(ValidSyntax $syntax): self
    {
        $catalog = $syntax->input->catalog ?? throw new InvalidArgumentException(
            'Rules validated with no schema catalog have no schemas to be expanded against.',
        );

        $expanded = (new RuleSetExpander($catalog))->expand($syntax);

        /** @var list<WarrantRuleNode> $rules */
        $rules = $expanded->entries;

        return new self($expanded->schemaKey, $rules, $syntax->input);
    }

    /**
     * The rules of $parts, in order, as one expanded syntax for $schemaKey,
     * validated under $input. Every part must be for that schema and validated
     * under that input; with no parts, there are no rules.
     */
    public static function combine(string $schemaKey, ValidationInput $input, self ...$parts): self
    {
        $rules = [];

        foreach ($parts as $part) {
            if ($part->schemaKey !== $schemaKey) {
                throw new InvalidArgumentException(sprintf(
                    'Expanded rules for [%s] cannot be combined into rules for [%s].',
                    $part->schemaKey,
                    $schemaKey,
                ));
            }

            if ($part->input != $input) {
                throw new InvalidArgumentException(sprintf(
                    'Expanded rules for [%s] validated under another input cannot be combined with these.',
                    $schemaKey,
                ));
            }

            array_push($rules, ...$part->rules);
        }

        return new self($schemaKey, $rules, $input);
    }
}
