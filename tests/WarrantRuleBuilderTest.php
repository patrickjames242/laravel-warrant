<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\ConditionResolver;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\Builders\NoRow;
use Warrant\Builders\Ref;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ConditionDefinition;

require_once __DIR__.'/Support/TestSupport.php';

final class BuilderTestUser implements Authenticatable
{
    public function __construct(public ?string $role = null) {}

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->role; }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getAuthPassword(): ?string { return null; }
    public function getRememberToken(): ?string { return null; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): ?string { return null; }
}

final class BuilderFakeResolver implements ConditionResolver
{
    public static function schemaKey(): string { return 'builder-fake'; }
    public function getAbilityDefinition(string $name): ?AbilityDefinition { return $name === 'view' ? new AbilityDefinition($name) : null; }
    public function getConditionDefinition(string $name): ?ConditionDefinition { return $name === 'is_teacher' ? new ConditionDefinition($name, $name, true) : null; }

    public function applyCondition(string $name, Authenticatable $user, Builder $whereClause, ?string $targetSqlId, array $parameters, array $context = [], ?EloquentModel $targetModel = null): Builder|bool
    {
        return $whereClause->whereRaw("{$targetSqlId} = ?", ["teacher:{$user->role}"]);
    }
}

/**
 * Render an expression AST to a canonical, fully-parenthesized string so two
 * trees can be compared for structural equality.
 */
function treeToString(?object $node): string
{
    return match (true) {
        $node === null => 'null',
        $node instanceof ConditionNode => $node->conditionKey . ($node->parameters === []
            ? ''
            : '(' . implode(',', array_map(fn ($p) => argToString($p), $node->parameters)) . ')'),
        $node instanceof CrossSchemaCanNode => 'can(' . $node->ability . ' for ' . handleToString($node) . ')',
        $node instanceof CrossSchemaConditionNode => 'check(' . treeToString($node->predicate) . ' for ' . handleToString($node) . ')',
        $node instanceof NotNode => '!' . treeToString($node->operand),
        $node instanceof AndNode => '(' . treeToString($node->leftSide) . ' and ' . treeToString($node->rightSide) . ')',
        $node instanceof OrNode => '(' . treeToString($node->leftSide) . ' or ' . treeToString($node->rightSide) . ')',
        $node instanceof BooleanNode => $node->value ? 'true' : 'false',
        default => throw new RuntimeException('unexpected node ' . $node::class),
    };
}

/**
 * Render an argument value — a scalar, or one of the DSL's symbolic references,
 * which must stay distinguishable (a ContextRef would otherwise var_export to
 * NULL, making every ref look alike).
 */
function argToString(mixed $value): string
{
    return match (true) {
        $value instanceof ContextRef => '@context ' . $value->key,
        $value instanceof ColumnRef => '@column ' . $value->schemaKey . '.' . $value->column,
        $value instanceof SqlRef => '@sql ' . $value->sql,
        default => var_export($value, true),
    };
}

/**
 * Render a cross-schema handle: the schema, its row selector when the reference
 * is row-bound (an unbound handle has no parens at all — the distinction NoRow
 * exists to preserve), and any `with` map.
 */
function handleToString(CrossSchemaCanNode|CrossSchemaConditionNode $node): string
{
    $out = $node->schemaKey;

    if ($node->isRowBound) {
        $out .= '(' . argToString($node->boundRow) . ')';
    }

    if ($node->contextMap !== []) {
        $entries = [];

        foreach ($node->contextMap as $key => $value) {
            $entries[] = $key . ' = ' . argToString($value);
        }

        $out .= ' with ' . implode(', ', $entries);
    }

    return $out;
}

// -- structure ----------------------------------------------------------------

it('builds an unconditional rule (no if) with null conditions', function () {
    $rule = WarrantRule::build()->theyCan('view', 'update')->theyCannot('delete')->toRule();

    expect($rule->conditions)->toBeNull();
    expect($rule->canAbilities)->toBe(['view', 'update']);
    expect($rule->cannotAbilities())->toBe(['delete']);
});

it('attaches a denial message to a single ability via theyCannotBecause', function () {
    $rule = WarrantRule::build()->theyCannotBecause('delete', 'This record is locked.')->toRule();

    expect($rule->cannotAbilities())->toBe(['delete']);
    expect($rule->messageFor('delete'))->toBe('This record is locked.');
});

it('shares one message across several abilities in a theyCannotBecause array', function () {
    $rule = WarrantRule::build()->theyCannotBecause(['update', 'delete'], 'locked')->toRule();

    expect($rule->cannotClauses)->toHaveCount(1);
    expect($rule->cannotAbilities())->toBe(['update', 'delete']);
    expect($rule->messageFor('update'))->toBe('locked');
    expect($rule->messageFor('delete'))->toBe('locked');
});

