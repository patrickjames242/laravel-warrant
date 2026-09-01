<?php

use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;

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
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        if is_self
        they can edit, view, delete
        they cannot approve, deny
        WARRANT, 'timesheets');

    expect($set->schemaKey)->toBe('timesheets');
    expect($set->rules)->toHaveCount(1);

    $rule = $set->rules[0];
    expect($rule->conditions)->toBeInstanceOf(ConditionNode::class);
    expect($rule->conditions->conditionKey)->toBe('is_self');
    expect($rule->canAbilities)->toBe(['edit', 'view', 'delete']);
    expect($rule->cannotAbilities())->toBe(['approve', 'deny']);
});

it('parses multiple rules in one string', function () {
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        if is_self
        they can edit

        if has_access_control_level
        they can view
        WARRANT, 'timesheets');

    expect($set->rules)->toHaveCount(2);
    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[1]->conditions->conditionKey)->toBe('has_access_control_level');
});

it('parses an unconditional rule (no if) with null conditions', function () {
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        they can view
        they cannot delete
        WARRANT, 'timesheets');

    expect($set->rules)->toHaveCount(1);
    expect($set->rules[0]->conditions)->toBeNull();
    expect($set->rules[0]->canAbilities)->toBe(['view']);
    expect($set->rules[0]->cannotAbilities())->toBe(['delete']);
});

it('allows an empty rule set', function () {
    $set = WarrantRuleSet::fromSyntax('   ', 'timesheets');

    expect($set->rules)->toBe([]);
});

// -- Whitespace ---------------------------------------------------------------

it('treats whitespace as insignificant (whole ruleset on one line)', function () {
    $set = WarrantRuleSet::fromSyntax(
        'if is_self they can edit if is_manager they can approve they cannot delete',
        'timesheets'
    );

    expect($set->rules)->toHaveCount(2);
    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[0]->canAbilities)->toBe(['edit']);
    expect($set->rules[1]->conditions->conditionKey)->toBe('is_manager');
    expect($set->rules[1]->canAbilities)->toBe(['approve']);
    expect($set->rules[1]->cannotAbilities())->toBe(['delete']);
});

// -- Boolean expressions ------------------------------------------------------

it('applies precedence not > and > or', function () {
    $set = WarrantRuleSet::fromSyntax('if is_self or not is_manager and is_owner they can view', 'timesheets');

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
    $bang = WarrantRuleSet::fromSyntax('if !is_manager they can view', 'timesheets');
    $word = WarrantRuleSet::fromSyntax('if not is_manager they can view', 'timesheets');

    expect($bang->rules[0]->conditions)->toBeInstanceOf(NotNode::class);
    expect($word->rules[0]->conditions)->toBeInstanceOf(NotNode::class);
});

it('honours parentheses over precedence', function () {
    $set = WarrantRuleSet::fromSyntax('if !(is_self or is_manager) they cannot edit', 'timesheets');

    $expr = $set->rules[0]->conditions;
    expect($expr)->toBeInstanceOf(NotNode::class);
    expect($expr->operand)->toBeInstanceOf(OrNode::class);
});

// -- Inline literals ----------------------------------------------------------

it('parses inline literals of every supported type', function () {
    $set = WarrantRuleSet::fromSyntax("if is_thing('a-string', 42, 3.14, true, null) they can view", 'timesheets');

    $params = $set->rules[0]->conditions->parameters;
    expect($params)->toBe(['a-string', 42, 3.14, true, null]);
});

it('unescapes quotes and backslashes in string literals', function () {
    $set = WarrantRuleSet::fromSyntax("if is_thing('a\\'b\\\\c') they can view", 'timesheets');

    expect($set->rules[0]->conditions->parameters)->toBe(["a'b\\c"]);
});

it('parses a double-quoted string literal', function () {
    $set = WarrantRuleSet::fromSyntax('if is_thing("a-string") they can view', 'timesheets');

    expect($set->rules[0]->conditions->parameters)->toBe(['a-string']);
});

it('keeps a single quote literal inside a double-quoted string', function () {
    $set = WarrantRuleSet::fromSyntax('if is_thing("can\'t touch this") they can view', 'timesheets');

    expect($set->rules[0]->conditions->parameters)->toBe(["can't touch this"]);
});

it('keeps a double quote literal inside a single-quoted string', function () {
    $set = WarrantRuleSet::fromSyntax('if is_thing(\'she said "hi"\') they can view', 'timesheets');

    expect($set->rules[0]->conditions->parameters)->toBe(['she said "hi"']);
});

