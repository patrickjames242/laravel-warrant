<?php

namespace Warrant\RuleSyntaxTree;


/**
 * Living reference for Warrant rule syntax.
 *
 * Every method below is illustrative and never executed — each demonstrates one
 * facet of the language accepted by WarrantRuleSet::fromSyntax() /
 * WarrantRule::fromSyntax(), or the resolved-rule composition accepted by
 * WarrantRuleSet::fromRules().
 *
 * Core model:
 *  - A rule set compiles DIRECTLY to SQL. There is no in-memory evaluator; even a
 *    single-instance check runs as a scoped SQL query.
 *  - Conditions (is_self, is_manager, is_specific_user, ...) are Warrant conditions
 *    that emit SQL. Conditions may take parameters, e.g. is_specific_user('id').
 *  - `cannot` is deny-overrides: each `cannot` rule contributes
 *    `AND NOT (its if-expression)` to every ability it lists. An unconditional
 *    `they cannot X` compiles to `AND NOT (true)` — X is impossible, full stop,
 *    regardless of any `can` rule. Per ability the compiled predicate is:
 *        ( OR of all can-expressions ) AND ( AND of NOT(each cannot-expression) )
 *  - Whitespace and newlines are INSIGNIFICANT; a whole rule set may sit on one
 *    line. `if` (as a standalone keyword) is the sole rule delimiter.
 *  - Bindings are resolved inline at parse time; the resulting tree holds only
 *    concrete values (no placeholder nodes, no separate resolve phase).
 *  - Malformed syntax throws WarrantSyntaxException eagerly at build time, with
 *    position information (line:col / offset) and a snippet.
 *
 * Lexical rules:
 *  - Identifiers (condition names, ability names, :binding names): must start with
 *    a letter or underscore, then letters / digits / underscores / dashes —
 *    `[A-Za-z_][A-Za-z0-9_-]*`. No dots.
 *  - Reserved words (cannot be used as an EXACT condition or ability name, though a
 *    name may start with or contain them): if, they, can, cannot, because, and, or,
 *    not.
 *  - String literals: single-quoted, with `\'` and `\\` escapes.
 *  - Operators: `and`, `or`, and negation as `not` (canonical) or `!` (synonym).
 *    Precedence, tightest to loosest: `not`/`!` > `and` > `or`. Parentheses override.
 *    (`&&` / `||` are NOT supported.)
 */
class RuleSyntaxExamples
{
    // -------------------------------------------------------------------------
    // 1. Basics
    // -------------------------------------------------------------------------

    /** A single rule: one `if`, a `can` line, and a `cannot` line. */
    public function basicRule(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if is_self
            they can edit, view, delete
            they cannot approve, deny
            DSL, 'timesheets');