it('gives each theyCannotBecause clause its own message', function () {
    $rule = WarrantRule::build()
        ->theyCannotBecause('update', 'no update')
        ->theyCannotBecause('delete', 'no delete')
        ->toRule();

    expect($rule->cannotClauses)->toHaveCount(2);
    expect($rule->messageFor('update'))->toBe('no update');
    expect($rule->messageFor('delete'))->toBe('no delete');
});

it('accepts a closure denial message via theyCannotBecause', function () {
    $closure = fn () => 'dynamic';
    $rule = WarrantRule::build()->theyCannotBecause('delete', $closure)->toRule();

    expect($rule->messageFor('delete'))->toBe($closure);
});

it('mixes message-less theyCannot and message-bearing theyCannotBecause', function () {
    $rule = WarrantRule::build()
        ->theyCannot('archive')
        ->theyCannotBecause('delete', 'locked')
        ->toRule();

    expect($rule->cannotAbilities())->toBe(['archive', 'delete']);
    expect($rule->messageFor('archive'))->toBeNull();
    expect($rule->messageFor('delete'))->toBe('locked');
});

it('carries condition parameters onto the node', function () {
    $rule = WarrantRule::build()->if('in_department', ['sales', 'eng'])->theyCan('view')->toRule();

    expect($rule->conditions)->toBeInstanceOf(ConditionNode::class);
    expect($rule->conditions->conditionKey)->toBe('in_department');
    expect($rule->conditions->parameters)->toBe(['sales', 'eng']);
});

it('wraps negated terms in a NotNode', function () {
    $rule = WarrantRule::build()->ifNot('is_locked')->theyCan('view')->toRule();

    expect($rule->conditions)->toBeInstanceOf(NotNode::class);
    expect($rule->conditions->operand->conditionKey)->toBe('is_locked');
});

it('rejects a rule with no they-can/they-cannot clause, matching the DSL', function () {
    expect(fn () => WarrantRule::build()->if('is_self')->toRule())
        ->toThrow(LogicException::class);
});

it('hands a group closure a bare condition builder with no clause methods', function () {
    $received = null;

    WarrantRule::build()
        ->if(function ($c) use (&$received) {
            $received = $c;
            $c->if('a');
        })
        ->theyCan('view')
        ->toRule();

    expect($received)->toBeInstanceOf(\Warrant\Builders\WarrantConditionBuilder::class);
    expect($received)->not->toBeInstanceOf(\Warrant\Builders\WarrantRuleBuilder::class);
    expect(method_exists($received, 'theyCan'))->toBeFalse();
});

// -- precedence & grouping (and > or) -----------------------------------------

it('applies and > or precedence when materializing the chain', function () {
    // a and b or c  ->  (a and b) or c
    $tree = WarrantRule::build()->if('a')->andIf('b')->orIf('c')->buildConditions();
    expect(treeToString($tree))->toBe('((a and b) or c)');

    // a or b and c  ->  a or (b and c)
    $tree = WarrantRule::build()->if('a')->orIf('b')->andIf('c')->buildConditions();
    expect(treeToString($tree))->toBe('(a or (b and c))');
});

it('treats a closure as a parenthesized group', function () {
    // (a or b) and c
    $tree = WarrantRule::build()
        ->if(fn ($c) => $c->if('a')->orIf('b'))
        ->andIf('c')
        ->buildConditions();

    expect(treeToString($tree))->toBe('((a or b) and c)');
});

it('negates a whole group', function () {
    // not (a and b)
    $tree = WarrantRule::build()
        ->ifNot(fn ($c) => $c->if('a')->andIf('b'))
        ->buildConditions();

    expect(treeToString($tree))->toBe('!(a and b)');
});

// -- parity with the string DSL -----------------------------------------------