it('unescapes quotes and backslashes in double-quoted string literals', function () {
    $set = WarrantRuleSet::fromSyntax('if is_thing("a\\"b\\\\c") they can view', 'timesheets');

    expect($set->rules[0]->conditions->parameters)->toBe(['a"b\\c']);
});

it('allows escaping either quote regardless of the delimiter', function () {
    $single = WarrantRuleSet::fromSyntax("if is_thing('a\\\"b') they can view", 'timesheets');
    $double = WarrantRuleSet::fromSyntax('if is_thing("a\\\'b") they can view', 'timesheets');

    expect($single->rules[0]->conditions->parameters)->toBe(['a"b']);
    expect($double->rules[0]->conditions->parameters)->toBe(["a'b"]);
});

// -- Bindings -----------------------------------------------------------------

it('resolves named bindings inline, reused and order-independent', function () {
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        if is_specific_user(:user_id, :user_id, :list)
        they cannot edit
        WARRANT, 'timesheets', [
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
    $set = WarrantRuleSet::fromSyntax('if is_department(?, ?, ?) they can view', 'timesheets', [
        'a', 'b', 'c',
    ]);

    expect($set->rules[0]->conditions->parameters)->toBe(['a', 'b', 'c']);
});

it('accepts any value type through a binding', function () {
    $object = new stdClass;
    $set = WarrantRuleSet::fromSyntax('if is_thing(:v) they can view', 'timesheets', ['v' => $object]);

    expect($set->rules[0]->conditions->parameters[0])->toBe($object);
});

// -- Context references (@context) --------------------------------------------

it('parses @context <key> into a symbolic ContextRef, not a value', function () {
    $set = WarrantRuleSet::fromSyntax('if is_teacher(@context academic_year_id) they can view', 'timesheets');

    $params = $set->rules[0]->conditions->parameters;
    expect($params)->toHaveCount(1);
    expect($params[0])->toBeInstanceOf(ContextRef::class);
    expect($params[0]->key)->toBe('academic_year_id');
});

it('mixes a context ref with literals and bindings in one condition', function () {
    $set = WarrantRuleSet::fromSyntax(
        "if is_teacher('x', @context year, :b) they can view",
        'timesheets',
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
    $set = WarrantRuleSet::fromSyntax('if is_teacher(@context year) they can view', 'timesheets');

    expect($set->rules[0]->conditions->parameters[0])->toBeInstanceOf(ContextRef::class);
});

it('errors on a bad context sigil or a missing key', function (string $syntax, string $needle) {
    expect(fn () => WarrantRuleSet::fromSyntax($syntax, 'timesheets'))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'not context'  => ['if is_teacher(@year) they can view', "Expected 'context'"],
    'missing key'  => ['if is_teacher(@context) they can view', 'Expected a context key'],
]);

// -- Column references (@column) ----------------------------------------------

it('parses @column <schema>.<column> into a symbolic ColumnRef, not a value', function () {
    $set = WarrantRuleSet::fromSyntax('if is_teacher(@column timesheets.pay_period_id) they can view', 'timesheets');

    $params = $set->rules[0]->conditions->parameters;
    expect($params)->toHaveCount(1);
    expect($params[0])->toBeInstanceOf(ColumnRef::class);
    expect($params[0]->schemaKey)->toBe('timesheets');
    expect($params[0]->column)->toBe('pay_period_id');
});

it('mixes a column ref with literals, context refs, and bindings in one condition', function () {
    $set = WarrantRuleSet::fromSyntax(
        "if is_teacher('x', @column timesheets.id, @context year, :b) they can view",
        'timesheets',
        ['b' => 42],
    );

    $params = $set->rules[0]->conditions->parameters;
    expect($params[0])->toBe('x');
    expect($params[1])->toEqual(new ColumnRef('timesheets', 'id'));
    expect($params[2])->toEqual(new ContextRef('year'));
    expect($params[3])->toBe(42);
});

it('exempts a column ref from binding finalize (no "unused binding" error)', function () {
    // Like @context, a bare @column ref with an empty bindings array must not trip
    // the all-bindings-used / mixing checks — it is resolved later, at compile time.
    $set = WarrantRuleSet::fromSyntax('if is_teacher(@column timesheets.id) they can view', 'timesheets');

    expect($set->rules[0]->conditions->parameters[0])->toBeInstanceOf(ColumnRef::class);
});

it('parses a @column row selector in a can(...) handle', function () {
    $rules = WarrantParser::parse('if can(manage for departments(@column timesheets.department_id)) they can update');

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->boundRow)->toEqual(new ColumnRef('timesheets', 'department_id'));
});

it('parses a @column row selector in a check(...) handle', function () {
    $rules = WarrantParser::parse(
        'if check(is_open for pay_periods(@column timesheets.pay_period_id)) they can update'
    );

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->boundRow)->toEqual(new ColumnRef('timesheets', 'pay_period_id'));
});

