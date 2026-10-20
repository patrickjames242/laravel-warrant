<?php

use Warrant\Builders\Build;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\DSL\SchemaCatalog;
use Warrant\DSL\SchemaVocabulary;
use Warrant\DSL\Validation\LanguageRuleMessages;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ConditionDefinition;
use Warrant\Schema\ConditionKind;

require_once __DIR__.'/Support/TestSupport.php';

/**
 * The rules of the language validating $syntax as written reports it breaks,
 * with no schema to look its names up in.
 *
 * @return list<string>
 */
function languageRuleErrors(string $syntax): array
{
    $noSchemas = new class implements SchemaCatalog
    {
        public function find(string $schemaKey): ?SchemaVocabulary
        {
            return null;
        }
    };

    return array_values(array_filter(
        array_map(
            static fn (Diagnostic $diagnostic): string => $diagnostic->message,
            mistakesOf(WarrantSyntax::parse($syntax), new ValidationInput($noSchemas)),
        ),
        static fn (string $message): bool => ! str_contains($message, 'names unknown schema'),
    ));
}

// -- unscoped rules -----------------------------------------------------------

it('parses source and bindings into a flat list of rules', function () {
    $rules = WarrantSyntax::parse('if is_teacher they can view if is_admin they can edit')->validateWithoutSchemas()->ruleEntries();

    expect($rules)->toBeArray()->toHaveCount(2);
    expect($rules[0])->toBeInstanceOf(WarrantRuleNode::class);
    expect($rules[0]->conditions->conditionKey?->name)->toBe('is_teacher');
    expect($rules[1]->conditions->conditionKey?->name)->toBe('is_admin');
});

it('resolves bindings in unscoped rules', function () {
    $rules = WarrantSyntax::parse(new BoundSyntax('if is_owner(:id) they can view', ['id' => 'x-1']))->validateWithoutSchemas()->ruleEntries();

    expect(argumentValues($rules[0]->conditions->parameters))->toEqualSyntax((['x-1']));
});

// -- Basics -------------------------------------------------------------------

it('parses a single rule with can and cannot clauses', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        if is_self
        they can edit, view, delete
        they cannot approve, deny
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->schemaKey?->name)->toBe('timesheets');
    expect($set->entries)->toHaveCount(1);

    $rule = $set->entries[0];
    expect($rule->conditions)->toBeInstanceOf(ConditionNode::class);
    expect($rule->conditions->conditionKey?->name)->toBe('is_self');
    expect($rule->canAbilities())->toBe(['edit', 'view', 'delete']);
    expect($rule->cannotAbilities())->toBe(['approve', 'deny']);
});

it('parses multiple rules in one string', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        if is_self
        they can edit

        if has_access_control_level
        they can view
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries)->toHaveCount(2);
    expect($set->entries[0]->conditions->conditionKey?->name)->toBe('is_self');
    expect($set->entries[1]->conditions->conditionKey?->name)->toBe('has_access_control_level');
});

it('parses an unconditional rule (no if) with null conditions', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        they can view
        they cannot delete
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries)->toHaveCount(1);
    expect($set->entries[0]->conditions)->toBeNull();
    expect($set->entries[0]->canAbilities())->toBe(['view']);
    expect($set->entries[0]->cannotAbilities())->toBe(['delete']);
});

it('allows an empty rule set', function () {
    $set = WarrantSyntax::parse('   ')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries)->toBe([]);
});

// -- Whitespace ---------------------------------------------------------------

it('treats whitespace as insignificant (whole ruleset on one line)', function () {
    $set = WarrantSyntax::parse(
        'if is_self they can edit if is_manager they can approve they cannot delete',
    )->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries)->toHaveCount(2);
    expect($set->entries[0]->conditions->conditionKey?->name)->toBe('is_self');
    expect($set->entries[0]->canAbilities())->toBe(['edit']);
    expect($set->entries[1]->conditions->conditionKey?->name)->toBe('is_manager');
    expect($set->entries[1]->canAbilities())->toBe(['approve']);
    expect($set->entries[1]->cannotAbilities())->toBe(['delete']);
});

// -- Boolean expressions ------------------------------------------------------

it('applies precedence not > and > or', function () {
    $set = WarrantSyntax::parse('if is_self or not is_manager and is_owner they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    // Expect: Or(is_self, And(Not(is_manager), is_owner))
    $expr = $set->entries[0]->conditions;
    expect($expr)->toBeInstanceOf(OrNode::class);
    expect($expr->leftSide)->toBeInstanceOf(ConditionNode::class);
    expect($expr->leftSide->conditionKey?->name)->toBe('is_self');

    expect($expr->rightSide)->toBeInstanceOf(AndNode::class);
    expect($expr->rightSide->leftSide)->toBeInstanceOf(NotNode::class);
    expect($expr->rightSide->leftSide->operand->conditionKey?->name)->toBe('is_manager');
    expect($expr->rightSide->rightSide->conditionKey?->name)->toBe('is_owner');
});

it('treats ! as a synonym for not', function () {
    $bang = WarrantSyntax::parse('if !is_manager they can view')->validateWithoutSchemas()->scopedTo('timesheets');
    $word = WarrantSyntax::parse('if not is_manager they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($bang->entries[0]->conditions)->toBeInstanceOf(NotNode::class);
    expect($word->entries[0]->conditions)->toBeInstanceOf(NotNode::class);
});

it('honours parentheses over precedence', function () {
    $set = WarrantSyntax::parse('if !(is_self or is_manager) they cannot edit')->validateWithoutSchemas()->scopedTo('timesheets');

    $expr = $set->entries[0]->conditions;
    expect($expr)->toBeInstanceOf(NotNode::class);
    expect($expr->operand)->toBeInstanceOf(OrNode::class);
});

// -- Inline literals ----------------------------------------------------------

it('parses inline literals of every supported type', function () {
    $set = WarrantSyntax::parse("if is_thing('a-string', 42, 3.14, true, null) they can view")->validateWithoutSchemas()->scopedTo('timesheets');

    $params = argumentValues($set->entries[0]->conditions->parameters);
    expect($params)->toBe(['a-string', 42, 3.14, true, null]);
});

it('unescapes quotes and backslashes in string literals', function () {
    $set = WarrantSyntax::parse("if is_thing('a\\'b\\\\c') they can view")->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters))->toEqualSyntax((["a'b\\c"]));
});

it('parses a double-quoted string literal', function () {
    $set = WarrantSyntax::parse('if is_thing("a-string") they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters))->toEqualSyntax((['a-string']));
});

it('keeps a single quote literal inside a double-quoted string', function () {
    $set = WarrantSyntax::parse('if is_thing("can\'t touch this") they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters))->toEqualSyntax((["can't touch this"]));
});

it('keeps a double quote literal inside a single-quoted string', function () {
    $set = WarrantSyntax::parse('if is_thing(\'she said "hi"\') they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters))->toEqualSyntax((['she said "hi"']));
});

it('unescapes quotes and backslashes in double-quoted string literals', function () {
    $set = WarrantSyntax::parse('if is_thing("a\\"b\\\\c") they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters))->toEqualSyntax((['a"b\\c']));
});

it('allows escaping either quote regardless of the delimiter', function () {
    $single = WarrantSyntax::parse("if is_thing('a\\\"b') they can view")->validateWithoutSchemas()->scopedTo('timesheets');
    $double = WarrantSyntax::parse('if is_thing("a\\\'b") they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($single->entries[0]->conditions->parameters))->toEqualSyntax((['a"b']));
    expect(argumentValues($double->entries[0]->conditions->parameters))->toEqualSyntax((["a'b"]));
});

// -- Bindings -----------------------------------------------------------------

it('resolves named bindings inline, reused and order-independent', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(<<<'WARRANT'
        if is_specific_user(:user_id, :user_id, :list)
        they cannot edit
        WARRANT, [
        'list' => [1, null, false, 'x'],
        'user_id' => 'some-user-id',
    ]))->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters))->toEqualSyntax(([
        'some-user-id',
        'some-user-id',
        [1, null, false, 'x'],
    ]));
});

it('resolves positional bindings left-to-right across the whole string', function () {
    $set = WarrantSyntax::parse(new BoundSyntax('if is_department(?, ?, ?) they can view', [
        'a', 'b', 'c',
    ]))->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters))->toEqualSyntax((['a', 'b', 'c']));
});

it('accepts any value type through a binding', function () {
    $object = new stdClass;
    $set = WarrantSyntax::parse(new BoundSyntax('if is_thing(:v) they can view', ['v' => $object]))->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters)[0])->toEqualSyntax(($object));
});

// -- Context references (@context) --------------------------------------------