it('produces the identical tree to the equivalent DSL expression', function (string $dsl, Closure $rule) {
    $fromDsl = WarrantParser::parseConditionExpression($dsl);
    $fromBuilder = $rule()->toRule()->conditions;

    expect(treeToString($fromBuilder))->toBe(treeToString($fromDsl));
})->with([
    'and/or precedence' => ['a and b or c', fn () => WarrantRule::build()->if('a')->andIf('b')->orIf('c')->theyCan('x')],
    'or/and precedence' => ['a or b and c', fn () => WarrantRule::build()->if('a')->orIf('b')->andIf('c')->theyCan('x')],
    'explicit grouping'  => ['(a or b) and c', fn () => WarrantRule::build()->if(fn ($g) => $g->if('a')->orIf('b'))->andIf('c')->theyCan('x')],
    'leading not'        => ['not a and b', fn () => WarrantRule::build()->ifNot('a')->andIf('b')->theyCan('x')],
    'or not group'       => ['a or not (b and c)', fn () => WarrantRule::build()->if('a')->orIfNot(fn ($g) => $g->if('b')->andIf('c'))->theyCan('x')],

    // Cross-schema leaves. A plain schema key needs no registration — the registry
    // returns an unrecognized key unchanged, so these stay parser-only comparisons.
    'can unbound' => [
        'can(access for xs_capability)',
        fn () => WarrantRule::build()->ifCan('access', 'xs_capability')->theyCan('x'),
    ],
    'can row-bound by @context' => [
        'can(manage for xs_target(@context id))',
        fn () => WarrantRule::build()->ifCan('manage', 'xs_target', Ref::context('id'))->theyCan('x'),
    ],
    'can row-bound by literal' => [
        "can(manage for xs_target('t-1'))",
        fn () => WarrantRule::build()->ifCan('manage', 'xs_target', 't-1')->theyCan('x'),
    ],
    'can with map' => [
        'can(create for xs_target(@context id) with tenant = @context t, plan = 3)',
        fn () => WarrantRule::build()
            ->ifCan('create', 'xs_target', Ref::context('id'), ['tenant' => Ref::context('t'), 'plan' => 3])
            ->theyCan('x'),
    ],
    'can with a @column selector' => [
        'is_self and can(manage for xs_target(@column docs.target_id))',
        fn () => WarrantRule::build()
            ->if('is_self')
            ->andIfCan('manage', 'xs_target', Ref::column('docs', 'target_id'))
            ->theyCan('x'),
    ],
    'can with a @sql selector' => [
        'can(manage for xs_target(@sql "select 1"))',
        fn () => WarrantRule::build()->ifCan('manage', 'xs_target', Ref::sql('select 1'))->theyCan('x'),
    ],
    'can negated through a group' => [
        'a or not can(view for xs_target)',
        fn () => WarrantRule::build()->if('a')->orIfNot(fn ($g) => $g->ifCan('view', 'xs_target'))->theyCan('x'),
    ],
    'check with a string predicate' => [
        'check(is_open for xs_target)',
        fn () => WarrantRule::build()->ifCheck('is_open', 'xs_target')->theyCan('x'),
    ],
    'check predicate precedence' => [
        'check(a or b and not c for xs_target(@context id))',
        fn () => WarrantRule::build()
            ->ifCheck(fn ($p) => $p->if('a')->orIf('b')->andIfNot('c'), 'xs_target', Ref::context('id'))
            ->theyCan('x'),
    ],
    'check or-joined with a with map' => [
        'is_self or check(is_published for xs_target(@context id) with tenant = @context t)',
        fn () => WarrantRule::build()
            ->if('is_self')
            ->orIfCheck('is_published', 'xs_target', Ref::context('id'), ['tenant' => Ref::context('t')])
            ->theyCan('x'),
    ],
    'check predicate leaf with parameters' => [
        "check(is_open('maintenance') for xs_target)",
        fn () => WarrantRule::build()->ifCheck(fn ($p) => $p->if('is_open', ['maintenance']), 'xs_target')->theyCan('x'),
    ],
    'check predicate with a nested group' => [
        'check((a or b) and c for xs_target)',
        fn () => WarrantRule::build()
            ->ifCheck(fn ($p) => $p->if(fn ($g) => $g->if('a')->orIf('b'))->andIf('c'), 'xs_target')
            ->theyCan('x'),
    ],
]);

// -- when() -------------------------------------------------------------------

it('applies when() branches only when the condition is truthy', function () {
    $make = fn (bool $flag) => WarrantRule::build()
        ->if('a')
        ->when($flag, fn ($c) => $c->orIf('b'))
        ->buildConditions();

    expect(treeToString($make(true)))->toBe('(a or b)');
    expect(treeToString($make(false)))->toBe('a');
});

// -- folding a dynamic list ---------------------------------------------------

it('folds a list of conditions inside a group', function () {
    $ids = ['d1', 'd2', 'd3'];

    $tree = WarrantRule::build()
        ->if('is_self')
        ->orIf(function ($c) use ($ids) {
            foreach ($ids as $id) {
                $c->orIf('in_department', [$id]);
            }
        })
        ->buildConditions();

    expect(treeToString($tree))
        ->toBe("(is_self or ((in_department('d1') or in_department('d2')) or in_department('d3')))");
});

