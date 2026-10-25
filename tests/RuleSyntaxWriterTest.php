<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\Builders\Build;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

// -- formatting ---------------------------------------------------------------

it('formats if on one line, can and cannot on their own lines', function () {
    $rule = WarrantSyntax::parse('if is_self they can view, update they cannot delete')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if is_self
        they can view, update
        they cannot delete
        TXT);
});

it('omits the if line for an unconditional rule', function () {
    $rule = WarrantSyntax::parse('they can view')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe('they can view');
});

it('separates rules in a set with a blank line', function () {
    $set = WarrantSyntax::parse('if is_self they can view if is_manager they can approve')->validateWithoutSchemas()->scopedTo('docs');

    expect(writeSyntax($set))->toBe(<<<'TXT'
        for docs {
            if is_self
            they can view

            if is_manager
            they can approve
        }
        TXT);
});

it('renders wildcard abilities verbatim', function () {
    $rule = WarrantSyntax::parse('if is_admin they can *')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if is_admin\nthey can *");
});

// -- minimal parenthesization (not > and > or) --------------------------------

it('drops redundant parens but keeps semantically necessary ones', function (string $in, string $expectedIf) {
    expect(WarrantSyntax::parse("if $in they can x")->validateWithoutSchemas()->toSyntax())->toBe("if $expectedIf\nthey can x");
})->with([
    'and binds tighter than or' => ['a and b or c', 'a and b or c'],
    'or under and needs parens'  => ['(a or b) and c', '(a or b) and c'],
    'redundant parens removed'   => ['a and (b and c)', 'a and b and c'],
    'not group keeps parens'     => ['not (a and b)', 'not (a and b)'],
    'not condition no parens'    => ['not a and b', 'not a and b'],
]);

// -- inline literals ----------------------------------------------------------

it('writes scalar condition parameters as inline literals', function () {
    $rule = WarrantSyntax::parse("if in_department('sales', 'eng') they can view")->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if in_department('sales', 'eng')\nthey can view");
});

it('escapes quotes and backslashes in string literals', function () {
    $rule = Build::rule()->if('eq', ["a'b\\c"])->theyCan('view')->toRule();

    expect(writeSyntax($rule))->toBe("if eq('a\\'b\\\\c')\nthey can view");
    // and it re-parses back to the same value
    expect(WarrantSyntax::parse(writeSyntax($rule))->validateWithoutSchemas()->rule()->conditions->parameters)->toEqualSyntax(array_map(argumentNode(...), ["a'b\\c"]));
});

it('preserves the int/float distinction and renders bool/null', function () {
    $rule = Build::rule()->if('c', [1, 1.0, 2.5, true, false, null])->theyCan('view')->toRule();

    expect(writeSyntax($rule))->toBe("if c(1, 1.0, 2.5, true, false, null)\nthey can view");
});

it('throws when a parameter cannot be written inline', function () {
    $rule = Build::rule()->if('c', [['a', 'b']])->theyCan('view')->toRule();

    expect(fn () => writeSyntax($rule))->toThrow(LogicException::class);
});

// -- bound form ---------------------------------------------------------------

it('extracts every parameter as a named binding of its own', function () {
    $rule = Build::rule()->if('in_department', ['sales', 'eng'])->theyCan('view')->toRule();

    $bound = writeBoundSyntax($rule);

    expect($bound)->toBeInstanceOf(BoundSyntax::class);
    expect($bound->syntax)->toBe("if in_department(:b0, :b1)\nthey can view");
    expect($bound->bindings)->toBe(['b0' => 'sales', 'b1' => 'eng']);
});

it('binds any value losslessly, including non-inlinable ones', function () {
    $ids = [1, 2, 3];
    $rule = Build::rule()->if('in', [$ids])->theyCan('view')->toRule();

    $bound = writeBoundSyntax($rule);

    expect($bound->syntax)->toBe("if in(:b0)\nthey can view");
    expect($bound->bindings)->toBe(['b0' => $ids]);
});