it('parses @context <key> into a symbolic ContextRef, not a value', function () {
    $set = WarrantSyntax::parse('if is_teacher(@context academic_year_id) they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    $params = argumentValues($set->entries[0]->conditions->parameters);
    expect($params)->toHaveCount(1);
    expect($params[0])->toBeInstanceOf(ContextRef::class);
    expect($params[0]->key->name)->toBe('academic_year_id');
});

it('mixes a context ref with literals and bindings in one condition', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        "if is_teacher('x', @context year, :b) they can view",
        ['b' => 42],
    ))->validateWithoutSchemas()->scopedTo('timesheets');

    $params = argumentValues($set->entries[0]->conditions->parameters);
    expect($params[0])->toBe('x');
    expect($params[1])->toBeInstanceOf(ContextRef::class);
    expect($params[1]->key->name)->toBe('year');
    expect($params[2])->toBe(42);
});

it('exempts a context ref from binding finalize (no "unused binding" error)', function () {
    // A bare @context ref with an empty bindings array must not trip the
    // all-bindings-used / mixing checks — it is resolved later, at check time.
    $set = WarrantSyntax::parse('if is_teacher(@context year) they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->conditions->parameters[0])->toBeInstanceOf(ContextRef::class);
});

it('errors on a bad context sigil or a missing key', function (string $syntax, string $needle) {
    expect(fn () => WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'not context'  => ['if is_teacher(@year) they can view', "Expected 'context'"],
    'missing key'  => ['if is_teacher(@context) they can view', 'Expected a context key'],
]);

// -- Column references (@column) ----------------------------------------------

it('parses @column <name>.<column> into a symbolic ColumnRef, not a value', function () {
    $set = WarrantSyntax::parse('if is_teacher(@column timesheets.pay_period_id) they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    $params = argumentValues($set->entries[0]->conditions->parameters);
    expect($params)->toHaveCount(1);
    expect($params[0])->toBeInstanceOf(ColumnRef::class);
    expect($params[0]->alias?->name)->toBe('timesheets');
    expect($params[0]->column?->name)->toBe('pay_period_id');
});

it('mixes a column ref with literals, context refs, and bindings in one condition', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        "if is_teacher('x', @column timesheets.id, @context year, :b) they can view",
        ['b' => 42],
    ))->validateWithoutSchemas()->scopedTo('timesheets');

    $params = argumentValues($set->entries[0]->conditions->parameters);
    expect($params[0])->toBe('x');
    expect($params[1])->toEqualSyntax(new ColumnRef(nameNode('timesheets'), nameNode('id'), SyntaxLocation::noProducer()));
    expect($params[2])->toEqualSyntax(new ContextRef(nameNode('year'), SyntaxLocation::noProducer()));
    expect($params[3])->toBe(42);
});

it('exempts a column ref from binding finalize (no "unused binding" error)', function () {
    // Like @context, a bare @column ref with an empty bindings array must not trip
    // the all-bindings-used / mixing checks — it is resolved later, at compile time.
    $set = WarrantSyntax::parse('if is_teacher(@column timesheets.id) they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->conditions->parameters[0])->toBeInstanceOf(ColumnRef::class);
});

it('parses a @column row selector in a can(...) handle', function () {
    $rules = WarrantSyntax::parse('if can(manage for departments(@column timesheets.department_id)) they can update')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect(argumentValues($node->boundKey))->toEqualSyntax(([new ColumnRef(nameNode('timesheets'), nameNode('department_id'), SyntaxLocation::noProducer())]));
});

it('parses a @column row selector in a check(...) handle', function () {
    $rules = WarrantSyntax::parse(
        'if check(is_open for pay_periods(@column timesheets.pay_period_id)) they can update',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect(argumentValues($node->boundKey))->toEqualSyntax(([new ColumnRef(nameNode('timesheets'), nameNode('pay_period_id'), SyntaxLocation::noProducer())]));
});

it('parses a @column value in a with-map', function () {
    $rules = WarrantSyntax::parse(
        'if can(create for billing_plans with plan_id = @column timesheets.plan_id) they can create',
    )->validateWithoutSchemas()->ruleEntries();

    expect($rules[0]->conditions->contextMap)->toEqualSyntax(withEntries(['plan_id' => new ColumnRef(nameNode('timesheets'), nameNode('plan_id'), SyntaxLocation::noProducer())]));
});

it('errors on a bad @-sigil or a malformed @column reference', function (string $syntax, string $needle) {
    expect(fn () => WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'bad sigil'    => ['if is_teacher(@col) they can view', "Expected 'context', 'column', 'sql', or 'include'"],
    'trailing dot' => ['if is_teacher(@column timesheets.) they can view', 'Expected a column name'],
    'missing all'  => ['if is_teacher(@column) they can view', "Expected a column name after '@column'"],
]);

it('parses a bare @column <column> with no qualifier at all', function () {
    $set = WarrantSyntax::parse('if is_teacher(@column pay_period_id) they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->conditions->parameters)[0])->toEqualSyntax((new ColumnRef(null, nameNode('pay_period_id'), SyntaxLocation::noProducer())));
});

it('renders a bare @column without a stray dot', function () {
    $set = WarrantSyntax::parse('if is_teacher(@column pay_period_id) they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argToString($set->entries[0]->conditions->parameters[0]))->toBe('@column pay_period_id');
});

// -- can(<ability>) with no for clause ----------------------------------------

it('parses a can with no for clause, naming no schema', function () {
    $rules = WarrantSyntax::parse('if can(do_thing_1) they can do_thing_2')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->schemaKey)->toBeNull();
    expect($node->ability?->name)->toBe('do_thing_1');
    expect($node->isRowBound)->toBeFalse();
    expect($node->contextMap)->toEqualSyntax(withEntries([]));
    expect($node->alias)->toBeNull();
    expect(treeToString($node))->toBe('can(do_thing_1)');
});

it('errors on anything but for or a closing paren after the ability', function () {
    expect(fn () => WarrantSyntax::parse('if can(do_thing_1 as d2) they can update')->validateWithoutSchemas()->ruleEntries())
        ->toThrow(WarrantSyntaxException::class, "Expected 'for' or ')' after the ability name");
});

// -- handle aliases (as) ------------------------------------------------------

it('parses an as <alias> tail on a can(...) handle', function () {
    $rules = WarrantSyntax::parse('if can(view for docs(@context id) as d2) they can update')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->alias?->name)->toBe('d2');
    expect($node->isRowBound)->toBeTrue();
    expect(handleToString($node))->toBe('docs(@context id) as d2');
});

it('parses an as <alias> tail on a check(...) handle', function () {
    $rules = WarrantSyntax::parse('if check(is_open for docs(@context id) as d2) they can update')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->alias?->name)->toBe('d2');
});

it('parses the alias between the row selector and the with-map', function () {
    $rules = WarrantSyntax::parse(
        'if can(view for docs(@context id) as d2 with tenant = 7) they can update',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node->alias?->name)->toBe('d2');
    expect($node->contextMap)->toEqualSyntax(withEntries(['tenant' => 7]));
    expect(handleToString($node))->toBe('docs(@context id) as d2 with tenant = 7');
});

it('leaves the alias null when the handle has no as tail', function () {
    $rules = WarrantSyntax::parse('if can(view for docs(@context id)) they can update')->validateWithoutSchemas()->ruleEntries();

    expect($rules[0]->conditions->alias)->toBeNull();
});

it('parses an alias on an unbound handle, leaving it for validation to reject', function () {
    /* The parser's job is the shape; whether an alias makes sense on a handle
       that selects no row is a coherence question, judged with the rest of them
       in RuleSetValidator. */
    $node = WarrantSyntax::parse('if can(view for docs as d2) they can update')->children[0]->conditions;
    expect($node->isRowBound)->toBeFalse();
    expect($node->alias?->name)->toBe('d2');
});

it('errors on an as with no alias name, and on a reserved word as one', function (string $syntax, string $needle) {
    expect(fn () => WarrantSyntax::parse($syntax)->validateWithoutSchemas()->ruleEntries())
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'nothing after as' => [
        'if can(view for docs(@context id) as) they can update',
        "Expected an alias name after 'as'",
    ],
    'reserved word as an alias' => [
        'if can(view for docs(@context id) as with) they can update',
        "Reserved word 'with' cannot be used as a name",
    ],
]);

// -- SQL references (@sql) ----------------------------------------------------

it('parses @sql "<sql>" into a symbolic SqlRef, not a value', function () {
    $set = WarrantSyntax::parse('if is_teacher(@sql "select 1") they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    $params = argumentValues($set->entries[0]->conditions->parameters);
    expect($params)->toHaveCount(1);
    expect($params[0])->toBeInstanceOf(SqlRef::class);
    expect($params[0]->sql?->value)->toBe('select 1');
});

it('accepts single- or double-quoted @sql bodies, keeping the inner quote', function () {
    $double = WarrantSyntax::parse('if is_teacher(@sql "id = \'blah\'") they can view')->validateWithoutSchemas()->scopedTo('timesheets');
    $single = WarrantSyntax::parse("if is_teacher(@sql 'name = \"x\"') they can view")->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($double->entries[0]->conditions->parameters)[0])->toEqualSyntax((new SqlRef(argumentNode("id = 'blah'"), SyntaxLocation::noProducer())));
    expect(argumentValues($single->entries[0]->conditions->parameters)[0])->toEqualSyntax((new SqlRef(argumentNode('name = "x"'), SyntaxLocation::noProducer())));
});

it('resolves a :name binding as the @sql body at parse time', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        'if is_teacher(@sql :q) they can view',
        ['q' => 'select 1'],
    ))->validateWithoutSchemas()->scopedTo('timesheets');

    expect(valueOf(argumentValues($set->entries[0]->conditions->parameters)[0]->sql))->toBe('select 1');
});