it('parses a @column value in a with-map', function () {
    $rules = WarrantParser::parse(
        'if can(create for billing_plans with plan_id = @column timesheets.plan_id) they can create'
    );

    expect($rules[0]->conditions->contextMap)->toEqual(['plan_id' => new ColumnRef('timesheets', 'plan_id')]);
});

it('errors on a bad @-sigil or a malformed @column reference', function (string $syntax, string $needle) {
    expect(fn () => WarrantRuleSet::fromSyntax($syntax, 'timesheets'))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'bad sigil'    => ['if is_teacher(@col) they can view', "Expected 'context', 'column', or 'sql'"],
    'missing dot'  => ['if is_teacher(@column timesheets) they can view', "Expected '.'"],
    'missing col'  => ['if is_teacher(@column timesheets.) they can view', 'Expected a column name'],
    'missing all'  => ['if is_teacher(@column) they can view', "Expected a schema key after '@column'"],
]);

// -- SQL references (@sql) ----------------------------------------------------

it('parses @sql "<sql>" into a symbolic SqlRef, not a value', function () {
    $set = WarrantRuleSet::fromSyntax('if is_teacher(@sql "select 1") they can view', 'timesheets');

    $params = $set->rules[0]->conditions->parameters;
    expect($params)->toHaveCount(1);
    expect($params[0])->toBeInstanceOf(SqlRef::class);
    expect($params[0]->sql)->toBe('select 1');
});

it('accepts single- or double-quoted @sql bodies, keeping the inner quote', function () {
    $double = WarrantRuleSet::fromSyntax('if is_teacher(@sql "id = \'blah\'") they can view', 'timesheets');
    $single = WarrantRuleSet::fromSyntax("if is_teacher(@sql 'name = \"x\"') they can view", 'timesheets');

    expect($double->rules[0]->conditions->parameters[0])->toEqual(new SqlRef("id = 'blah'"));
    expect($single->rules[0]->conditions->parameters[0])->toEqual(new SqlRef('name = "x"'));
});

it('resolves a :name binding as the @sql body at parse time', function () {
    $set = WarrantRuleSet::fromSyntax(
        'if is_teacher(@sql :q) they can view',
        'timesheets',
        ['q' => 'select 1'],
    );

    expect($set->rules[0]->conditions->parameters[0])->toEqual(new SqlRef('select 1'));
});

it('resolves a ? binding as the @sql body at parse time', function () {
    $set = WarrantRuleSet::fromSyntax(
        'if is_teacher(@sql ?) they can view',
        'timesheets',
        ['select 2'],
    );

    expect($set->rules[0]->conditions->parameters[0])->toEqual(new SqlRef('select 2'));
});

it('rejects a @sql binding that resolves to a non-string', function () {
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if is_teacher(@sql :q) they can view',
        'timesheets',
        ['q' => 42],
    ))->toThrow(WarrantSyntaxException::class, 'must resolve to a string');
});

it('mixes a @sql ref with literals, column/context refs, and bindings in one condition', function () {
    $set = WarrantRuleSet::fromSyntax(
        "if is_teacher('x', @sql \"select 1\", @column timesheets.id, @context year, :b) they can view",
        'timesheets',
        ['b' => 42],
    );

    $params = $set->rules[0]->conditions->parameters;
    expect($params[0])->toBe('x');
    expect($params[1])->toEqual(new SqlRef('select 1'));
    expect($params[2])->toEqual(new ColumnRef('timesheets', 'id'));
    expect($params[3])->toEqual(new ContextRef('year'));
    expect($params[4])->toBe(42);
});