it('names bindings in the order written across the whole set', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        'if a(:one) they can view if b(:two, :three) they can edit',
        ['one' => 'first', 'two' => 'second', 'three' => 'third'],
    ))->validateWithoutSchemas()->scopedTo('docs');

    $bound = writeBoundSyntax($set);

    expect($bound->syntax)->toBe(<<<'TXT'
        for docs {
            if a(:b0)
            they can view

            if b(:b1, :b2)
            they can edit
        }
        TXT);
    expect($bound->bindings)->toBe(['b0' => 'first', 'b1' => 'second', 'b2' => 'third']);
});

// -- denial messages (because) ------------------------------------------------

it('writes a string denial message on the cannot line', function () {
    $rule = WarrantSyntax::parse("if is_locked they cannot edit because 'This row is locked.'")->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if is_locked\nthey cannot edit because 'This row is locked.'");
});

it('escapes quotes in a written denial message', function () {
    $rule = WarrantSyntax::parse("they cannot edit because 'can\\'t'")->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("they cannot edit because 'can\\'t'");
});

it('round-trips a string denial message through the inline form', function () {
    $rule = WarrantSyntax::parse("if is_locked they cannot edit because 'locked'")->validateWithoutSchemas()->rule();

    expect(WarrantSyntax::parse(writeSyntax($rule))->validateWithoutSchemas()->rule()->messageFor('edit'))->toBe('locked');
});

it('extracts a string denial message as a named binding in bound form', function () {
    $rule = WarrantSyntax::parse("if is_locked they cannot edit because 'locked'")->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);

    expect($bound->syntax)->toBe("if is_locked\nthey cannot edit because :b0");
    expect($bound->bindings)->toBe(['b0' => 'locked']);

    // Re-parsing the bound form restores the message.
    expect(WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule()->messageFor('edit'))->toBe('locked');
});

it('orders the message binding after the condition bindings', function () {
    $rule = WarrantSyntax::parse("if in_dept('sales') they cannot edit because 'locked'")->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);

    expect($bound->syntax)->toBe("if in_dept(:b0)\nthey cannot edit because :b1");
    expect($bound->bindings)->toBe(['b0' => 'sales', 'b1' => 'locked']);
});

it('carries a closure denial message losslessly through the bound form', function () {
    $closure = fn () => 'dynamic';
    $rule = WarrantSyntax::parse(new BoundSyntax('they cannot edit because :m', ['m' => $closure]))->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);

    expect($bound->syntax)->toBe('they cannot edit because :b0');
    expect($bound->bindings)->toBe(['b0' => $closure]);
    expect(WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule()->messageFor('edit'))->toBe($closure);
});

it('throws when writing a closure denial message inline', function () {
    $rule = WarrantSyntax::parse(new BoundSyntax('they cannot edit because :msg', ['msg' => fn () => 'x']))->validateWithoutSchemas()->rule();

    expect(fn () => writeSyntax($rule))->toThrow(LogicException::class, 'no inline representation');
});

it('renders one they-cannot line per cannot clause and round-trips them', function () {
    $rule = WarrantSyntax::parse(
        "if is_locked they cannot update because 'no update' they cannot delete because 'no delete'",
    )->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if is_locked
        they cannot update because 'no update'
        they cannot delete because 'no delete'
        TXT);

    // Re-parsing yields the same single rule with both cannot clause messages.
    $reparsed = WarrantSyntax::parse(writeSyntax($rule))->validateWithoutSchemas()->rule();
    expect($reparsed->cannotClauses)->toHaveCount(2);
    expect($reparsed->messageFor('update'))->toBe('no update');
    expect($reparsed->messageFor('delete'))->toBe('no delete');
});

// -- context references (@context) --------------------------------------------

it('renders a context ref as @context <key>, inline and bound alike', function () {
    $rule = WarrantSyntax::parse('if is_teacher(@context academic_year_id) they can view')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if is_teacher(@context academic_year_id)\nthey can view");

    // Bound form: the ref is NOT a runtime value, so it renders the same and
    // is not made a binding.
    $bound = writeBoundSyntax($rule);
    expect($bound->syntax)->toBe("if is_teacher(@context academic_year_id)\nthey can view");
    expect($bound->bindings)->toBe([]);
});

