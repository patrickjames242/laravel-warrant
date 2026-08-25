<?php

use Warrant\RuleSyntaxTree\BoundSyntax;
use Warrant\RuleSyntaxTree\ConditionNode;
use Warrant\RuleSyntaxTree\ContextRef;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

// -- formatting ---------------------------------------------------------------

it('formats if on one line, can and cannot on their own lines', function () {
    $rule = WarrantRule::fromSyntax('if is_self they can view, update they cannot delete');

    expect($rule->toSyntax())->toBe(<<<'TXT'
        if is_self
        they can view, update
        they cannot delete
        TXT);
});

it('omits the if line for an unconditional rule', function () {
    $rule = WarrantRule::fromSyntax('they can view');

    expect($rule->toSyntax())->toBe('they can view');
});

it('separates rules in a set with a blank line', function () {
    $set = WarrantRuleSet::fromSyntax('docs', 'if is_self they can view if is_manager they can approve');

    expect($set->toSyntax())->toBe(<<<'TXT'
        if is_self
        they can view

        if is_manager
        they can approve
        TXT);
});

it('renders wildcard abilities verbatim', function () {
    $rule = WarrantRule::fromSyntax('if is_admin they can *');

    expect($rule->toSyntax())->toBe("if is_admin\nthey can *");
});

// -- minimal parenthesization (not > and > or) --------------------------------

it('drops redundant parens but keeps semantically necessary ones', function (string $in, string $expectedIf) {
    expect(WarrantRule::fromSyntax("if $in they can x")->toSyntax())->toBe("if $expectedIf\nthey can x");
})->with([
    'and binds tighter than or' => ['a and b or c', 'a and b or c'],
    'or under and needs parens'  => ['(a or b) and c', '(a or b) and c'],
    'redundant parens removed'   => ['a and (b and c)', 'a and b and c'],
    'not group keeps parens'     => ['not (a and b)', 'not (a and b)'],
    'not condition no parens'    => ['not a and b', 'not a and b'],
    '! normalized to not'        => ['!a', 'not a'],
]);

// -- inline literals ----------------------------------------------------------

it('writes scalar condition parameters as inline literals', function () {
    $rule = WarrantRule::fromSyntax("if in_department('sales', 'eng') they can view");

    expect($rule->toSyntax())->toBe("if in_department('sales', 'eng')\nthey can view");
});

it('escapes quotes and backslashes in string literals', function () {
    $rule = WarrantRule::build()->if('eq', ["a'b\\c"])->theyCan('view')->toRule();

    expect($rule->toSyntax())->toBe("if eq('a\\'b\\\\c')\nthey can view");
    // and it re-parses back to the same value
    expect(WarrantRule::fromSyntax($rule->toSyntax())->conditions->parameters)->toBe(["a'b\\c"]);
});

it('preserves the int/float distinction and renders bool/null', function () {
    $rule = WarrantRule::build()->if('c', [1, 1.0, 2.5, true, false, null])->theyCan('view')->toRule();

    expect($rule->toSyntax())->toBe("if c(1, 1.0, 2.5, true, false, null)\nthey can view");
});

it('throws when a parameter cannot be written inline', function () {
    $rule = WarrantRule::build()->if('c', [['a', 'b']])->theyCan('view')->toRule();

    expect(fn () => $rule->toSyntax())->toThrow(LogicException::class);
});

// -- bound form ---------------------------------------------------------------

it('extracts every parameter as a positional placeholder', function () {
    $rule = WarrantRule::build()->if('in_department', ['sales', 'eng'])->theyCan('view')->toRule();

    $bound = $rule->toBoundSyntax();

    expect($bound)->toBeInstanceOf(BoundSyntax::class);
    expect($bound->syntax)->toBe("if in_department(?, ?)\nthey can view");
    expect($bound->bindings)->toBe(['sales', 'eng']);
});

it('binds any value losslessly, including non-inlinable ones', function () {
    $ids = [1, 2, 3];
    $rule = WarrantRule::build()->if('in', [$ids])->theyCan('view')->toRule();

    $bound = $rule->toBoundSyntax();

    expect($bound->syntax)->toBe("if in(?)\nthey can view");
    expect($bound->bindings)->toBe([$ids]);
});

it('orders bindings left-to-right across the whole set', function () {
    $set = WarrantRuleSet::fromSyntax(
        'docs',
        'if a(?) they can view if b(?, ?) they can edit',
        ['first', 'second', 'third'],
    );

    $bound = $set->toBoundSyntax();

    expect($bound->syntax)->toBe(<<<'TXT'
        if a(?)
        they can view

        if b(?, ?)
        they can edit
        TXT);
    expect($bound->bindings)->toBe(['first', 'second', 'third']);
});