it('resolves a ? binding as the @sql body at parse time', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        'if is_teacher(@sql ?) they can view',
        ['select 2'],
    ))->validateWithoutSchemas()->scopedTo('timesheets');

    expect(valueOf(argumentValues($set->entries[0]->conditions->parameters)[0]->sql))->toBe('select 2');
});

it('rejects a @sql binding that resolves to a non-string', function () {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax(
        'if is_teacher(@sql :q) they can view',
        ['q' => 42],
    ))->validateWithoutSchemas()->scopedTo('timesheets'))->toThrow(RuleValidationException::class, 'must resolve to a string');
});

it('mixes a @sql ref with literals, column/context refs, and bindings in one condition', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        "if is_teacher('x', @sql \"select 1\", @column timesheets.id, @context year, :b) they can view",
        ['b' => 42],
    ))->validateWithoutSchemas()->scopedTo('timesheets');

    $params = argumentValues($set->entries[0]->conditions->parameters);
    expect($params[0])->toBe('x');
    expect($params[1])->toEqualSyntax(new SqlRef(argumentNode('select 1'), SyntaxLocation::noProducer()));
    expect($params[2])->toEqualSyntax(new ColumnRef(nameNode('timesheets'), nameNode('id'), SyntaxLocation::noProducer()));
    expect($params[3])->toEqualSyntax(new ContextRef(nameNode('year'), SyntaxLocation::noProducer()));
    expect($params[4])->toBe(42);
});

it('exempts a @sql ref from binding finalize (no "unused binding" error)', function () {
    // Like @context / @column, a bare @sql ref must not trip the all-bindings-used /
    // mixing checks — it carries no value at parse time and never consumes a `?`.
    $set = WarrantSyntax::parse('if is_teacher(@sql "select 1") they can view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->conditions->parameters[0])->toBeInstanceOf(SqlRef::class);
});

it('parses a @sql row selector in a can(...) handle', function () {
    $rules = WarrantSyntax::parse('if can(manage for departments(@sql "select id from d")) they can update')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect(argumentValues($node->boundKey))->toEqualSyntax(([new SqlRef(argumentNode('select id from d'), SyntaxLocation::noProducer())]));
});

it('parses a @sql row selector in a check(...) handle', function () {
    $rules = WarrantSyntax::parse(
        'if check(is_open for pay_periods(@sql "select id from p")) they can update',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect(argumentValues($node->boundKey))->toEqualSyntax(([new SqlRef(argumentNode('select id from p'), SyntaxLocation::noProducer())]));
});

it('parses a @sql value in a with-map', function () {
    $rules = WarrantSyntax::parse(
        'if can(create for billing_plans with plan_id = @sql "select id from plans") they can create',
    )->validateWithoutSchemas()->ruleEntries();

    expect($rules[0]->conditions->contextMap)->toEqualSyntax(withEntries(['plan_id' => new SqlRef(argumentNode('select id from plans'), SyntaxLocation::noProducer())]));
});

it('errors when @sql is not followed by a quoted string', function (string $syntax, string $needle) {
    expect(fn () => WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'missing string' => ['if is_teacher(@sql) they can view', 'Expected a quoted SQL string'],
    'identifier not string' => ['if is_teacher(@sql foo) they can view', 'Expected a quoted SQL string'],
]);

// -- Wildcards ----------------------------------------------------------------

it('parses wildcard abilities on can and cannot', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        if is_admin
        they can *

        if is_suspended
        they cannot *
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->canAbilities())->toBe(['*']);
    expect($set->entries[1]->cannotAbilities())->toBe(['*']);
});

// -- Identifiers --------------------------------------------------------------

it('allows dashes inside condition and ability names', function () {
    $set = WarrantSyntax::parse('if is-department-manager they can soft-delete')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->conditions->conditionKey?->name)->toBe('is-department-manager');
    expect($set->entries[0]->canAbilities())->toBe(['soft-delete']);
});

// -- Single-rule factory ------------------------------------------------------

it('parses a single unconditional rule', function () {
    $rule = WarrantSyntax::parse('they cannot publish')->validateWithoutSchemas()->rule();

    expect($rule->conditions)->toBeNull();
    expect($rule->cannotAbilities())->toBe(['publish']);
});

it('parses a single conditional rule with a binding', function () {
    $rule = WarrantSyntax::parse(new BoundSyntax('if some_condition(:p) they can edit', ['p' => 'v']))->validateWithoutSchemas()->rule();

    expect(argumentValues($rule->conditions->parameters))->toEqualSyntax((['v']));
    expect($rule->canAbilities())->toBe(['edit']);
});

it('refuses to answer a single rule for source holding several', function () {
    expect(fn () => WarrantSyntax::parse('if a they can x if b they can y')->validateWithoutSchemas()->rule())
        ->toThrow(LogicException::class, 'Expected a single rule, but the source holds 2 unscoped rule entries.');
});

// -- Denial messages (because) ------------------------------------------------

it('parses a string denial message after a cannot clause', function () {
    $rule = WarrantSyntax::parse(<<<'WARRANT'
        if is_locked
        they cannot edit because 'This row is locked.'
        WARRANT)->validateWithoutSchemas()->rule();

    expect($rule->cannotAbilities())->toBe(['edit']);
    expect($rule->messageFor('edit'))->toBe('This row is locked.');
});

it('unescapes quotes in a denial message', function () {
    $rule = WarrantSyntax::parse("they cannot edit because 'can\\'t touch this'")->validateWithoutSchemas()->rule();

    expect($rule->messageFor('edit'))->toBe("can't touch this");
});

it('leaves the message null when no because clause is present', function () {
    $rule = WarrantSyntax::parse('they cannot edit')->validateWithoutSchemas()->rule();

    expect($rule->messageFor('edit'))->toBeNull();
});

it('resolves a named binding message to a string', function () {
    $rule = WarrantSyntax::parse(new BoundSyntax('they cannot edit because :msg', ['msg' => 'locked']))->validateWithoutSchemas()->rule();

    expect($rule->messageFor('edit'))->toBe('locked');
});

it('resolves a positional binding message to a string', function () {
    $rule = WarrantSyntax::parse(new BoundSyntax('they cannot edit because ?', ['locked']))->validateWithoutSchemas()->rule();

    expect($rule->messageFor('edit'))->toBe('locked');
});

it('accepts a closure resolved from a binding as the message', function () {
    $closure = fn () => 'dynamic';
    $rule = WarrantSyntax::parse(new BoundSyntax('they cannot edit because :msg', ['msg' => $closure]))->validateWithoutSchemas()->rule();

    expect($rule->messageFor('edit'))->toBe($closure);
});

it('keeps a can clause and a message-bearing cannot in one rule', function () {
    $rule = WarrantSyntax::parse(<<<'WARRANT'
        they can view
        they cannot edit because 'locked'
        WARRANT)->validateWithoutSchemas()->rule();

    expect($rule->canAbilities())->toBe(['view']);
    expect($rule->cannotAbilities())->toBe(['edit']);
    expect($rule->messageFor('edit'))->toBe('locked');
});

it('gives each cannot clause its own message on a single rule', function () {
    $rule = WarrantSyntax::parse(
        "if is_locked they cannot update because 'no update' they cannot delete because 'no delete'",
    )->validateWithoutSchemas()->rule();

    expect($rule->cannotAbilities())->toBe(['update', 'delete']);
    expect($rule->cannotClauses)->toHaveCount(2);
    expect($rule->messageFor('update'))->toBe('no update');
    expect($rule->messageFor('delete'))->toBe('no delete');
});

