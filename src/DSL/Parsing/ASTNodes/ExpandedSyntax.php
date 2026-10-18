<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use InvalidArgumentException;
use LogicException;
use Warrant\DSL\Expanding\RuleSetExpander;

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
 * Only {@see of()} makes one, and only from a {@see SyntaxBindingsResolved}, so
 * holding an ExpandedSyntax is proof that its text was validated, then had its
 * bindings' values put in place, then was expanded, in that order. Its one
 * child is a rule set holding {@see $rules}.
 *
 * @internal
 */
final readonly class ExpandedSyntax extends WarrantSyntax
{
    /**
     * @param string $schemaKey The schema the rules are for.
     * @param list<WarrantRuleNode> $rules In source order, each include's rules
     *   where the include stood.
     */
    private function __construct(public string $schemaKey, public array $rules)
    {
        parent::__construct([new RuleSetNode($schemaKey, $rules)]);
    }

    /**
     * $syntax expanded against the schemas its validation input names: the
     * rules with no `for` header against the schema they were validated in, and
     * a `for` body against the schema its header names.
     */
    public static function of(SyntaxBindingsResolved $syntax): self
    {
        $catalog = $syntax->input->catalog ?? throw new InvalidArgumentException(
            'Rules validated with no schema catalog have no schemas to be expanded against.',
        );

        $expanded = (new RuleSetExpander($catalog))->expand($syntax);

        /** @var list<WarrantRuleNode> $rules */
        $rules = $expanded->entries;

        return new self($expanded->schemaKey, $rules);
    }

    /**
     * The rules of $parts, in order, as one expanded syntax for $schemaKey. Every
     * part must be for that schema.
     */
    public static function combine(string $schemaKey, self ...$parts): self
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

            array_push($rules, ...$part->rules);
        }

        return new self($schemaKey, $rules);
    }

    /**
     * An expanded syntax has no bindings left to put in place: its rules are
     * read from {@see $rules}.
     */
    public function resolveBindings(): never
    {
        throw new LogicException('An expanded syntax has no bindings to resolve; read its rules.');
    }
}