        // Compiles per ability, for this rule:
        //   edit / view / delete  ->  is_self
        //   approve / deny        ->  NOT (is_self)
    }

    /** Several rules in one string. Each `if` starts a new rule. */
    public function multipleRules(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if is_self or (not is_manager and is_specific_user('some-user-id'))
            they can edit, view, delete
            they cannot approve, deny

            if has_access_control_level
            they can edit, view, update
            they cannot publish, deny
            DSL, 'timesheets');

        // The same ability may appear in multiple rules; the per-ability formula
        // ORs the `can` expressions and ANDs the negated `cannot` expressions.
    }

    /** No `if` → the rule always applies (compiles to `WHERE true` on the grant side). */
    public function unconditionalRule(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            they can view
            they cannot delete
            DSL, 'timesheets');

        // view   -> true            (always granted)
        // delete -> NOT (true)      (never granted, by anyone)
    }

    /**
     * A `cannot` clause may carry a denial message via `because '<message>'`,
     * surfaced (as a WarrantAuthorizationException) when this rule is the
     * attributable cause of a singular-target denial. `because` is valid only
     * after a `cannot` clause — never after `can`.
     *
     * Each `they cannot ...` clause is its own denial, so different abilities
     * under one `if` can give different reasons — the two clauses below deny
     * `edit` and `delete` with different messages, all on ONE rule.
     */
    public function denialMessage(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            they can view, edit, delete
            if is_locked
            they cannot edit because 'This timesheet is locked and can no longer be edited.'
            they cannot delete because 'Locked timesheets cannot be deleted.'
            DSL, 'timesheets');

        // edit/delete are granted unless is_locked; when a locked row denies one,
        // the denial surfaces that clause's own message instead of the generic 403.
        //
        // The message may also come from a binding rather than an inline literal.
        // A `?`/`:name` binding may resolve to a string, or to a closure of the
        // form fn (WarrantDenialContext $c) => string|Throwable — the same
        // dynamic message form accepted by WarrantRule::withDenialMessage():
        WarrantRule::fromSyntax(
            "if is_locked they cannot edit because :msg",
            bindings: ['msg' => fn (\Warrant\WarrantDenialContext $c) => "You cannot edit {$c->target->getKey()}."],
        );

        // The same rule built fluently — theyCannotBecause() carries the message:
        WarrantRule::build()
            ->if('is_locked')
            ->theyCannotBecause('edit', 'This timesheet is locked and can no longer be edited.')
            ->theyCannotBecause('delete', 'Locked timesheets cannot be deleted.')
            ->toRule();

        // `@context` is NOT accepted after `because` — a message is fixed when the
        // rule is parsed, not resolved per check.
    }

    // -------------------------------------------------------------------------
    // 2. Whitespace is insignificant
    // -------------------------------------------------------------------------

    /** An entire rule set — multiple `if`s, multiple rules — on a single line. */
    public function singleLine(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(
            'if is_self they can edit if is_manager they can approve they cannot delete',
            'timesheets'
        );

        // Two rules:
        //   is_self    -> can edit
        //   is_manager -> can approve, cannot delete
        // (`they cannot delete` attaches to the most recent `if`, i.e. is_manager.)
    }

    // -------------------------------------------------------------------------
    // 3. Boolean expressions
    // -------------------------------------------------------------------------

    /** Operator precedence: `not`/`!` > `and` > `or`; parentheses override. */
    public function booleanPrecedence(): void
    {
        // Parses as: is_self OR ((NOT is_manager) AND is_owner)
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if is_self or not is_manager and is_owner
            they can view
            DSL, 'timesheets');
    }

    /** `!` is an accepted synonym for `not`; parentheses group freely. */
    public function negationSynonymAndGrouping(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if !(is_self or (!is_manager and is_specific_user('some-user-id')))
            they cannot edit
            DSL, 'timesheets');

        // Equivalent, using canonical `not`:
        //   if not (is_self or (not is_manager and is_specific_user('some-user-id')))
    }

    // -------------------------------------------------------------------------
    // 4. Condition parameters — inline literals
    // -------------------------------------------------------------------------

    /**
     * Inline literals may be: string, int, float, bool, null.
     * (Lists / arbitrary values are only available via bindings — see section 5.)
     */
    public function inlineLiterals(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if is_thing('a-string', 42, 3.14, true, null)
            they can view
            DSL, 'timesheets');
    }

    /**
     * The schema side of a parameterised condition.
     *
     * A condition declares its DSL arguments as method parameters. The context
     * object is always the first parameter; every parameter after it receives an
     * argument positionally (parameter #2 -> argument[0], #3 -> argument[1], …).
     * The condition binds every value as a placeholder — never interpolating it
     * into SQL:
     *
     *   use Warrant\RowCondition;
     *   use Warrant\GlobalCondition;
     *   use Warrant\Schema\Conditions\RowConditionContext;
     *   use Warrant\Schema\Conditions\GlobalConditionContext;
     *
     *   // Row: the context exposes the target row's SQL identity; the argument binds a parameter.
     *   #[RowCondition]
     *   public function isSpecificUser(RowConditionContext $c, string $userId): Builder {
     *       // is_specific_user('some-user-id') -> $userId === 'some-user-id'
     *       return $c->query->whereRaw("{$c->row()} = ?", [$userId]);
     *   }
     *
     *   // Variadic parameter -> a list argument -> a whereIn:
     *   #[RowCondition]
     *   public function isDepartment(RowConditionContext $c, string ...$departmentIds): Builder {
     *       // is_department(?, ?, ?) with positional bindings ['a', 'b', 'c']
     *       return $c->query->whereIn($c->row(), $departmentIds);
     *   }
     *
     *   // No-target boolean condition: returns true/false, ignoring the query.
     *   #[GlobalCondition]
     *   public function isSuperUser(GlobalConditionContext $c): bool {
     *       return $c->user->isSuperUser();
     *   }
     *
     * Conditions that ignore arguments simply declare no parameters beyond the
     * context. A parameter with a default value is optional. Passing more arguments
     * than declared parameters is fine — the extras are ignored by the call but stay
     * reachable via the full positional array `$c->arguments`. Passing fewer
     * arguments than a required parameter (one with no default) is a rule-level
     * error, rejected during validation before compilation.
     */
    public function conditionParameterContract(): void
    {
        // Documentation only — see the docblock above.
    }

    // -------------------------------------------------------------------------
    // 5. Condition parameters — bindings
    // -------------------------------------------------------------------------

    /**
     * Named bindings (:name). Only the name matters: order in the array is
     * irrelevant, a name may be reused any number of times, and it may appear
     * anywhere in the string — even across multiple rules. A binding value may be
     * ANY PHP value (scalars, null, lists, objects, ...).
     */
    public function namedBindings(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if not (is_self or (not is_manager and is_specific_user(:specific_user_id, :specific_user_id, :some_list)))
            they cannot edit
            DSL, 'timesheets', [
            'specific_user_id' => 'some-user-id',
            'some_list' => [1, null, false, 'some-string'],
        ]);
    }

    /**
     * Positional bindings (?). Values are matched left-to-right across the ENTIRE
     * string. Positional and named bindings may NOT be mixed in the same call.
     */
    public function positionalBindings(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if is_department(?, ?, ?)
            they can view
            DSL, 'timesheets', [
            'department-id-1',
            'department-id-2',
            'department-id-3',
        ]);
    }

    // -------------------------------------------------------------------------
    // 6. Wildcard abilities
    // -------------------------------------------------------------------------

    /**
     * `*` means "every ability" (expanded against the schema's declared abilities
     * at compile time). Works on both `can` and `cannot`.
     */
    public function wildcards(): void
    {
        $ruleSet = WarrantRuleSet::fromSyntax(<<<'DSL'
            if is_admin
            they can *

            if is_suspended
            they cannot *
            DSL, 'timesheets');

        // is_admin     -> grants every ability
        // is_suspended -> AND NOT (is_suspended) applied to every ability
        //                 (blanket lockout; deny-overrides still wins)
    }

    // -------------------------------------------------------------------------
    // 7. Composing already-resolved rules (fromRules)
    // -------------------------------------------------------------------------

    /**
     * A single WarrantRule can be built on its own (with its own bindings) and later
     * composed into a set. `fromRules` accepts either a variadic list or a single
     * array. It takes NO bindings, and does NOT allow mixing raw syntax with
     * already-resolved rules.
     */
    public function composeResolvedRules(): void
    {
        $cannotPublish = WarrantRule::fromSyntax('they cannot publish');
        $cannotEdit    = WarrantRule::fromSyntax('they cannot edit');
        $canEdit       = WarrantRule::fromSyntax(
            'if some_condition(:some_param) they can edit',
            bindings: ['some_param' => 'some-value']
        );

        // Variadic:
        $ruleSet = WarrantRuleSet::fromRules('timesheets', $cannotPublish, $cannotEdit, $canEdit);

        // Or a single array:
        $ruleSet = WarrantRuleSet::fromRules('timesheets', [$cannotPublish, $cannotEdit, $canEdit]);
    }

    // -------------------------------------------------------------------------
    // 8. Invalid input — each throws WarrantSyntaxException at build time
    // -------------------------------------------------------------------------

    /**
     * The following are all INVALID and throw WarrantSyntaxException (eagerly, with
     * position info) at build time. Shown as comments so this file stays loadable.
     */
    public function invalidExamples(): void
    {
        // Mixing named and positional bindings in one call:
        //   if is_thing(:a, ?) they can view

        // A named placeholder with no matching binding:
        //   fromSyntax("if is_thing(:missing) they can view", [])

        // A binding that is never referenced by any placeholder:
        //   fromSyntax("if is_self they can view", ['unused' => 1])

        // Positional count mismatch (2 placeholders, 3 values — or vice versa):
        //   fromSyntax("if is_thing(?, ?) they can view", [1, 2, 3])

        // A bare `if` with no `can` / `cannot` lines (grants/denies nothing):
        //   if is_self

        // A reserved word used as an EXACT condition or ability name:
        //   they can can            // ability named exactly `can`
        //   if if they can view     // condition named exactly `if`
        //   (Allowed — they merely contain/start with a reserved word:
        //    `canonical`, `cannot_publish`, `ifield`.)

        // fromRules mixing resolved rules with raw syntax, or being handed bindings:
        //   WarrantRuleSet::fromRules('timesheets', $resolvedRule, 'they can view');
    }
}