// -- denial messages (because) ------------------------------------------------

it('writes a string denial message on the cannot line', function () {
    $rule = WarrantRule::fromSyntax("if is_locked they cannot edit because 'This row is locked.'");

    expect($rule->toSyntax())->toBe("if is_locked\nthey cannot edit because 'This row is locked.'");
});

it('escapes quotes in a written denial message', function () {
    $rule = WarrantRule::fromSyntax("they cannot edit because 'can\\'t'");

    expect($rule->toSyntax())->toBe("they cannot edit because 'can\\'t'");
});

it('round-trips a string denial message through the inline form', function () {
    $rule = WarrantRule::fromSyntax("if is_locked they cannot edit because 'locked'");

    expect(WarrantRule::fromSyntax($rule->toSyntax())->message)->toBe('locked');
});

it('extracts a string denial message as a positional binding in bound form', function () {
    $rule = WarrantRule::fromSyntax("if is_locked they cannot edit because 'locked'");

    $bound = $rule->toBoundSyntax();

    expect($bound->syntax)->toBe("if is_locked\nthey cannot edit because ?");
    expect($bound->bindings)->toBe(['locked']);

    // Re-parsing the bound form restores the message.
    expect(WarrantRule::fromSyntax($bound->syntax, $bound->bindings)->message)->toBe('locked');
});

it('orders the message binding after the condition bindings', function () {
    $rule = WarrantRule::fromSyntax("if in_dept('sales') they cannot edit because 'locked'");

    $bound = $rule->toBoundSyntax();

    expect($bound->syntax)->toBe("if in_dept(?)\nthey cannot edit because ?");
    expect($bound->bindings)->toBe(['sales', 'locked']);
});

it('carries a closure denial message losslessly through the bound form', function () {
    $closure = fn () => 'dynamic';
    $rule = WarrantRule::fromSyntax('they cannot edit because :m', ['m' => $closure]);

    $bound = $rule->toBoundSyntax();

    expect($bound->syntax)->toBe('they cannot edit because ?');
    expect($bound->bindings)->toBe([$closure]);
    expect(WarrantRule::fromSyntax($bound->syntax, $bound->bindings)->message)->toBe($closure);
});

it('throws when writing a closure denial message inline', function () {
    $rule = WarrantRule::fromSyntax('they cannot edit')->withDenialMessage(fn () => 'x');

    expect(fn () => $rule->toSyntax())->toThrow(LogicException::class, 'no inline representation');
});

// -- context references (@context) --------------------------------------------

it('renders a context ref as @context <key>, inline and bound alike', function () {
    $rule = WarrantRule::fromSyntax('if is_teacher(@context academic_year_id) they can view');

    expect($rule->toSyntax())->toBe("if is_teacher(@context academic_year_id)\nthey can view");

    // Bound form: the ref is NOT a runtime value, so it renders the same and
    // consumes no positional binding.
    $bound = $rule->toBoundSyntax();
    expect($bound->syntax)->toBe("if is_teacher(@context academic_year_id)\nthey can view");
    expect($bound->bindings)->toBe([]);
});

it('keeps a context ref out of the positional binding stream', function () {
    $rule = WarrantRule::fromSyntax("if is_teacher('x', @context year) they can view");

    $bound = $rule->toBoundSyntax();
    expect($bound->syntax)->toBe("if is_teacher(?, @context year)\nthey can view");
    expect($bound->bindings)->toBe(['x']);

    // Re-parsing the bound form restores the same value + ref shape.
    $reparsed = WarrantRule::fromSyntax($bound->syntax, $bound->bindings);
    expect($reparsed->conditions->parameters[0])->toBe('x');
    expect($reparsed->conditions->parameters[1])->toBeInstanceOf(ContextRef::class);
    expect($reparsed->conditions->parameters[1]->key)->toBe('year');
});

// -- round-trip ---------------------------------------------------------------

it('round-trips the inline form back through the parser', function (string $syntax) {
    $set = WarrantRuleSet::fromSyntax('docs', $syntax);
    $reparsed = WarrantRuleSet::fromSyntax('docs', $set->toSyntax());

    // toSyntax is idempotent: a second render matches the first.
    expect($reparsed->toSyntax())->toBe($set->toSyntax());
})->with([
    'simple'      => ['if is_self they can view'],
    'precedence'  => ['if a or b and not c they can view, update'],
    'grouping'    => ['if (a or b) and c they can view they cannot delete'],
    'literals'    => ["if seen_recently(30, true) they can view"],
    'multi-rule'  => ['they can list if is_admin they can * if is_suspended they cannot *'],
]);