it('renders a context ref beside a bound value as a reference', function () {
    $rule = WarrantSyntax::parse("if is_teacher('x', @context year) they can view")->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);
    expect($bound->syntax)->toBe("if is_teacher(:b0, @context year)\nthey can view");
    expect($bound->bindings)->toBe(['b0' => 'x']);

    // Re-parsing the bound form restores the same value + ref shape.
    $reparsed = WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule();
    expect(argumentValues($reparsed->conditions->parameters)[0])->toEqualSyntax(('x'));
    expect($reparsed->conditions->parameters[1])->toBeInstanceOf(ContextRef::class);
    expect($reparsed->conditions->parameters[1]->key->name)->toBe('year');
});

it('round-trips a bare @column through the writer', function () {
    $rule = WarrantSyntax::parse('if is_teacher(@column pay_period_id) they can view')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if is_teacher(@column pay_period_id)\nthey can view");
});

it('round-trips a can with no for clause', function () {
    $rule = WarrantSyntax::parse('if can(do_thing_1) they can do_thing_2')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if can(do_thing_1)\nthey can do_thing_2");
});

// -- handle aliases (as) ------------------------------------------------------

it('renders an as <alias> tail between the row selector and the with-map', function () {
    $rule = WarrantSyntax::parse(
        'if can(view for docs(@context id) as d2 with tenant = 7) they can update',
    )->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))
        ->toBe("if can(view for docs(@context id) as d2 with tenant = 7)\nthey can update");
});

it('round-trips an aliased check(...) handle', function () {
    $rule = WarrantSyntax::parse('if check(is_open for docs(@context id) as d2) they can update')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if check(is_open for docs(@context id) as d2)\nthey can update");
});

it('renders no as tail for an unaliased handle', function () {
    $rule = WarrantSyntax::parse('if can(view for docs(@context id)) they can update')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if can(view for docs(@context id))\nthey can update");
});

// -- column references (@column) ----------------------------------------------

it('renders a column ref as @column <schema>.<column>, inline and bound alike', function () {
    $rule = WarrantSyntax::parse('if is_teacher(@column timesheets.pay_period_id) they can view')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe("if is_teacher(@column timesheets.pay_period_id)\nthey can view");

    // Bound form: the ref is NOT a runtime value, so it renders the same and
    // is not made a binding.
    $bound = writeBoundSyntax($rule);
    expect($bound->syntax)->toBe("if is_teacher(@column timesheets.pay_period_id)\nthey can view");
    expect($bound->bindings)->toBe([]);
});

it('renders a column ref beside a bound value as a reference', function () {
    $rule = WarrantSyntax::parse("if is_teacher('x', @column timesheets.id) they can view")->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);
    expect($bound->syntax)->toBe("if is_teacher(:b0, @column timesheets.id)\nthey can view");
    expect($bound->bindings)->toBe(['b0' => 'x']);

    // Re-parsing the bound form restores the same value + ref shape.
    $reparsed = WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule();
    expect(argumentValues($reparsed->conditions->parameters)[0])->toEqualSyntax(('x'));
    expect($reparsed->conditions->parameters[1])->toBeInstanceOf(ColumnRef::class);
    expect($reparsed->conditions->parameters[1]->alias?->name)->toBe('timesheets');
    expect($reparsed->conditions->parameters[1]->column?->name)->toBe('id');
});

// -- SQL references (@sql) ----------------------------------------------------

it('renders a @sql ref as @sql \'<sql>\', inline and bound alike', function () {
    $rule = WarrantSyntax::parse('if is_teacher(@sql "select 1") they can view')->validateWithoutSchemas()->rule();

    // Rendered with single quotes (the writer's canonical string form).
    expect(writeSyntax($rule))->toBe("if is_teacher(@sql 'select 1')\nthey can view");

    // Bound form: the ref is NOT a runtime value, so it renders the same and
    // is not made a binding.
    $bound = writeBoundSyntax($rule);
    expect($bound->syntax)->toBe("if is_teacher(@sql 'select 1')\nthey can view");
    expect($bound->bindings)->toBe([]);
});