it('mixes message-less and message-bearing cannot clauses on one rule', function () {
    $rule = WarrantSyntax::parse("they cannot archive they cannot edit because 'locked'")->validateWithoutSchemas()->rule();

    expect($rule->cannotAbilities())->toBe(['archive', 'edit']);
    expect($rule->messageFor('archive'))->toBeNull();
    expect($rule->messageFor('edit'))->toBe('locked');
});

it('groups multiple abilities in one cannot clause under one message', function () {
    $rule = WarrantSyntax::parse("they cannot update, delete because 'locked'")->validateWithoutSchemas()->rule();

    expect($rule->cannotClauses)->toHaveCount(1);
    expect($rule->cannotClauses[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['update', 'delete']));
    expect($rule->messageFor('update'))->toBe('locked');
    expect($rule->messageFor('delete'))->toBe('locked');
});

it('rejects because after a can clause, an empty one too', function (string $syntax) {
    expect(languageRuleErrors($syntax))
        ->toBe(["'because' may only follow a 'they cannot ...' clause, not 'they can ...'."]);
})->with([
    'naming abilities' => ["they can view because 'nope'"],
    'naming none' => ["they can because 'nope'"],
]);

it('rejects a denial message that is not text', function (string $syntax) {
    expect(languageRuleErrors($syntax))->toBe([LanguageRuleMessages::messageIsNotText()]);
})->with([
    'a number' => ['they cannot edit because 42'],
    'a @context' => ['they cannot edit because @context reason'],
]);

it('reads a because with nothing after it as a syntax error', function () {
    expect(fn () => WarrantSyntax::parse('they cannot edit because'))
        ->toThrow(WarrantSyntaxException::class, "Expected a denial message after 'because'");
});

it('rejects a binding that resolves to a non-string, non-closure message', function () {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax('they cannot edit because :msg', ['msg' => 42]))->validateWithoutSchemas()->rule())
        ->toThrow(RuleValidationException::class, 'must be a string or a closure');
});

it('rejects a bare because with no message', function () {
    expect(fn () => WarrantSyntax::parse('they cannot edit because')->validateWithoutSchemas()->rule())
        ->toThrow(WarrantSyntaxException::class, "Expected a denial message after 'because'");
});

// -- Invalid syntax -----------------------------------------------------------

it('rejects bindings that do not match their placeholders', function (string $syntax, array $bindings, string $needle) {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax($syntax, $bindings))->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(RuleValidationException::class, $needle);
})->with([
    'missing named binding' => ['if is_thing(:missing) they can view', [], 'No binding provided for ":missing"'],
    'unused binding' => ['if is_self they can view', ['unused' => 1], 'never used'],
    'too many positional placeholders' => ['if is_thing(?, ?) they can view', [1], 'More positional placeholders'],
    'unused positional binding' => ['if is_thing(?) they can view', [1, 2], 'never used'],
]);

it('throws on invalid syntax', function (string $syntax, array $bindings, string $needle) {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax($syntax, $bindings))->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, $needle);
})->with([
    'bare if' => ['if is_self', [], "Expected at least one 'they can"],
    'reserved word as ability' => ['they can can', [], "Reserved word 'can' cannot be used"],
    'reserved word as condition' => ['if if they can view', [], "Reserved word 'if' cannot be used"],
    // `as` became a keyword for the cross-schema handle alias, so it stopped
    // being available as a condition, ability or schema name.
    'as reserved as condition' => ['if as they can view', [], "Reserved word 'as' cannot be used"],
    'as reserved as ability' => ['they can as', [], "Reserved word 'as' cannot be used"],
    'unterminated string' => ["if is_thing('oops) they can view", [], 'Unterminated string'],
    'unterminated double-quoted string' => ['if is_thing("oops) they can view', [], 'Unterminated string'],
    'invalid escape sequence' => ["if is_thing('a\\nb') they can view", [], 'Invalid escape sequence'],
    'unbalanced parens' => ['if (is_self they can view', [], "Expected ')'"],
    'they without can/cannot' => ['they view', [], "Expected 'can' or 'cannot'"],
    'trailing junk' => ['if is_self they can edit garbage', [], 'end of input'],
]);

it('reports the position of a syntax error', function () {
    try {
        WarrantSyntax::parse('if is_self they can can')->validateWithoutSchemas()->scopedTo('timesheets');
        $this->fail('Expected a WarrantSyntaxException.');
    } catch (WarrantSyntaxException $e) {
        expect($e->sourceLine)->toBe(1);
        expect($e->sourceColumn)->toBe(21); // the second `can` in "if is_self they can can"
        expect($e->getMessage())->toContain('line 1, column 21');
    }
});

// -- Comments -----------------------------------------------------------------

it('ignores a full-line comment', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        # only the owner may touch their own timesheet
        if is_self
        they can edit
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries)->toHaveCount(1);
    expect($set->entries[0]->conditions->conditionKey?->name)->toBe('is_self');
    expect($set->entries[0]->canAbilities())->toBe(['edit']);
});

it('ignores a trailing comment on a line', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        if is_self   # the author
        they can edit   # but see the cannot below
        they cannot approve
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->conditions->conditionKey?->name)->toBe('is_self');
    expect($set->entries[0]->canAbilities())->toBe(['edit']);
    expect($set->entries[0]->cannotAbilities())->toBe(['approve']);
});

it('ignores a comment with no trailing newline at end of source', function () {
    $rules = WarrantSyntax::parse('if is_self they can edit # trailing')->validateWithoutSchemas()->ruleEntries();

    expect($rules)->toHaveCount(1);
    expect($rules[0]->canAbilities())->toBe(['edit']);
});

it('does not let a comment merge the tokens on either side of it', function () {
    $rules = WarrantSyntax::parse("if is_self they can # comment\n edit")->validateWithoutSchemas()->ruleEntries();

    expect($rules[0]->canAbilities())->toBe(['edit']);
});

it('keeps a "#" inside a string literal as a literal character', function () {
    $rules = WarrantSyntax::parse("if is_thing('a#b') they can view")->validateWithoutSchemas()->ruleEntries();

    expect(argumentValues($rules[0]->conditions->parameters))->toEqualSyntax((['a#b']));
});

// -- Cross-schema can(...) -----------------------------------------------------

it('parses an unbound can(...) handle (capability schema, no row)', function () {
    $rules = WarrantSyntax::parse('if can(access_payroll for payroll_admin) they can view')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->schemaKey?->name)->toBe('payroll_admin');
    expect($node->ability?->name)->toBe('access_payroll');
    expect($node->isRowBound)->toBeFalse();
    expect(argumentValues($node->boundKey))->toEqualSyntax(([]));
    expect($node->contextMap)->toEqualSyntax(withEntries([]));
    expect($rules[0]->canAbilities())->toBe(['view']);
});

it('parses a row-bound can(...) handle with a @context row selector', function () {
    $rules = WarrantSyntax::parse('if can(manage for departments(@context department_id)) they can update')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->schemaKey?->name)->toBe('departments');
    expect($node->ability?->name)->toBe('manage');
    expect($node->isRowBound)->toBeTrue();
    expect(argumentValues($node->boundKey))->toEqualSyntax(([new ContextRef(nameNode('department_id'), SyntaxLocation::noProducer())]));
});

it('parses a can(...) with a with-map of @context values', function () {
    $rules = WarrantSyntax::parse(
        'if can(create for billing_plans with as_of_date = @context d, plan_id = @context p) they can create',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node->schemaKey?->name)->toBe('billing_plans');
    expect($node->ability?->name)->toBe('create');
    expect($node->isRowBound)->toBeFalse();
    expect($node->contextMap)->toEqualSyntax(withEntries([
        'as_of_date' => new ContextRef(nameNode('d'), SyntaxLocation::noProducer()),
        'plan_id' => new ContextRef(nameNode('p'), SyntaxLocation::noProducer()),
    ]));
});

it('composes a can(...) leaf with a local condition via and', function () {
    $rules = WarrantSyntax::parse('if is_self and can(x for users(@context user_id)) they can create')->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(AndNode::class);
    expect($node->leftSide)->toBeInstanceOf(ConditionNode::class);
    expect($node->leftSide->conditionKey?->name)->toBe('is_self');
    expect($node->rightSide)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($node->rightSide->schemaKey?->name)->toBe('users');
});

it('resolves a named binding as the row selector', function () {
    $rules = WarrantSyntax::parse(new BoundSyntax(
        'if can(manage for departments(:dept)) they can update',
        ['dept' => 'dept-1'],
    ))->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node->isRowBound)->toBeTrue();
    expect(argumentValues($node->boundKey))->toEqualSyntax((['dept-1']));
});