it('round-trips the bound form back through the parser', function () {
    $set = WarrantRuleSet::fromSyntax(
        'docs',
        "if in_department(?, ?) they can view they cannot delete if is_admin they can *",
        ['sales', 'eng'],
    );

    $bound = $set->toBoundSyntax();
    $reparsed = WarrantRuleSet::fromSyntax('docs', $bound->syntax, $bound->bindings);

    expect($reparsed->toBoundSyntax()->syntax)->toBe($bound->syntax);
    expect($reparsed->toBoundSyntax()->bindings)->toBe(['sales', 'eng']);
});

// -- Cross-schema can(...) round-trip -----------------------------------------

it('round-trips an unbound can(...) handle', function () {
    $rule = WarrantRule::fromSyntax('if can(access_payroll for payroll_admin) they can view');

    expect($rule->toSyntax())->toBe(<<<'TXT'
        if can(access_payroll for payroll_admin)
        they can view
        TXT);
});

it('round-trips a row-bound can(...) handle with @context', function () {
    $rule = WarrantRule::fromSyntax('if can(manage for departments(@context department_id)) they can update');

    expect($rule->toSyntax())->toBe(<<<'TXT'
        if can(manage for departments(@context department_id))
        they can update
        TXT);
});

it('round-trips a can(...) with-map', function () {
    $syntax = 'if can(create for billing_plans with as_of_date = @context d, plan_id = @context p) they can create';
    $rule = WarrantRule::fromSyntax($syntax);

    expect($rule->toSyntax())->toBe(<<<'TXT'
        if can(create for billing_plans with as_of_date = @context d, plan_id = @context p)
        they can create
        TXT);
});

it('re-parses to an equal tree (inline round-trip)', function () {
    $syntax = 'if is_self and can(manage for departments(@context id) with tenant = @context t) they can update';
    $once = WarrantRule::fromSyntax($syntax);
    $twice = WarrantRule::fromSyntax($once->toSyntax());

    expect($twice->conditions)->toEqual($once->conditions);
});

it('renders literal row selectors and with-values via bound syntax losslessly', function () {
    $rule = WarrantRule::fromSyntax(
        'if can(manage for departments(?) with tenant = ?) they can update',
        ['dept-1', 'tenant-9'],
    );

    $bound = $rule->toBoundSyntax();
    expect($bound->bindings)->toBe(['dept-1', 'tenant-9']);

    $reparsed = WarrantRule::fromSyntax($bound->syntax, $bound->bindings);
    expect($reparsed->conditions)->toEqual($rule->conditions);
});

// -- Cross-schema check(...) round-trip ----------------------------------------

it('round-trips an unbound check(...) handle with a global condition', function () {
    $rule = WarrantRule::fromSyntax("if check(is_open('maintenance') for tenant_settings) they cannot update");

    expect($rule->toSyntax())->toBe(<<<'TXT'
        if check(is_open('maintenance') for tenant_settings)
        they cannot update
        TXT);
});

it('round-trips a row-bound check(...) handle with @context', function () {
    $rule = WarrantRule::fromSyntax(
        'if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context id)) they cannot update'
    );

    expect($rule->toSyntax())->toBe(<<<'TXT'
        if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context id))
        they cannot update
        TXT);
});

it('round-trips a complex check(...) predicate with minimal parentheses', function () {
    $syntax = 'if check(is_published or (needs_review and not is_locked) for pay_periods(@context id)) they can approve';
    $rule = WarrantRule::fromSyntax($syntax);

    expect($rule->toSyntax())->toBe(<<<'TXT'
        if check(is_published or needs_review and not is_locked for pay_periods(@context id))
        they can approve
        TXT);
});

it('re-parses a check(...) to an equal tree (inline round-trip)', function () {
    $syntax = 'if is_manager and not check(is_locked or is_frozen for pay_periods(@context id) with t = @context t) they can update';
    $once = WarrantRule::fromSyntax($syntax);
    $twice = WarrantRule::fromSyntax($once->toSyntax());

    expect($twice->conditions)->toEqual($once->conditions);
});

it('renders a check(...) row selector and predicate args via bound syntax losslessly', function () {
    $rule = WarrantRule::fromSyntax(
        'if check(is_open(?) for pay_periods(?) with tenant = ?) they can view',
        ['maintenance', 'pp-1', 'tenant-9'],
    );

    $bound = $rule->toBoundSyntax();
    expect($bound->bindings)->toBe(['maintenance', 'pp-1', 'tenant-9']);

    $reparsed = WarrantRule::fromSyntax($bound->syntax, $bound->bindings);
    expect($reparsed->conditions)->toEqual($rule->conditions);
});