it('renders a @sql ref beside a bound value as a reference', function () {
    $rule = WarrantSyntax::parse("if is_teacher('x', @sql \"select 1\") they can view")->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);
    expect($bound->syntax)->toBe("if is_teacher(:b0, @sql 'select 1')\nthey can view");
    expect($bound->bindings)->toBe(['b0' => 'x']);

    // Re-parsing the bound form restores the same value + ref shape.
    $reparsed = WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule();
    expect(argumentValues($reparsed->conditions->parameters)[0])->toEqualSyntax(('x'));
    expect(argumentValues($reparsed->conditions->parameters)[1])->toEqualSyntax((new SqlRef(argumentNode('select 1'), SyntaxLocation::noProducer())));
});

it('escapes quotes and backslashes in a @sql body so it round-trips', function () {
    // A body containing single quotes, double quotes, and a backslash must survive
    // the render → re-parse round trip unchanged.
    $rule = WarrantSyntax::parse('if is_teacher(@sql "id = \'a\' or n = \\"b\\"") they can view')->validateWithoutSchemas()->rule();

    $reparsed = WarrantSyntax::parse(writeSyntax($rule))->validateWithoutSchemas()->rule();
    expect(argumentValues($reparsed->conditions->parameters)[0])->toEqualSyntax((new SqlRef(argumentNode('id = \'a\' or n = "b"'), SyntaxLocation::noProducer())));
});

// -- round-trip ---------------------------------------------------------------

it('round-trips the inline form back through the parser', function (string $syntax) {
    $set = WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('docs');
    $reparsed = WarrantSyntax::parse(writeSyntax($set))->validateWithoutSchemas()->scopedTo('docs');

    // toSyntax is idempotent: a second render matches the first.
    expect(writeSyntax($reparsed))->toBe(writeSyntax($set));
})->with([
    'simple'      => ['if is_self they can view'],
    'precedence'  => ['if a or b and not c they can view, update'],
    'grouping'    => ['if (a or b) and c they can view they cannot delete'],
    'literals'    => ["if seen_recently(30, true) they can view"],
    'multi-rule'  => ['they can list if is_admin they can * if is_suspended they cannot *'],
]);

it('round-trips the bound form back through the parser', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        "if in_department(:first, :second) they can view they cannot delete if is_admin they can *",
        ['first' => 'sales', 'second' => 'eng'],
    ))->validateWithoutSchemas()->scopedTo('docs');

    $bound = writeBoundSyntax($set);
    $reparsed = WarrantSyntax::parse($bound)->validateWithoutSchemas()->scopedTo('docs');

    expect(writeBoundSyntax($reparsed)->syntax)->toBe($bound->syntax);
    expect(writeBoundSyntax($reparsed)->bindings)->toBe(['b0' => 'sales', 'b1' => 'eng']);
});

// -- Cross-schema can(...) round-trip -----------------------------------------

it('round-trips an unbound can(...) handle', function () {
    $rule = WarrantSyntax::parse('if can(access_payroll for payroll_admin) they can view')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if can(access_payroll for payroll_admin)
        they can view
        TXT);
});

it('round-trips a row-bound can(...) handle with @context', function () {
    $rule = WarrantSyntax::parse('if can(manage for departments(@context department_id)) they can update')->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if can(manage for departments(@context department_id))
        they can update
        TXT);
});

it('round-trips a can(...) with-map', function () {
    $syntax = 'if can(create for billing_plans with as_of_date = @context d, plan_id = @context p) they can create';
    $rule = WarrantSyntax::parse($syntax)->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if can(create for billing_plans with as_of_date = @context d, plan_id = @context p)
        they can create
        TXT);
});

it('re-parses to an equal tree (inline round-trip)', function () {
    $syntax = 'if is_self and can(manage for departments(@context id) with tenant = @context t) they can update';
    $once = WarrantSyntax::parse($syntax)->validateWithoutSchemas()->rule();
    $twice = WarrantSyntax::parse(writeSyntax($once))->validateWithoutSchemas()->rule();

    expect($twice->conditions)->toEqualSyntax($once->conditions);
});