it('resolves positional bindings across the row selector and with-map', function () {
    $rules = WarrantSyntax::parse(new BoundSyntax(
        'if can(manage for departments(?) with tenant = ?) they can update',
        ['dept-1', 'tenant-9'],
    ))->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect(argumentValues($node->boundKey))->toEqualSyntax((['dept-1']));
    expect(withEntryValues($node->contextMap))->toBe(['tenant' => 'tenant-9']);
});

it('keeps a null literal row selector distinct from an unbound handle', function () {
    $node = WarrantSyntax::parse('if can(manage for departments(null)) they can update')->children[0]->conditions;
    expect($node->isRowBound)->toBeTrue();
    expect(argumentValues($node->boundKey))->toEqualSyntax(([null]));
});

it('throws when the ability is not followed by for', function () {
    expect(fn () => WarrantSyntax::parse('if can(manage departments) they can view')->validateWithoutSchemas()->ruleEntries())
        ->toThrow(WarrantSyntaxException::class);
});

it('rejects a reserved word (for) used as a bare condition name', function () {
    expect(fn () => WarrantSyntax::parse('if for they can view')->validateWithoutSchemas()->ruleEntries())
        ->toThrow(WarrantSyntaxException::class);
});

it('rejects a duplicate key in a with-map', function () {
    expect(fn () => WarrantSyntax::parse('if can(create for s with a = 1, a = 2) they can view')->validateWithoutSchemas()->ruleEntries())
        ->toThrow(RuleValidationException::class, "Duplicate key 'a'");
});

// -- Cross-schema check(...) ---------------------------------------------------

it('parses an unbound check(...) handle with a global condition', function () {
    $rules = WarrantSyntax::parse("if check(is_open('maintenance') for tenant_settings) they cannot update")->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->schemaKey?->name)->toBe('tenant_settings');
    expect($node->isRowBound)->toBeFalse();
    expect(argumentValues($node->boundKey))->toEqualSyntax(([]));
    expect($node->contextMap)->toEqualSyntax(withEntries([]));
    expect($node->predicate)->toBeInstanceOf(ConditionNode::class);
    expect($node->predicate->conditionKey?->name)->toBe('is_open');
    expect(argumentValues($node->predicate->parameters))->toEqualSyntax((['maintenance']));
    expect($rules[0]->cannotAbilities())->toBe(['update']);
});

it('parses a row-bound check(...) handle with a @context row selector', function () {
    $rules = WarrantSyntax::parse(
        'if check(is_payroll_published_for_user(@context user_id) for pay_periods(@context id)) they cannot update',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->schemaKey?->name)->toBe('pay_periods');
    expect($node->isRowBound)->toBeTrue();
    expect(argumentValues($node->boundKey))->toEqualSyntax(([new ContextRef(nameNode('id'), SyntaxLocation::noProducer())]));
    expect($node->predicate->conditionKey?->name)->toBe('is_payroll_published_for_user');
    expect(argumentValues($node->predicate->parameters))->toEqualSyntax(([new ContextRef(nameNode('user_id'), SyntaxLocation::noProducer())]));
});

it('parses a complex boolean predicate of conditions inside check(...)', function () {
    $rules = WarrantSyntax::parse(
        'if check(is_published or (needs_review and not is_locked) for pay_periods(@context id)) they can approve',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->schemaKey?->name)->toBe('pay_periods');

    // is_published OR (needs_review AND NOT is_locked)
    $predicate = $node->predicate;
    expect($predicate)->toBeInstanceOf(OrNode::class);
    expect($predicate->leftSide)->toBeInstanceOf(ConditionNode::class);
    expect($predicate->leftSide->conditionKey?->name)->toBe('is_published');
    expect($predicate->rightSide)->toBeInstanceOf(AndNode::class);
    expect($predicate->rightSide->leftSide->conditionKey?->name)->toBe('needs_review');
    expect($predicate->rightSide->rightSide)->toBeInstanceOf(NotNode::class);
    expect($predicate->rightSide->rightSide->operand->conditionKey?->name)->toBe('is_locked');
});

it('parses a check(...) with a with-map of @context values', function () {
    $rules = WarrantSyntax::parse(
        'if check(is_published(@context user_id) for pay_periods(@context id) with user_id = @context user_id) they can create',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node->schemaKey?->name)->toBe('pay_periods');
    expect($node->isRowBound)->toBeTrue();
    expect($node->contextMap)->toEqualSyntax(withEntries(['user_id' => new ContextRef(nameNode('user_id'), SyntaxLocation::noProducer())]));
});

it('composes a check(...) leaf with a local condition, negated', function () {
    $rules = WarrantSyntax::parse(
        'if is_manager and not check(is_locked for pay_periods(@context id)) they can update',
    )->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect($node)->toBeInstanceOf(AndNode::class);
    expect($node->leftSide->conditionKey?->name)->toBe('is_manager');
    expect($node->rightSide)->toBeInstanceOf(NotNode::class);
    expect($node->rightSide->operand)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->rightSide->operand->schemaKey?->name)->toBe('pay_periods');
});

it('resolves positional bindings across a check(...) predicate arg, row selector and with-map', function () {
    $rules = WarrantSyntax::parse(new BoundSyntax(
        'if check(is_open(?) for pay_periods(?) with tenant = ?) they can view',
        ['maintenance', 'pp-1', 'tenant-9'],
    ))->validateWithoutSchemas()->ruleEntries();

    $node = $rules[0]->conditions;
    expect(argumentValues($node->predicate->parameters))->toEqualSyntax((['maintenance']));
    expect(argumentValues($node->boundKey))->toEqualSyntax((['pp-1']));
    expect(withEntryValues($node->contextMap))->toBe(['tenant' => 'tenant-9']);
});

it('throws when the check(...) predicate is not followed by for', function () {
    expect(fn () => WarrantSyntax::parse('if check(is_open pay_periods) they can view')->validateWithoutSchemas()->ruleEntries())
        ->toThrow(WarrantSyntaxException::class);
});

it('throws on an empty check(...) predicate', function () {
    expect(fn () => WarrantSyntax::parse('if check(for pay_periods) they can view')->validateWithoutSchemas()->ruleEntries())
        ->toThrow(WarrantSyntaxException::class);
});

it('rejects a duplicate key in a check(...) with-map', function () {
    expect(fn () => WarrantSyntax::parse('if check(is_open for s with a = 1, a = 2) they can view')->validateWithoutSchemas()->ruleEntries())
        ->toThrow(RuleValidationException::class, "Duplicate key 'a'");
});

// -- condition expressions ----------------------------------------------------
//
// The entry point behind the builder's ifRaw() bridge and Build::condition().
// It parses the `if` half of a rule and nothing else, which gives it four rules
// of its own on top of the shared expression grammar: no clauses may follow, the
// source must be consumed to the end, every binding must be used, and an optional
// `for <schema>` header is accepted and discarded (it is there so tooling reading
// a condition string knows which schema's names to resolve, which is the one
// thing an expression cannot say for itself).

it('parses an expression through the condition entry point', function (string $source, string $expected) {
    expect(treeToString(WarrantSyntax::parse($source)->validateWithoutSchemas()->conditionExpression()))->toBe($expected);
})->with([
    'single condition' => ['is_owner', 'is_owner'],
    'and binds tighter than or' => ['a and b or c', '((a and b) or c)'],
    'and binds tighter than or, reversed' => ['a or b and c', '(a or (b and c))'],
    'grouping overrides precedence' => ['(a or b) and c', '((a or b) and c)'],
    'and is left associative' => ['a and b and c', '((a and b) and c)'],
    'or is left associative' => ['a or b or c', '((a or b) or c)'],
    'not binds tighter than and' => ['not a and b', '(!a and b)'],
    'not over a group' => ['not (a and b)', '!(a and b)'],
    'double negation is kept' => ['not not a', '!!a'],
    'redundant parens collapse' => ['((a))', 'a'],
]);

it('parses condition arguments through the condition entry point', function (string $source, string $expected) {
    expect(treeToString(WarrantSyntax::parse($source)->validateWithoutSchemas()->conditionExpression()))->toBe($expected);
})->with([
    'string and int' => ["is_owner('x-1', 3)", "is_owner('x-1',3)"],
    'bool and null' => ['is_owner(true, null)', 'is_owner(true,NULL)'],
    'context ref' => ['is_owner(@context id)', 'is_owner(@context id)'],
    'column ref' => ['is_owner(@column docs.owner)', 'is_owner(@column docs.owner)'],
    'sql ref' => ['is_owner(@sql "select 1")', 'is_owner(@sql select 1)'],
]);

