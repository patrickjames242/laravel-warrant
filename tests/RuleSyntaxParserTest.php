<?php

use Warrant\RuleSyntaxTree\AndNode;
use Warrant\RuleSyntaxTree\ConditionNode;
use Warrant\RuleSyntaxTree\ContextRef;
use Warrant\RuleSyntaxTree\NotNode;
use Warrant\RuleSyntaxTree\OrNode;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\RuleSyntaxTree\WarrantSyntaxException;

use Warrant\RuleSyntaxTree\Parsing\WarrantParser;

// -- Parser::parse (rules, not a rule set) ------------------------------------

it('parses source and bindings into a flat list of rules', function () {
    $rules = WarrantParser::parse('if is_teacher they can view if is_admin they can edit');

    expect($rules)->toBeArray()->toHaveCount(2);
    expect($rules[0])->toBeInstanceOf(WarrantRule::class);
    expect($rules[0]->conditions->conditionKey)->toBe('is_teacher');
    expect($rules[1]->conditions->conditionKey)->toBe('is_admin');
});

it('resolves bindings through Parser::parse', function () {
    $rules = WarrantParser::parse('if is_owner(:id) they can view', ['id' => 'x-1']);

    expect($rules[0]->conditions->parameters)->toBe(['x-1']);
});

// -- Basics -------------------------------------------------------------------

it('parses a single rule with can and cannot clauses', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', <<<'DSL'
        if is_self
        they can edit, view, delete
        they cannot approve, deny
        DSL);

    expect($set->schemaKey)->toBe('timesheets');
    expect($set->rules)->toHaveCount(1);

    $rule = $set->rules[0];
    expect($rule->conditions)->toBeInstanceOf(ConditionNode::class);
    expect($rule->conditions->conditionKey)->toBe('is_self');
    expect($rule->canAbilities)->toBe(['edit', 'view', 'delete']);
    expect($rule->cannotAbilities)->toBe(['approve', 'deny']);
});

it('parses multiple rules in one string', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', <<<'DSL'
        if is_self
        they can edit

        if has_access_control_level
        they can view
        DSL);

    expect($set->rules)->toHaveCount(2);
    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[1]->conditions->conditionKey)->toBe('has_access_control_level');
});

it('parses an unconditional rule (no if) with null conditions', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', <<<'DSL'
        they can view
        they cannot delete
        DSL);

    expect($set->rules)->toHaveCount(1);
    expect($set->rules[0]->conditions)->toBeNull();
    expect($set->rules[0]->canAbilities)->toBe(['view']);
    expect($set->rules[0]->cannotAbilities)->toBe(['delete']);
});

it('allows an empty rule set', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', '   ');

    expect($set->rules)->toBe([]);
});

// -- Whitespace ---------------------------------------------------------------

it('treats whitespace as insignificant (whole ruleset on one line)', function () {
    $set = WarrantRuleSet::fromSyntax(
        'timesheets',
        'if is_self they can edit if is_manager they can approve they cannot delete'
    );

    expect($set->rules)->toHaveCount(2);
    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[0]->canAbilities)->toBe(['edit']);
    expect($set->rules[1]->conditions->conditionKey)->toBe('is_manager');
    expect($set->rules[1]->canAbilities)->toBe(['approve']);
    expect($set->rules[1]->cannotAbilities)->toBe(['delete']);
});

// -- Boolean expressions ------------------------------------------------------

it('applies precedence not > and > or', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', 'if is_self or not is_manager and is_owner they can view');

    // Expect: Or(is_self, And(Not(is_manager), is_owner))
    $expr = $set->rules[0]->conditions;
    expect($expr)->toBeInstanceOf(OrNode::class);
    expect($expr->leftSide)->toBeInstanceOf(ConditionNode::class);
    expect($expr->leftSide->conditionKey)->toBe('is_self');

    expect($expr->rightSide)->toBeInstanceOf(AndNode::class);
    expect($expr->rightSide->leftSide)->toBeInstanceOf(NotNode::class);
    expect($expr->rightSide->leftSide->operand->conditionKey)->toBe('is_manager');
    expect($expr->rightSide->rightSide->conditionKey)->toBe('is_owner');
});