it('treats an empty group as false', function () {
    $tree = WarrantRule::build()
        ->if('is_self')
        ->orIf(function ($c) {
            foreach ([] as $id) {
                $c->orIf('in_department', [$id]);
            }
        })
        ->buildConditions();

    // false contributes nothing to the OR.
    expect(treeToString($tree))->toBe('(is_self or false)');
});

// -- cross-schema handles -----------------------------------------------------

it('leaves a cross-schema reference unbound when no row selector is given', function () {
    $can = WarrantRule::build()->ifCan('access', 'xs_capability')->buildConditions();
    $check = WarrantRule::build()->ifCheck('is_open', 'xs_capability')->buildConditions();

    foreach ([$can, $check] as $node) {
        expect($node->isRowBound)->toBeFalse();
        expect($node->boundRow)->toBeNull();
    }
});

it('keeps an explicit null row selector row-bound so validation can reject it', function () {
    // The whole point of NoRow: a missing id must fail loudly, not quietly widen
    // a row question into a schema-wide one.
    $can = WarrantRule::build()->ifCan('manage', 'xs_target', null)->buildConditions();
    $check = WarrantRule::build()->ifCheck('is_open', 'xs_target', null)->buildConditions();

    foreach ([$can, $check] as $node) {
        expect($node->isRowBound)->toBeTrue();
        expect($node->boundRow)->toBeNull();
    }
});

it('treats an explicitly passed NoRow as an unbound handle', function () {
    $id = null;
    $node = WarrantRule::build()->ifCan('manage', 'xs_target', $id ?? new NoRow)->buildConditions();

    expect($node->isRowBound)->toBeFalse();
});

it('passes a model row selector through untouched', function () {
    $model = (new WarrantTestModel)->forceFill(['id' => 't-1']);
    $node = WarrantRule::build()->ifCan('manage', 'xs_target', $model)->buildConditions();

    expect($node->isRowBound)->toBeTrue();
    expect($node->boundRow)->toBe($model);
});

it('preserves the with map insertion order', function () {
    $node = WarrantRule::build()
        ->ifCan('manage', 'xs_target', 't-1', ['zebra' => 1, 'apple' => 2, 'mango' => 3])
        ->buildConditions();

    expect(array_keys($node->contextMap))->toBe(['zebra', 'apple', 'mango']);
});

it('rejects an empty check(...) predicate closure', function (string $method) {
    expect(fn () => WarrantRule::build()->{$method}(function ($p) {}, 'xs_target'))
        ->toThrow(LogicException::class, 'predicate cannot be empty');
})->with(['ifCheck', 'andIfCheck', 'orIfCheck']);

it('normalizes a model or schema reference to its schema key', function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);

    $keys = array_map(
        fn ($schema) => WarrantRule::build()->ifCan('view', $schema)->buildConditions()->schemaKey,
        [WarrantTestModel::class, new WarrantTestModel, WarrantTestSchema::class, new WarrantTestSchema, 'course_sections'],
    );

    expect($keys)->toBe(array_fill(0, 5, 'course_sections'));
});

it('throws when a class-string reference resolves to no registered schema', function () {
    useWarrantSchemas([]);

    // A plain *key* string passes through unresolved by design — the builder stays
    // usable without a warm registry, and a typo'd key is caught by validate().
    expect(WarrantRule::build()->ifCan('view', 'never_registered')->buildConditions()->schemaKey)
        ->toBe('never_registered');

    expect(fn () => WarrantRule::build()->ifCan('view', WarrantTestModel::class))
        ->toThrow(OutOfBoundsException::class);
});

// -- round-tripping cross-schema terms back to DSL text -----------------------

it('renders a built can/check back to DSL text that re-parses identically', function () {
    $rule = WarrantRule::build()
        ->if('is_self')
        ->andIfCan('manage', 'xs_target', Ref::context('id'), ['tenant' => Ref::context('t')])
        ->orIfCheck(fn ($p) => $p->if('is_published')->andIfNot('is_locked'), 'xs_other', Ref::column('xs_owner', 'other_id'))
        ->theyCan('update')
        ->toRule();

    $syntax = $rule->toSyntax();

    expect($syntax)->toContain('can(manage for xs_target(@context id) with tenant = @context t)');
    expect($syntax)->toContain('check(is_published and not is_locked for xs_other(@column xs_owner.other_id))');
    expect(treeToString(WarrantRule::fromSyntax($syntax)->conditions))->toBe(treeToString($rule->conditions));
});