it('parses a cross-schema can(...) leaf as a bare expression', function () {
    $unbound = WarrantSyntax::parse('can(view for other)')->validateWithoutSchemas()->conditionExpression();

    expect($unbound)->toBeInstanceOf(CrossSchemaCanNode::class);
    expect($unbound->ability?->name)->toBe('view');
    expect($unbound->schemaKey?->name)->toBe('other');
    expect($unbound->isRowBound)->toBeFalse();

    $bound = WarrantSyntax::parse('can(view for other(@context id) with t = @context x)')->validateWithoutSchemas()->conditionExpression();

    expect($bound->isRowBound)->toBeTrue();
    expect($bound->boundKey[0])->toBeInstanceOf(ContextRef::class);
    expect($bound->contextMap[0]->key->name)->toBe('t');
});

it('parses a cross-schema check(...) leaf, predicate and all', function () {
    $node = WarrantSyntax::parse('check(a or b for other(@context id))')->validateWithoutSchemas()->conditionExpression();

    expect($node)->toBeInstanceOf(CrossSchemaConditionNode::class);
    expect($node->schemaKey?->name)->toBe('other');
    expect($node->isRowBound)->toBeTrue();
    expect(treeToString($node->predicate))->toBe('(a or b)');
});

it('resolves named and positional bindings in a condition expression', function () {
    expect(argumentValues(WarrantSyntax::parse(new BoundSyntax('is_owner(:id)', ['id' => 'x-1']))->validateWithoutSchemas()->conditionExpression()->parameters))->toBe(['x-1']);
    expect(argumentValues(WarrantSyntax::parse(new BoundSyntax('is_owner(?)', ['p-1']))->validateWithoutSchemas()->conditionExpression()->parameters))->toBe(['p-1']);
});

// -- what may not follow the expression ---------------------------------------

it('rejects anything after a condition expression', function (string $source) {
    expect(fn () => WarrantSyntax::parse($source)->validateWithoutSchemas()->conditionExpression())
        ->toThrow(WarrantSyntaxException::class);
})->with([
    // An expression is the `if` half alone, so a clause after it is a rule
    // written without its `if`.
    'a they-can clause' => ['is_owner they can view'],
    'a they-cannot clause' => ['is_owner they cannot view'],
    'a second condition' => ['is_owner is_admin'],
    'a stray closing paren' => ['is_owner)'],
    'an unclosed group' => ['(is_owner or is_admin'],
    'a braced body' => ['{ is_owner }'],
]);

it('rejects a condition expression that is empty or incomplete', function (string $source) {
    expect(fn () => WarrantSyntax::parse($source)->validateWithoutSchemas()->conditionExpression())
        ->toThrow(WarrantSyntaxException::class);
})->with([
    'a bare not' => ['not'],
    'a dangling and' => ['a and'],
    'a dangling or' => ['a or'],
]);

it('has no condition expression to answer for an empty source', function (string $source) {
    expect(fn () => WarrantSyntax::parse($source)->validateWithoutSchemas()->conditionExpression())
        ->toThrow(LogicException::class, 'Expected a condition expression, but the source holds nothing.');
})->with([
    'empty' => [''],
    'whitespace only' => ['   '],
]);

it('rejects bindings the condition expression never used', function () {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax('is_owner', ['id' => 'x-1']))->validateWithoutSchemas()->conditionExpression())
        ->toThrow(RuleValidationException::class, 'never used');
});

it('rejects a condition expression that mixes named and positional bindings, validating it before resolving', function () {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax('is_owner(:a, ?)', ['a' => 1, 'p']))->validateWithoutSchemas()->conditionExpression())
        ->toThrow(RuleValidationException::class, 'Cannot mix named and positional bindings.');
});

it('rejects rules that mix named and positional bindings, validating them before resolving', function () {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax('if is_thing(:a, ?) they can view', ['a' => 1]))->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(RuleValidationException::class, 'Cannot mix named and positional bindings.');
});

// -- the optional `for` header ------------------------------------------------

it('parses a bare condition expression, with or without a for header', function () {
    /* The header scopes the names to a schema and changes nothing about the
       expression, so the two forms answer the same tree. */
    expect(WarrantSyntax::parse('for timesheets is_owner or is_admin')->validateWithoutSchemas()->conditionExpression())
        ->toEqualSyntax(WarrantSyntax::parse('is_owner or is_admin')->validateWithoutSchemas()->conditionExpression());
});

it('keeps the schema a for header names on the condition', function () {
    $syntax = WarrantSyntax::parse('for timesheets is_owner or is_admin');

    expect($syntax->isSchemaCondition())->toBeTrue();
    expect($syntax->children[0])->toBeInstanceOf(SchemaConditionNode::class);
    expect($syntax->children[0]->schemaKey?->name)->toBe('timesheets');
});

it('parses a braced condition after a for header', function () {
    expect(WarrantSyntax::parse('for timesheets { is_owner or is_admin }')->validateWithoutSchemas()->conditionExpression())
        ->toEqualSyntax(WarrantSyntax::parse('is_owner or is_admin')->validateWithoutSchemas()->conditionExpression());
});

it('accepts a for header ahead of any expression form', function (string $source, string $expected) {
    expect(treeToString(WarrantSyntax::parse($source)->validateWithoutSchemas()->conditionExpression()))->toBe($expected);
})->with([
    'a single condition' => ['for timesheets is_owner', 'is_owner'],
    'a negation' => ['for timesheets not is_owner', '!is_owner'],
    'a group' => ['for timesheets (a or b)', '(a or b)'],
    'a can(...) leaf' => ['for timesheets can(view for other)', 'can(view for other)'],
]);

it('resolves bindings in a condition expression behind a for header', function () {
    $node = WarrantSyntax::parse(new BoundSyntax('for timesheets is_owner(:id)', ['id' => 'x-1']))->validateWithoutSchemas()->conditionExpression();

    expect(argumentValues($node->parameters))->toEqualSyntax((['x-1']));
});

it('rejects a malformed or misplaced for header', function (string $source) {
    expect(fn () => WarrantSyntax::parse($source)->validateWithoutSchemas()->conditionExpression())
        ->toThrow(WarrantSyntaxException::class);
})->with([
    'no schema name' => ['for is_owner or is_admin'],
    'a doubled header' => ['for timesheets for documents is_owner'],
    'a header mid-expression' => ['is_owner or for timesheets is_admin'],
]);

it('reads a header with nothing after it as an empty rule set, not a condition', function () {
    expect(WarrantSyntax::parse('for timesheets')->validateWithoutSchemas()->ruleSet()->entries)->toBe([]);

    expect(fn () => WarrantSyntax::parse('for timesheets')->validateWithoutSchemas()->conditionExpression())
        ->toThrow(LogicException::class, 'but the source holds a rule set for [timesheets]');
});

// -- Ability blocks -----------------------------------------------------------

/**
 * The rules a rule set's ability blocks stand for. The parser keeps a block as
 * written; expansion is what applies its header.
 *
 * @return list<WarrantRuleNode>
 */
function openBlocks(RuleSetNode $set): array
{
    return expandedFor($set, new EveryNameSchema)->rules;
}

/**
 * The test schema, declaring every ability and condition a rule names besides
 * its own, so the block tests can name whatever reads best.
 */
final class EveryNameSchema extends WarrantTestSchema
{
    public function getAbilityDefinition(string $abilityKey): ?AbilityDefinition
    {
        return parent::getAbilityDefinition($abilityKey) ?? new AbilityDefinition($abilityKey);
    }

    public function getConditionDefinition(string $conditionKey): ?ConditionDefinition
    {
        return parent::getConditionDefinition($conditionKey)
            ?? new ConditionDefinition($conditionKey, $conditionKey, ConditionKind::Global);
    }
}

it('parses an ability block into the rules its longhand would produce', function () {
    $block = WarrantSyntax::parse(<<<'WARRANT'
        can they view {
            if is_public they can
            if is_locked they cannot because 'Locked.'
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    $longhand = WarrantSyntax::parse(<<<'WARRANT'
        if is_public they can view
        if is_locked they cannot view because 'Locked.'
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($block))->toHaveCount(2);

    foreach (openBlocks($block) as $index => $rule) {
        expect($rule->canAbilities())->toBe($longhand->entries[$index]->canAbilities());
        expect($rule->cannotAbilities())->toBe($longhand->entries[$index]->cannotAbilities());
        expect($rule->conditions->conditionKey?->name)->toBe($longhand->entries[$index]->conditions->conditionKey->name);
    }

    expect(openBlocks($block)[1]->messageFor('view'))->toBe('Locked.');
});

it('gives every clause of a block the whole header ability list', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they edit, delete {
            if is_owner they can
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($set)[0]->canAbilities())->toBe(['edit', 'delete']);
});

it('accepts a wildcard block header', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they * {
            if is_admin they can
            if is_suspended they cannot because 'Suspended.'
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($set)[0]->canAbilities())->toBe(['*']);
    expect(openBlocks($set)[1]->cannotAbilities())->toBe(['*']);
});