it('treats ! as a synonym for not', function () {
    $bang = WarrantRuleSet::fromSyntax('timesheets', 'if !is_manager they can view');
    $word = WarrantRuleSet::fromSyntax('timesheets', 'if not is_manager they can view');

    expect($bang->rules[0]->conditions)->toBeInstanceOf(NotNode::class);
    expect($word->rules[0]->conditions)->toBeInstanceOf(NotNode::class);
});

it('honours parentheses over precedence', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', 'if !(is_self or is_manager) they cannot edit');

    $expr = $set->rules[0]->conditions;
    expect($expr)->toBeInstanceOf(NotNode::class);
    expect($expr->operand)->toBeInstanceOf(OrNode::class);
});

// -- Inline literals ----------------------------------------------------------

it('parses inline literals of every supported type', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', "if is_thing('a-string', 42, 3.14, true, null) they can view");

    $params = $set->rules[0]->conditions->parameters;
    expect($params)->toBe(['a-string', 42, 3.14, true, null]);
});

it('unescapes quotes and backslashes in string literals', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', "if is_thing('a\\'b\\\\c') they can view");

    expect($set->rules[0]->conditions->parameters)->toBe(["a'b\\c"]);
});

// -- Bindings -----------------------------------------------------------------

it('resolves named bindings inline, reused and order-independent', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', <<<'DSL'
        if is_specific_user(:user_id, :user_id, :list)
        they cannot edit
        DSL, [
        'list' => [1, null, false, 'x'],
        'user_id' => 'some-user-id',
    ]);

    expect($set->rules[0]->conditions->parameters)->toBe([
        'some-user-id',
        'some-user-id',
        [1, null, false, 'x'],
    ]);
});

it('resolves positional bindings left-to-right across the whole string', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', 'if is_department(?, ?, ?) they can view', [
        'a', 'b', 'c',
    ]);

    expect($set->rules[0]->conditions->parameters)->toBe(['a', 'b', 'c']);
});

it('accepts any value type through a binding', function () {
    $object = new stdClass;
    $set = WarrantRuleSet::fromSyntax('timesheets', 'if is_thing(:v) they can view', ['v' => $object]);

    expect($set->rules[0]->conditions->parameters[0])->toBe($object);
});

// -- Context references (@context) --------------------------------------------

it('parses @context <key> into a symbolic ContextRef, not a value', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', 'if is_teacher(@context academic_year_id) they can view');

    $params = $set->rules[0]->conditions->parameters;
    expect($params)->toHaveCount(1);
    expect($params[0])->toBeInstanceOf(ContextRef::class);
    expect($params[0]->key)->toBe('academic_year_id');
});

it('mixes a context ref with literals and bindings in one condition', function () {
    $set = WarrantRuleSet::fromSyntax(
        'timesheets',
        "if is_teacher('x', @context year, :b) they can view",
        ['b' => 42],
    );

    $params = $set->rules[0]->conditions->parameters;
    expect($params[0])->toBe('x');
    expect($params[1])->toBeInstanceOf(ContextRef::class);
    expect($params[1]->key)->toBe('year');
    expect($params[2])->toBe(42);
});

it('exempts a context ref from binding finalize (no "unused binding" error)', function () {
    // A bare @context ref with an empty bindings array must not trip the
    // all-bindings-used / mixing checks — it is resolved later, at check time.
    $set = WarrantRuleSet::fromSyntax('timesheets', 'if is_teacher(@context year) they can view');

    expect($set->rules[0]->conditions->parameters[0])->toBeInstanceOf(ContextRef::class);
});

it('errors on a bad context sigil or a missing key', function (string $syntax, string $needle) {
    expect(fn () => WarrantRuleSet::fromSyntax('timesheets', $syntax))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'not context'  => ['if is_teacher(@year) they can view', "Expected 'context'"],
    'missing key'  => ['if is_teacher(@context) they can view', 'Expected a context key'],
]);

// -- Wildcards ----------------------------------------------------------------

it('parses wildcard abilities on can and cannot', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', <<<'DSL'
        if is_admin
        they can *

        if is_suspended
        they cannot *
        DSL);

    expect($set->rules[0]->canAbilities)->toBe(['*']);
    expect($set->rules[1]->cannotAbilities)->toBe(['*']);
});

// -- Identifiers --------------------------------------------------------------