it('exempts a @sql ref from binding finalize (no "unused binding" error)', function () {
    // Like @context / @column, a bare @sql ref must not trip the all-bindings-used /
    // mixing checks — it carries no value at parse time and never consumes a `?`.
    $set = WarrantRuleSet::fromSyntax('if is_teacher(@sql "select 1") they can view', 'timesheets');

    expect($set->rules[0]->conditions->parameters[0])->toBeInstanceOf(SqlRef::class);
});

it('parses a @sql row selector in a can(...) handle', function () {
    $rules = WarrantParser::parse('if can(manage for departments(@sql "select id from d")) they can update');

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->boundRow)->toEqual(new SqlRef('select id from d'));
});

it('parses a @sql row selector in a check(...) handle', function () {
    $rules = WarrantParser::parse(
        'if check(is_open for pay_periods(@sql "select id from p")) they can update'
    );

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->boundRow)->toEqual(new SqlRef('select id from p'));
});

it('parses a @sql value in a with-map', function () {
    $rules = WarrantParser::parse(
        'if can(create for billing_plans with plan_id = @sql "select id from plans") they can create'
    );

    expect($rules[0]->conditions->contextMap)->toEqual(['plan_id' => new SqlRef('select id from plans')]);
});

it('errors when @sql is not followed by a quoted string', function (string $syntax, string $needle) {
    expect(fn () => WarrantRuleSet::fromSyntax($syntax, 'timesheets'))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'missing string' => ['if is_teacher(@sql) they can view', 'Expected a quoted SQL string'],
    'identifier not string' => ['if is_teacher(@sql foo) they can view', 'Expected a quoted SQL string'],
]);

// -- Wildcards ----------------------------------------------------------------

it('parses wildcard abilities on can and cannot', function () {
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        if is_admin
        they can *

        if is_suspended
        they cannot *
        WARRANT, 'timesheets');

    expect($set->rules[0]->canAbilities)->toBe(['*']);
    expect($set->rules[1]->cannotAbilities())->toBe(['*']);
});

// -- Identifiers --------------------------------------------------------------

it('allows dashes inside condition and ability names', function () {
    $set = WarrantRuleSet::fromSyntax('if is-department-manager they can soft-delete', 'timesheets');

    expect($set->rules[0]->conditions->conditionKey)->toBe('is-department-manager');
    expect($set->rules[0]->canAbilities)->toBe(['soft-delete']);
});

// -- Single-rule factory ------------------------------------------------------

it('parses a single unconditional rule via WarrantRule::fromSyntax', function () {
    $rule = WarrantRule::fromSyntax('they cannot publish');

    expect($rule->conditions)->toBeNull();
    expect($rule->cannotAbilities())->toBe(['publish']);
});