it('renders literal row selectors and with-values via bound syntax losslessly', function () {
    $rule = WarrantSyntax::parse(new BoundSyntax(
        'if can(manage for departments(:department) with tenant = :tenant) they can update',
        ['department' => 'dept-1', 'tenant' => 'tenant-9'],
    ))->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);
    expect($bound->bindings)->toBe(['b0' => 'dept-1', 'b1' => 'tenant-9']);

    $reparsed = WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule();
    expect(writeSyntax($reparsed))->toBe(writeSyntax($rule));
});

// -- Cross-schema check(...) round-trip ----------------------------------------

it('round-trips an unbound check(...) handle with a global condition', function () {
    $rule = WarrantSyntax::parse("if check(is_open('maintenance') for tenant_settings) they cannot update")->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if check(is_open('maintenance') for tenant_settings)
        they cannot update
        TXT);
});

it('round-trips a row-bound check(...) handle with @context', function () {
    $rule = WarrantSyntax::parse(
        'if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context id)) they cannot update',
    )->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context id))
        they cannot update
        TXT);
});

it('round-trips a complex check(...) predicate with minimal parentheses', function () {
    $syntax = 'if check(is_published or (needs_review and not is_locked) for pay_periods(@context id)) they can approve';
    $rule = WarrantSyntax::parse($syntax)->validateWithoutSchemas()->rule();

    expect(writeSyntax($rule))->toBe(<<<'TXT'
        if check(is_published or needs_review and not is_locked for pay_periods(@context id))
        they can approve
        TXT);
});

it('re-parses a check(...) to an equal tree (inline round-trip)', function () {
    $syntax = 'if is_manager and not check(is_locked or is_frozen for pay_periods(@context id) with t = @context t) they can update';
    $once = WarrantSyntax::parse($syntax)->validateWithoutSchemas()->rule();
    $twice = WarrantSyntax::parse(writeSyntax($once))->validateWithoutSchemas()->rule();

    expect($twice->conditions)->toEqualSyntax($once->conditions);
});

it('renders a check(...) row selector and predicate args via bound syntax losslessly', function () {
    $rule = WarrantSyntax::parse(new BoundSyntax(
        'if check(is_open(:mode) for pay_periods(:period) with tenant = :tenant) they can view',
        ['mode' => 'maintenance', 'period' => 'pp-1', 'tenant' => 'tenant-9'],
    ))->validateWithoutSchemas()->rule();

    $bound = writeBoundSyntax($rule);
    expect($bound->bindings)->toBe(['b0' => 'maintenance', 'b1' => 'pp-1', 'b2' => 'tenant-9']);

    $reparsed = WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule();
    expect(writeSyntax($reparsed))->toBe(writeSyntax($rule));
});

// -- ability blocks -----------------------------------------------------------

it('renders an ability block as the block it was written as', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view, edit {
            if is_public they can
            if is_locked they cannot because 'Locked.'
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('docs');

    expect(writeSyntax($set))->toBe(<<<'TXT'
        for docs {
            can they view, edit {
                if is_public
                they can

                if is_locked
                they cannot because 'Locked.'
            }
        }
        TXT);
});

it('round-trips an ability block to the same tree', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view, edit {
            if is_public they can
            @include requires_approval
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('docs');

    expect(WarrantSyntax::parse(writeSyntax($set))->validateWithoutSchemas()->ruleSet())->toEqualSyntax($set);
});

// -- when blocks --------------------------------------------------------------

it('renders a when block and its otherwise as written', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        when is_published and not is_archived {
            if is_member they can view
            @include shared for edit
        } otherwise {
            they cannot view because 'Not published.'
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('docs');

    expect(writeSyntax($set))->toBe(<<<'TXT'
        for docs {
            when is_published and not is_archived {
                if is_member
                they can view

                @include shared for edit
            } otherwise {
                they cannot view because 'Not published.'
            }
        }
        TXT);
});

it('round-trips nested when and ability blocks to the same tree', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        when is_published {
            can they view {
                when is_shared { if is_member they can } otherwise { they cannot }
            }
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('docs');

    expect(WarrantSyntax::parse(writeSyntax($set))->validateWithoutSchemas()->ruleSet())->toEqualSyntax($set);
});