it('carries several clauses of one block rule onto the same rule', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view {
            if is_self
            they can
            they cannot because 'Not yours.'
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($set))->toHaveCount(1);
    expect(openBlocks($set)[0]->canAbilities())->toBe(['view']);
    expect(openBlocks($set)[0]->cannotAbilities())->toBe(['view']);
});

it('mixes plain rules and ability blocks in one rule set', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        if is_admin they can *

        can they view {
            if is_public they can
        }

        if is_archived they cannot edit because 'Archived.'
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($set))->toHaveCount(3);
    expect(openBlocks($set)[0]->canAbilities())->toBe(['*']);
    expect(openBlocks($set)[1]->canAbilities())->toBe(['view']);
    expect(openBlocks($set)[2]->cannotAbilities())->toBe(['edit']);
});

it('allows an unconditional rule after an ability block', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view {
            if is_public they can
        }

        they can list
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($set))->toHaveCount(2);
    expect(openBlocks($set)[1]->conditions)->toBeNull();
    expect(openBlocks($set)[1]->canAbilities())->toBe(['list']);
});

it('parses an ability block inside a for block and in a group', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        for timesheets {
            can they view {
                if is_self they can
            }
        }
        WARRANT)->validateWithoutSchemas()->ruleSet();

    expect($set->schemaKey?->name)->toBe('timesheets');
    expect(openBlocks($set)[0]->canAbilities())->toBe(['view']);
});

it('accepts an empty ability block, as a for block does', function () {
    $set = WarrantSyntax::parse('can they view { }')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($set))->toBe([]);
});

it('allows the same ability in more than one block header', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view, edit {
            if is_owner they can
        }

        can they view {
            if is_public they can
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(openBlocks($set))->toHaveCount(2);
    expect(openBlocks($set)[0]->canAbilities())->toBe(['view', 'edit']);
    expect(openBlocks($set)[1]->canAbilities())->toBe(['view']);
});

it('rejects a clause inside an ability block naming its own abilities', function () {
    expect(languageRuleErrors('can they view { if x they can edit }'))
        ->toBe(['A clause inside an ability block may not name abilities; the block header already names them.']);
});

it('rejects a wildcard clause inside an ability block', function () {
    expect(languageRuleErrors('can they view { if x they cannot * }'))
        ->toBe([LanguageRuleMessages::namedInsideBlock('A clause')]);
});

it('rejects a nested ability block', function () {
    expect(languageRuleErrors(<<<'WARRANT'
        can they view {
            can they edit {
                if is_owner they can
            }
        }
        WARRANT))
        ->toBe(['An ability block may not contain another; the enclosing block already names the abilities.']);
});

it('rejects a nested block opened after a clause', function () {
    expect(languageRuleErrors(<<<'WARRANT'
        can they view {
            if is_public they can
            can they edit { if is_owner they can }
        }
        WARRANT))
        ->toBe([LanguageRuleMessages::blockInsideBlock()]);
});

it('refuses to answer a single rule for an ability block', function () {
    expect(fn () => WarrantSyntax::parse('can they view { if is_public they can }')->validateWithoutSchemas()->rule())
        ->toThrow(LogicException::class, 'Expected a single rule, but the source holds an ability block.');
});

it('rejects an ability list used as a block header without `can they`', function () {
    // The syntax ability blocks shipped with. Naming the header is the one useful
    // thing to say here; 'expected end of input' points at the wrong token.
    expect(fn () => WarrantSyntax::parse('view { if is_public they can }')->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, 'An ability block header is written `can they <ability>, ... { ... }`.');
});

it('rejects a bare header list that never reaches its brace', function () {
    expect(fn () => WarrantSyntax::parse('edit, delete { if is_owner they can }')->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, 'An ability block header is written `can they <ability>, ... { ... }`.');
});

it('rejects a block header naming no ability', function () {
    expect(languageRuleErrors('can they { if is_public they can }'))
        ->toBe(['An ability block names at least one ability.']);
});

it('reads a generic clause in rules with no for header', function () {
    $rule = WarrantSyntax::parse(new BoundSyntax('if is_public they can  if is_locked they cannot because :why', ['why' => 'No.']))->validateWithoutSchemas()->ruleEntries();

    expect($rule)->toHaveCount(2);
    expect($rule[0]->isGeneric())->toBeTrue();
    expect($rule[1]->cannotClauses[0]->abilities)->toEqualSyntax(array_map(nameNode(...), []));
    expect(valueOf($rule[1]->cannotClauses[0]->message))->toBe('No.');
});

it('rejects a generic clause after rules that name their abilities', function () {
    expect(languageRuleErrors('if is_public they can view  if is_locked they cannot'))->toBe([
        'This clause names none, but the rules before it name theirs; rules with no `for` header either all '
            .'name their abilities or all leave them to be named where the rules are placed.',
    ]);
});

it('rejects a named clause after generic rules', function () {
    expect(languageRuleErrors('if is_public they can  if is_locked they cannot view'))
        ->toBe([LanguageRuleMessages::namingDisagrees('This clause', true)]);
});

it('rejects a rule mixing named and generic clauses', function () {
    expect(languageRuleErrors('if is_public they can view they cannot'))
        ->toBe([LanguageRuleMessages::namingDisagrees('This clause', false)]);
});

it('rejects an ability block after generic rules', function () {
    expect(languageRuleErrors('if is_public they can  can they view { they can }'))
        ->toBe([LanguageRuleMessages::namingDisagrees('An ability block', true)]);
});

it('reads an ability block among rules that name their abilities', function () {
    $entries = WarrantSyntax::parse('if is_public they can view  can they edit { they can }  @include x for view')
        ->validateWithoutSchemas()
        ->ruleEntries();

    expect($entries)->toHaveCount(3);
});

it('rejects a generic clause outside an ability block once it is placed in a rule set', function () {
    expect(fn () => validateRuleSet(WarrantSyntax::parse('if is_public they can')->validateWithoutSchemas()->scopedTo('timesheets')))
        ->toThrow(InvalidArgumentException::class, 'the rule set for [timesheets] holds one that names none');
});

it('rejects a generic clause in a for body', function () {
    expect(languageRuleErrors('for timesheets { if is_public they can }'))
        ->toBe([LanguageRuleMessages::unnamedOutsideBlock('timesheets')]);
});

// -- @include -----------------------------------------------------------------

it('sits generic in the ability block whose header names its abilities', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view, edit {
            @include requires_approval
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries)->toHaveCount(1);
    expect($set->entries[0])->toBeInstanceOf(AbilityBlockNode::class);
    expect($set->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['view', 'edit']));

    $include = $set->entries[0]->entries[0];

    expect($include)->toBeInstanceOf(IncludeInvocationNode::class);
    expect($include->templateKey?->name)->toBe('requires_approval');
    expect($include->abilities)->toEqualSyntax(array_map(nameNode(...), []));
    expect(argumentValues($include->arguments))->toEqualSyntax(([]));
});

it('names its own abilities with a for list outside a block', function () {
    $set = WarrantSyntax::parse('@include requires_approval for view, edit')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['view', 'edit']));
});

it('accepts a wildcard in an include for list', function () {
    $set = WarrantSyntax::parse('@include locked for *')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['*']));
});

it('parses include arguments as a condition\'s are', function () {
    $set = WarrantSyntax::parse(new BoundSyntax(
        "@include inherited('folder', :depth, @context tenant_id, @column parent_id) for view",
        ['depth' => 2],
    ))->validateWithoutSchemas()->scopedTo('timesheets');

    $arguments = argumentValues($set->entries[0]->arguments);

    expect($arguments[0])->toBe('folder');
    expect($arguments[1])->toBe(2);
    expect($arguments[2])->toBeInstanceOf(ContextRef::class);
    expect($arguments[3])->toBeInstanceOf(ColumnRef::class);
});

it('accepts an include with empty parentheses', function () {
    $set = WarrantSyntax::parse('@include locked() for view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->arguments))->toEqualSyntax(([]));
});

it('keeps an include in source order among the rules', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        if is_first they can view

        can they view {
            @include requires_approval
        }

        if is_last they can view
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries)->toHaveCount(3);
    expect($set->entries[0])->toBeInstanceOf(WarrantRuleNode::class);
    expect($set->entries[0]->conditions->conditionKey?->name)->toBe('is_first');
    expect($set->entries[1])->toBeInstanceOf(AbilityBlockNode::class);
    expect($set->entries[1]->entries[0])->toBeInstanceOf(IncludeInvocationNode::class);
    expect($set->entries[2]->conditions->conditionKey?->name)->toBe('is_last');
});