it('parses a single conditional rule with a binding via WarrantRule::fromSyntax', function () {
    $rule = WarrantRule::fromSyntax('if some_condition(:p) they can edit', bindings: ['p' => 'v']);

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

// -- Denial messages (because) ------------------------------------------------

it('parses a string denial message after a cannot clause', function () {
    $rule = WarrantRule::fromSyntax(<<<'WARRANT'
        if is_locked
        they cannot edit because 'This row is locked.'
        WARRANT);

    expect($rule->cannotAbilities())->toBe(['edit']);
    expect($rule->messageFor('edit'))->toBe('This row is locked.');
});

it('unescapes quotes in a denial message', function () {
    $rule = WarrantRule::fromSyntax("they cannot edit because 'can\\'t touch this'");

    expect($rule->messageFor('edit'))->toBe("can't touch this");
});

it('leaves the message null when no because clause is present', function () {
    $rule = WarrantRule::fromSyntax('they cannot edit');

    expect($rule->messageFor('edit'))->toBeNull();
});

it('resolves a named binding message to a string', function () {
    $rule = WarrantRule::fromSyntax('they cannot edit because :msg', bindings: ['msg' => 'locked']);

    expect($rule->messageFor('edit'))->toBe('locked');
});

it('resolves a positional binding message to a string', function () {
    $rule = WarrantRule::fromSyntax('they cannot edit because ?', bindings: ['locked']);

    expect($rule->messageFor('edit'))->toBe('locked');
});

it('accepts a closure resolved from a binding as the message', function () {
    $closure = fn () => 'dynamic';
    $rule = WarrantRule::fromSyntax('they cannot edit because :msg', bindings: ['msg' => $closure]);

    expect($rule->messageFor('edit'))->toBe($closure);
});

it('keeps a can clause and a message-bearing cannot in one rule', function () {
    $rule = WarrantRule::fromSyntax(<<<'WARRANT'
        they can view
        they cannot edit because 'locked'
        WARRANT);

    expect($rule->canAbilities)->toBe(['view']);
    expect($rule->cannotAbilities())->toBe(['edit']);
    expect($rule->messageFor('edit'))->toBe('locked');
});

it('gives each cannot clause its own message on a single rule', function () {
    $rule = WarrantRule::fromSyntax(
        "if is_locked they cannot update because 'no update' they cannot delete because 'no delete'",
    );

    expect($rule->cannotAbilities())->toBe(['update', 'delete']);
    expect($rule->cannotClauses)->toHaveCount(2);
    expect($rule->messageFor('update'))->toBe('no update');
    expect($rule->messageFor('delete'))->toBe('no delete');
});

it('mixes message-less and message-bearing cannot clauses on one rule', function () {
    $rule = WarrantRule::fromSyntax("they cannot archive they cannot edit because 'locked'");

    expect($rule->cannotAbilities())->toBe(['archive', 'edit']);
    expect($rule->messageFor('archive'))->toBeNull();
    expect($rule->messageFor('edit'))->toBe('locked');
});

it('groups multiple abilities in one cannot clause under one message', function () {
    $rule = WarrantRule::fromSyntax("they cannot update, delete because 'locked'");

    expect($rule->cannotClauses)->toHaveCount(1);
    expect($rule->cannotClauses[0]->abilities)->toBe(['update', 'delete']);
    expect($rule->messageFor('update'))->toBe('locked');
    expect($rule->messageFor('delete'))->toBe('locked');
});

it('rejects because after a can clause', function () {
    expect(fn () => WarrantRule::fromSyntax("they can view because 'nope'"))
        ->toThrow(WarrantSyntaxException::class, "'because' may only follow a 'they cannot");
});

it('rejects a non-string literal after because', function () {
    expect(fn () => WarrantRule::fromSyntax('they cannot edit because 42'))
        ->toThrow(WarrantSyntaxException::class, "Expected a denial message after 'because'");
});

it('rejects @context after because', function () {
    expect(fn () => WarrantRule::fromSyntax('they cannot edit because @context reason'))
        ->toThrow(WarrantSyntaxException::class, '@context is not allowed');
});

it('rejects a binding that resolves to a non-string, non-closure message', function () {
    expect(fn () => WarrantRule::fromSyntax('they cannot edit because :msg', bindings: ['msg' => 42]))
        ->toThrow(WarrantSyntaxException::class, 'must be a string or a closure');
});

it('rejects a bare because with no message', function () {
    expect(fn () => WarrantRule::fromSyntax('they cannot edit because'))
        ->toThrow(WarrantSyntaxException::class, "Expected a denial message after 'because'");
});

// -- Invalid syntax -----------------------------------------------------------

it('throws on invalid syntax', function (string $syntax, array $bindings, string $needle) {
    expect(fn () => WarrantRuleSet::fromSyntax($syntax, 'timesheets', $bindings))
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
    'because reserved as ability' => ['they can because', [], "Reserved word 'because' cannot be used"],
    'unterminated string' => ["if is_thing('oops) they can view", [], 'Unterminated string'],
    'unterminated double-quoted string' => ['if is_thing("oops) they can view', [], 'Unterminated string'],
    'invalid escape sequence' => ["if is_thing('a\\nb') they can view", [], 'Invalid escape sequence'],
    'unbalanced parens' => ['if (is_self they can view', [], "Expected ')'"],
    'they without can/cannot' => ['they view', [], "Expected 'can' or 'cannot'"],
    'trailing junk' => ['if is_self they can edit garbage', [], 'end of input'],
]);

it('reports the position of a syntax error', function () {
    try {
        WarrantRuleSet::fromSyntax('if is_self they can can', 'timesheets');
        $this->fail('Expected a WarrantSyntaxException.');
    } catch (WarrantSyntaxException $e) {
        expect($e->sourceLine)->toBe(1);
        expect($e->sourceColumn)->toBe(21); // the second `can` in "if is_self they can can"
        expect($e->getMessage())->toContain('line 1, column 21');
    }
});

// -- Comments -----------------------------------------------------------------

it('ignores a full-line comment', function () {
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        # only the owner may touch their own timesheet
        if is_self
        they can edit
        WARRANT, 'timesheets');

    expect($set->rules)->toHaveCount(1);
    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[0]->canAbilities)->toBe(['edit']);
});

