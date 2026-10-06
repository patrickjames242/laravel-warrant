<?php

namespace Warrant\DSL\Expanding;

use InvalidArgumentException;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;

/**
 * A rule set after expansion: rules and nothing else, every one naming the
 * abilities it is about.
 *
 * Parsing keeps what the author wrote — ability blocks, `@include`s, generic
 * clauses — so that a rule set can be written back out as it was read. Everything
 * downstream of expansion wants the opposite: the compiler folds rules, the
 * reachability analyzer reads them, denial diagnosis blames one. Giving that the
 * type of its own is what lets those readers take it as given rather than check
 * for it, and makes handing them a rule set nobody expanded a type error instead
 * of a template that silently grants nothing.
 *
 * Built by {@see RuleSetExpander}; the constructor holds the invariant for any
 * other caller.
 */
final readonly class ExpandedRuleSet
{
    /**
     * @param list<WarrantRuleNode> $rules In source order, each include's rules
     *   where the include stood.
     */
    public function __construct(
        public string $schemaKey,
        public array $rules = [],
    ) {
        foreach ($rules as $rule) {
            if (! $rule instanceof WarrantRuleNode) {
                throw new InvalidArgumentException(sprintf(
                    'An expanded rule set holds rules alone, got %s; expand ability blocks and includes first.',
                    get_debug_type($rule),
                ));
            }

            if ($rule->hasGenericClause()) {
                throw new InvalidArgumentException(sprintf(
                    'Every clause in an expanded rule set names the abilities it applies to; the rule set for '
                        .'[%s] holds one that names none.',
                    $schemaKey,
                ));
            }
        }
    }
}