it('allows dashes inside condition and ability names', function () {
    $set = WarrantRuleSet::fromSyntax('timesheets', 'if is-department-manager they can soft-delete');

    expect($set->rules[0]->conditions->conditionKey)->toBe('is-department-manager');
    expect($set->rules[0]->canAbilities)->toBe(['soft-delete']);
});

// -- Single-rule factory ------------------------------------------------------

it('parses a single unconditional rule via WarrantRule::fromSyntax', function () {
    $rule = WarrantRule::fromSyntax('they cannot publish');

    expect($rule->conditions)->toBeNull();
    expect($rule->cannotAbilities)->toBe(['publish']);
});

it('parses a single conditional rule with a binding via WarrantRule::fromSyntax', function () {
    $rule = WarrantRule::fromSyntax('if some_condition(:p) they can edit', ['p' => 'v']);

    expect($rule->conditions->parameters)->toBe(['v']);
    expect($rule->canAbilities)->toBe(['edit']);
});

it('rejects multiple rules through WarrantRule::fromSyntax', function () {
    expect(fn () => WarrantRule::fromSyntax('if a they can x if b they can y'))
        ->toThrow(WarrantSyntaxException::class, 'single rule');
});

// -- fromRules ----------------------------------------------------------------

it('composes resolved rules variadically and via a single array', function () {
    $a = WarrantRule::fromSyntax('they cannot publish');
    $b = WarrantRule::fromSyntax('they cannot edit');

    $variadic = WarrantRuleSet::fromRules('timesheets', $a, $b);
    $array = WarrantRuleSet::fromRules('timesheets', [$a, $b]);

    expect($variadic->rules)->toBe([$a, $b]);
    expect($array->rules)->toBe([$a, $b]);
});

it('silently flattens a mix of variadic rules and arrays', function () {
    $a = WarrantRule::fromSyntax('they cannot publish');
    $b = WarrantRule::fromSyntax('they cannot edit');
    $c = WarrantRule::fromSyntax('they cannot view');

    $set = WarrantRuleSet::fromRules('timesheets', $a, [$b, $c]);

    expect($set->rules)->toBe([$a, $b, $c]);
});

it('rejects non-rule elements inside a fromRules array', function () {
    expect(fn () => WarrantRuleSet::fromRules('timesheets', ['not a rule']))
        ->toThrow(InvalidArgumentException::class, 'WarrantRule');
});

// -- Invalid syntax -----------------------------------------------------------

it('throws on invalid syntax', function (string $syntax, array $bindings, string $needle) {
    expect(fn () => WarrantRuleSet::fromSyntax('timesheets', $syntax, $bindings))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'mixed bindings' => ['if is_thing(:a, ?) they can view', ['a' => 1], 'mix named and positional'],
    'missing named binding' => ['if is_thing(:missing) they can view', [], 'No binding provided for ":missing"'],
    'unused binding' => ['if is_self they can view', ['unused' => 1], 'never used'],
    'too many positional placeholders' => ['if is_thing(?, ?) they can view', [1], 'More positional placeholders'],
    'unused positional binding' => ['if is_thing(?) they can view', [1, 2], 'never used'],
    'bare if' => ['if is_self', [], "Expected at least one 'they can"],
    'reserved word as ability' => ['they can can', [], "Reserved word 'can' cannot be used"],
    'reserved word as condition' => ['if if they can view', [], "Reserved word 'if' cannot be used"],
    'unterminated string' => ["if is_thing('oops) they can view", [], 'Unterminated string'],
    'unbalanced parens' => ['if (is_self they can view', [], "Expected ')'"],
    'they without can/cannot' => ['they view', [], "Expected 'can' or 'cannot'"],
    'trailing junk' => ['if is_self they can edit garbage', [], 'end of input'],
]);

it('reports the position of a syntax error', function () {
    try {
        WarrantRuleSet::fromSyntax('timesheets', 'if is_self they can can');
        $this->fail('Expected a WarrantSyntaxException.');
    } catch (WarrantSyntaxException $e) {
        expect($e->sourceLine)->toBe(1);
        expect($e->sourceColumn)->toBe(21); // the second `can` in "if is_self they can can"
        expect($e->getMessage())->toContain('line 1, column 21');
    }
});