it('ignores a trailing comment on a line', function () {
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        if is_self   # the author
        they can edit   # but see the cannot below
        they cannot approve
        WARRANT, 'timesheets');

    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[0]->canAbilities)->toBe(['edit']);
    expect($set->rules[0]->cannotAbilities())->toBe(['approve']);
});

it('ignores a comment with no trailing newline at end of source', function () {
    $rules = WarrantParser::parse('if is_self they can edit # trailing');

    expect($rules)->toHaveCount(1);
    expect($rules[0]->canAbilities)->toBe(['edit']);
});

it('does not let a comment merge the tokens on either side of it', function () {
    $rules = WarrantParser::parse("if is_self they can # comment\n edit");

    expect($rules[0]->canAbilities)->toBe(['edit']);
});

it('keeps a "#" inside a string literal as a literal character', function () {
    $rules = WarrantParser::parse("if is_thing('a#b') they can view");

    expect($rules[0]->conditions->parameters)->toBe(['a#b']);
});

// -- Cross-schema can(...) -----------------------------------------------------

it('parses an unbound can(...) handle (capability schema, no row)', function () {
    $rules = WarrantParser::parse('if can(access_payroll for payroll_admin) they can view');

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->schemaKey)->toBe('payroll_admin');
    expect($node->ability)->toBe('access_payroll');
    expect($node->isRowBound)->toBeFalse();
    expect($node->boundRow)->toBeNull();
    expect($node->contextMap)->toBe([]);
    expect($rules[0]->canAbilities)->toBe(['view']);
});

it('parses a row-bound can(...) handle with a @context row selector', function () {
    $rules = WarrantParser::parse('if can(manage for departments(@context department_id)) they can update');

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->schemaKey)->toBe('departments');
    expect($node->ability)->toBe('manage');
    expect($node->isRowBound)->toBeTrue();
    expect($node->boundRow)->toEqual(new ContextRef('department_id'));
});

it('parses a can(...) with a with-map of @context values', function () {
    $rules = WarrantParser::parse(
        'if can(create for billing_plans with as_of_date = @context d, plan_id = @context p) they can create'
    );

    $node = $rules[0]->conditions;
    expect($node->schemaKey)->toBe('billing_plans');
    expect($node->ability)->toBe('create');
    expect($node->isRowBound)->toBeFalse();
    expect($node->contextMap)->toEqual([
        'as_of_date' => new ContextRef('d'),
        'plan_id' => new ContextRef('p'),
    ]);
});

it('composes a can(...) leaf with a local condition via and', function () {
    $rules = WarrantParser::parse('if is_self and can(x for users(@context user_id)) they can create');

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(AndNode::class);
    expect($node->leftSide)->toBeInstanceOf(ConditionNode::class);
    expect($node->leftSide->conditionKey)->toBe('is_self');
    expect($node->rightSide)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->rightSide->schemaKey)->toBe('users');
});

it('resolves a named binding as the row selector', function () {
    $rules = WarrantParser::parse(
        'if can(manage for departments(:dept)) they can update',
        ['dept' => 'dept-1'],
    );

    $node = $rules[0]->conditions;
    expect($node->isRowBound)->toBeTrue();
    expect($node->boundRow)->toBe('dept-1');
});

it('resolves positional bindings across the row selector and with-map', function () {
    $rules = WarrantParser::parse(
        'if can(manage for departments(?) with tenant = ?) they can update',
        ['dept-1', 'tenant-9'],
    );

    $node = $rules[0]->conditions;
    expect($node->boundRow)->toBe('dept-1');
    expect($node->contextMap)->toBe(['tenant' => 'tenant-9']);
});

it('keeps a null literal row selector distinct from an unbound handle', function () {
    $rules = WarrantParser::parse('if can(manage for departments(null)) they can update');

    $node = $rules[0]->conditions;
    expect($node->isRowBound)->toBeTrue();
    expect($node->boundRow)->toBeNull();
});

it('throws when the ability is not followed by for', function () {
    expect(fn () => WarrantParser::parse('if can(manage departments) they can view'))
        ->toThrow(WarrantSyntaxException::class);
});

it('rejects a reserved word (for) used as a bare condition name', function () {
    expect(fn () => WarrantParser::parse('if for they can view'))
        ->toThrow(WarrantSyntaxException::class);
});

it('rejects a duplicate key in a with-map', function () {
    expect(fn () => WarrantParser::parse('if can(create for s with a = 1, a = 2) they can view'))
        ->toThrow(WarrantSyntaxException::class, "Duplicate key 'a'");
});