it('renders a built unbound handle without a row selector', function () {
    // The NoRow distinction has to survive the writer too: an unbound handle has
    // no parens at all, where a row-bound one always does.
    $rule = WarrantRule::build()
        ->ifCan('access', 'xs_capability')
        ->orIfCheck('is_open', 'xs_capability')
        ->theyCan('view')
        ->toRule();

    expect($rule->toSyntax())->toContain('can(access for xs_capability) or check(is_open for xs_capability)');
});

it('renders built literal and @sql row selectors', function () {
    $rule = WarrantRule::build()
        ->ifCan('manage', 'xs_target', 't-1')
        ->orIfCan('manage', 'xs_target', 42)
        ->orIfCheck('is_open', 'xs_target', Ref::sql('select id from xs_targets limit 1'))
        ->theyCan('update')
        ->toRule();

    $syntax = $rule->toSyntax();

    expect($syntax)->toContain("can(manage for xs_target('t-1'))");
    expect($syntax)->toContain('can(manage for xs_target(42))');
    // The writer quotes an @sql body in its own literal style; the lexer takes
    // either quote, so it re-parses regardless.
    expect($syntax)->toContain("check(is_open for xs_target(@sql 'select id from xs_targets limit 1'))");
    expect(treeToString(WarrantRule::fromSyntax($syntax)->conditions))->toBe(treeToString($rule->conditions));
});

it('carries a non-inlinable row selector as a binding in bound syntax', function () {
    $rule = WarrantRule::build()
        ->ifCan('manage', 'xs_target', (new WarrantTestModel)->forceFill(['id' => 't-1']))
        ->theyCan('update')
        ->toRule();

    // Same contract as a condition parameter with no literal form.
    expect(fn () => $rule->toSyntax())->toThrow(LogicException::class);

    $bound = $rule->toBoundSyntax();

    expect($bound->syntax)->toContain('can(manage for xs_target(?))');
    expect($bound->bindings)->toHaveCount(1);

    // The round-trip only closes if the binding refills the selector.
    $reparsed = WarrantRule::fromSyntax($bound->syntax, bindings: $bound->bindings);

    expect($reparsed->conditions->boundRow)->toBe($bound->bindings[0]);
    expect(treeToString($reparsed->conditions))->toBe(treeToString($rule->conditions));
});

// -- ifRaw bridge -------------------------------------------------------------

it('splices a parsed DSL fragment in as one group', function () {
    $tree = WarrantRule::build()
        ->ifRaw('a or b')
        ->andIf('c')
        ->buildConditions();

    expect(treeToString($tree))->toBe('((a or b) and c)');
});

// -- fromRules accepts builders -----------------------------------------------

it('accepts builders directly in fromRules', function () {
    $set = WarrantRuleSet::fromRules(
        'timesheets',
        WarrantRule::build()->if('is_self')->theyCan('view'),
        WarrantRule::build()->theyCannot('delete'),
    );

    expect($set->rules)->toHaveCount(2);
    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[1]->conditions)->toBeNull();
});

// -- WarrantRuleSet::build (callback, one rule per $rule() call) ----------------

it('builds a rule set with one rule per $rule() call, no toRule() needed', function () {
    $set = WarrantRuleSet::build('timesheets', function ($rule) {
        $rule()->if('is_self')->theyCan('edit', 'view');
        $rule()->theyCan('list');
    });

    expect($set->schemaKey)->toBe('timesheets');
    expect($set->rules)->toHaveCount(2);
    expect($set->rules[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->rules[0]->canAbilities)->toBe(['edit', 'view']);
    expect($set->rules[1]->conditions)->toBeNull();
    expect($set->rules[1]->canAbilities)->toBe(['list']);
});

it('produces an empty rule set when the callback adds nothing', function () {
    $set = WarrantRuleSet::build('timesheets', function ($rule) {});

    expect($set->rules)->toBe([]);
});

it('rejects a $rule() with no they-can/they-cannot clause', function () {
    expect(fn () => WarrantRuleSet::build('timesheets', function ($rule) {
        $rule()->if('is_self');
    }))->toThrow(LogicException::class);
});

// -- end-to-end compilation (reuses the compiler test fakes) ------------------

it('compiles a built rule to SQL that filters rows', function () {
    Schema::create('docs', fn ($t) => $t->string('id'));
    DB::table('docs')->insert([['id' => 'teacher:role-1'], ['id' => 'other']]);

    $ruleSet = WarrantRuleSet::fromRules('docs', WarrantRule::build()->if('is_teacher')->theyCan('view'));

    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $query = DB::table('docs');
    $query->addNestedWhereQuery(
        $compiler->compileAbility(new CompilerTestUser('role-1'), $query, 'view', $ruleSet, 'docs.id')
    );

    expect($query->orderBy('id')->pluck('id')->all())->toBe(['teacher:role-1']);
});