it('rejects a for list on an include inside an ability block', function () {
    expect(languageRuleErrors('can they view { @include x for edit }'))
        ->toBe(['An @include inside an ability block may not name abilities; the block header already names them.']);
});

it('reads a generic include in rules with no for header', function () {
    $entries = WarrantSyntax::parse('@include x(1)  if is_public they can')->validateWithoutSchemas()->ruleEntries();

    expect($entries[0]->isGeneric())->toBeTrue();
    expect(argumentValues($entries[0]->arguments))->toEqualSyntax(([1]));
    expect($entries[1]->isGeneric())->toBeTrue();
});

it('rejects a generic include after rules that name their abilities', function () {
    expect(languageRuleErrors('@include x for view  @include y'))
        ->toBe([LanguageRuleMessages::namingDisagrees('This @include', false)]);
});

it('rejects an include that names no abilities once it is placed in a rule set', function () {
    expect(fn () => validateRuleSet(WarrantSyntax::parse('@include x')->validateWithoutSchemas()->scopedTo('timesheets')))
        ->toThrow(InvalidArgumentException::class, 'the rule set for [timesheets] holds one that names none');
});

it('rejects an include that names no abilities in a for body', function () {
    expect(languageRuleErrors('for timesheets { @include x }'))
        ->toBe([LanguageRuleMessages::unnamedOutsideBlock('timesheets')]);
});

it('rejects an include with no template name', function () {
    expect(fn () => WarrantSyntax::parse('@include for view')->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, 'a rule template name');
});

it('refuses to answer a single rule for an include', function () {
    expect(fn () => WarrantSyntax::parse('@include x for view')->validateWithoutSchemas()->rule())
        ->toThrow(LogicException::class, 'Expected a single rule, but the source holds an @include.');
});

it('parses a generic include as an entry of its own', function () {
    $entries = WarrantSyntax::parse('@include x for view')->validateWithoutSchemas()->ruleEntries();

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toBeInstanceOf(IncludeInvocationNode::class);
    expect($entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['view']));
});

it('renders an include back out rather than what it expands to', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view, edit {
            @include requires_approval
        }

        @include inherited('folder', 2) for view
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    expect(writeSyntax($set))->toBe(<<<'TXT'
        for timesheets {
            can they view, edit {
                @include requires_approval
            }

            @include inherited('folder', 2) for view
        }
        TXT);
});


// -- @include: placement and carriers -----------------------------------------

it('parses several includes in one ability block', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view {
            @include requires_approval
            @include not_archived
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    $block = $set->entries[0];

    expect($block->abilities)->toEqualSyntax(array_map(nameNode(...), ['view']));
    expect($block->entries)->toHaveCount(2);
    expect($block->entries[0]->templateKey?->name)->toBe('requires_approval');
    expect($block->entries[1]->templateKey?->name)->toBe('not_archived');
});

it('keeps rules and includes interleaved inside one block', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        can they view {
            if is_teacher they can
            @include requires_approval
            if is_advisor they cannot because 'No.'
        }
        WARRANT)->validateWithoutSchemas()->scopedTo('timesheets');

    $body = $set->entries[0]->entries;

    expect($body[0])->toBeInstanceOf(WarrantRuleNode::class);
    expect($body[1])->toBeInstanceOf(IncludeInvocationNode::class);
    expect($body[2])->toBeInstanceOf(WarrantRuleNode::class);
});

it('takes a wildcard block header for an include', function () {
    $set = WarrantSyntax::parse('can they * { @include suspended }')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['*']));
    expect($set->entries[0]->entries[0])->toBeInstanceOf(IncludeInvocationNode::class);
});

it('parses an include inside a braced for block', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        for timesheets {
            @include requires_approval for view
        }
        WARRANT)->validateWithoutSchemas()->ruleSet();

    expect($set->schemaKey?->name)->toBe('timesheets');
    expect($set->entries[0])->toBeInstanceOf(IncludeInvocationNode::class);
});

it('carries includes per block through a group', function () {
    $group = WarrantSyntax::parse(<<<'WARRANT'
        for timesheets {
            can they view { @include requires_approval }
        }

        for warrant_test_models {
            @include other for publish
        }
        WARRANT)->validateWithoutSchemas();

    expect($group->ruleSets())->toHaveCount(2);
    expect($group->ruleSets()[0]->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['view']));
    expect($group->ruleSets()[0]->entries[0]->entries[0]->templateKey?->name)->toBe('requires_approval');
    expect($group->ruleSets()[1]->entries[0]->templateKey?->name)->toBe('other');
    expect($group->ruleSets()[1]->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['publish']));
});

it('accepts an include built directly, without going through the parser', function () {
    $set = new RuleSetNode(nameNode('timesheets'), [
        new IncludeInvocationNode(nameNode('requires_approval'), [], [nameNode('view')], SyntaxLocation::noProducer()),
        WarrantSyntax::parse('they can update')->validateWithoutSchemas()->rule(),
    ], SyntaxLocation::noProducer());

    expect($set->entries)->toHaveCount(2);
    expect($set->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['view']));
});

it('rejects an entry that is neither a rule, a block nor an include', function () {
    expect(fn () => new RuleSetNode(nameNode('timesheets'), ['not an entry'], SyntaxLocation::noProducer()))
        ->toThrow(InvalidArgumentException::class, 'A rule set holds rules, ability blocks and includes, got string.');
});

// -- @include: arguments ------------------------------------------------------

it('resolves positional bindings in include arguments', function () {
    $set = WarrantSyntax::parse(new BoundSyntax('@include inherited(?, ?) for view', ['folder', 3]))->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->arguments))->toEqualSyntax((['folder', 3]));
});

it('takes every literal kind as an include argument', function () {
    $set = WarrantSyntax::parse("@include t('s', 4, 1.5, true, null) for view")->validateWithoutSchemas()->scopedTo('timesheets');

    expect(argumentValues($set->entries[0]->arguments))->toEqualSyntax((['s', 4, 1.5, true, null]));
});

it('takes a qualified column reference as an include argument', function () {
    $set = WarrantSyntax::parse('@include t(@column timesheets.parent_id) for view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect($set->entries[0]->arguments[0])->toBeInstanceOf(ColumnRef::class);
    expect($set->entries[0]->arguments[0]->alias?->name)->toBe('timesheets');
});

it('counts an unused binding against an include like any other', function () {
    expect(fn () => WarrantSyntax::parse(new BoundSyntax('@include t for view', ['unused' => 1]))->validateWithoutSchemas()->scopedTo('timesheets'))
        ->toThrow(RuleValidationException::class, 'never used');
});

it('rejects an include argument list that is never closed', function () {
    expect(fn () => WarrantSyntax::parse("@include t('a' for view")->scopedTo('timesheets'))
        ->toThrow(WarrantSyntaxException::class, "')' to close the @include arguments");
});

// -- @include: round-tripping -------------------------------------------------

it('round-trips an include through toSyntax', function () {
    $original = WarrantSyntax::parse(
        <<<WARRANT
            @include inherited('folder', 2) for view, update
        WARRANT,
    )->validateWithoutSchemas()->scopedTo('timesheets');

    $reparsed = WarrantSyntax::parse(writeSyntax($original))->validateWithoutSchemas()->ruleSet();

    expect($reparsed->entries[0]->templateKey?->name)->toBe('inherited');
    expect(argumentValues($reparsed->entries[0]->arguments))->toEqualSyntax((['folder', 2]));
    expect($reparsed->entries[0]->abilities)->toEqualSyntax(array_map(nameNode(...), ['view', 'update']));
});

it('round-trips an include through toBoundSyntax', function () {
    $original = WarrantSyntax::parse("@include inherited('folder', 2) for view")->validateWithoutSchemas()->scopedTo('timesheets');

    $bound = writeBoundSyntax($original);
    $reparsed = WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->ruleSet();

    expect($bound->bindings)->toBe(['folder', 2]);
    expect(argumentValues($reparsed->entries[0]->arguments))->toEqualSyntax((['folder', 2]));
});

it('renders an argument-less include with no parentheses', function () {
    $set = WarrantSyntax::parse('@include requires_approval for view')->validateWithoutSchemas()->scopedTo('timesheets');

    expect(writeSyntax($set))->toContain('@include requires_approval for view');
    expect(writeSyntax($set))->not->toContain('()');
});