// -- Cross-schema check(...) ---------------------------------------------------

it('parses an unbound check(...) handle with a global condition', function () {
    $rules = WarrantParser::parse("if check(is_open('maintenance') for tenant_settings) they cannot update");

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->schemaKey)->toBe('tenant_settings');
    expect($node->isRowBound)->toBeFalse();
    expect($node->boundRow)->toBeNull();
    expect($node->contextMap)->toBe([]);
    expect($node->predicate)->toBeInstanceOf(ConditionNode::class);
    expect($node->predicate->conditionKey)->toBe('is_open');
    expect($node->predicate->parameters)->toBe(['maintenance']);
    expect($rules[0]->cannotAbilities())->toBe(['update']);
});

it('parses a row-bound check(...) handle with a @context row selector', function () {
    $rules = WarrantParser::parse(
        'if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context id)) they cannot update'
    );

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->schemaKey)->toBe('pay_periods');
    expect($node->isRowBound)->toBeTrue();
    expect($node->boundRow)->toEqual(new ContextRef('id'));
    expect($node->predicate->conditionKey)->toBe('is_payroll_published_for_user');
    expect($node->predicate->parameters)->toEqual([new ContextRef('user_id')]);
});

it('parses a complex boolean predicate of conditions inside check(...)', function () {
    $rules = WarrantParser::parse(
        'if check(is_published or (needs_review and not is_locked) for pay_periods(@context id)) they can approve'
    );

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->schemaKey)->toBe('pay_periods');

    // is_published OR (needs_review AND NOT is_locked)
    $predicate = $node->predicate;
    expect($predicate)->toBeInstanceOf(OrNode::class);
    expect($predicate->leftSide)->toBeInstanceOf(ConditionNode::class);
    expect($predicate->leftSide->conditionKey)->toBe('is_published');
    expect($predicate->rightSide)->toBeInstanceOf(AndNode::class);
    expect($predicate->rightSide->leftSide->conditionKey)->toBe('needs_review');
    expect($predicate->rightSide->rightSide)->toBeInstanceOf(NotNode::class);
    expect($predicate->rightSide->rightSide->operand->conditionKey)->toBe('is_locked');
});

it('parses a check(...) with a with-map of @context values', function () {
    $rules = WarrantParser::parse(
        'if check(is_published(@context user_id) for pay_periods(@context id) with user_id = @context user_id) they can create'
    );

    $node = $rules[0]->conditions;
    expect($node->schemaKey)->toBe('pay_periods');
    expect($node->isRowBound)->toBeTrue();
    expect($node->contextMap)->toEqual(['user_id' => new ContextRef('user_id')]);
});

it('composes a check(...) leaf with a local condition, negated', function () {
    $rules = WarrantParser::parse(
        'if is_manager and not check(is_locked for pay_periods(@context id)) they can update'
    );

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(AndNode::class);
    expect($node->leftSide->conditionKey)->toBe('is_manager');
    expect($node->rightSide)->toBeInstanceOf(NotNode::class);
    expect($node->rightSide->operand)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->rightSide->operand->schemaKey)->toBe('pay_periods');
});

it('resolves positional bindings across a check(...) predicate arg, row selector and with-map', function () {
    $rules = WarrantParser::parse(
        'if check(is_open(?) for pay_periods(?) with tenant = ?) they can view',
        ['maintenance', 'pp-1', 'tenant-9'],
    );

    $node = $rules[0]->conditions;
    expect($node->predicate->parameters)->toBe(['maintenance']);
    expect($node->boundRow)->toBe('pp-1');
    expect($node->contextMap)->toBe(['tenant' => 'tenant-9']);
});

it('throws when the check(...) predicate is not followed by for', function () {
    expect(fn () => WarrantParser::parse('if check(is_open pay_periods) they can view'))
        ->toThrow(WarrantSyntaxException::class);
});

it('throws on an empty check(...) predicate', function () {
    expect(fn () => WarrantParser::parse('if check(for pay_periods) they can view'))
        ->toThrow(WarrantSyntaxException::class);
});

it('rejects a duplicate key in a check(...) with-map', function () {
    expect(fn () => WarrantParser::parse('if check(is_open for s with a = 1, a = 2) they can view'))
        ->toThrow(WarrantSyntaxException::class, "Duplicate key 'a'");
});
