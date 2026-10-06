<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Builders\NoRow;
use Warrant\Builders\Ref;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\ConditionResolver;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ConditionDefinition;
use Warrant\Schema\RuleTemplateDefinition;

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
    public static function modelClass(): string { return CompilerDocModel::class; }
    public static function hasRows(): bool { return true; }
    public static function hasRowKey(): bool { return true; }
    public static function virtualTable(): ?Builder { return null; }
    public function getAbilityDefinition(string $name): ?AbilityDefinition { return $name === 'view' ? new AbilityDefinition($name) : null; }
    public function getConditionDefinition(string $name): ?ConditionDefinition { return $name === 'is_teacher' ? new ConditionDefinition($name, $name, true) : null; }

    public function getRuleTemplateDefinition(string $templateKey): ?RuleTemplateDefinition { return null; }

    public function getKeyDefinition(): ConditionDefinition { return new ConditionDefinition('matchKey', 'matchKey', true, 1); }

    public function applyKey(Authenticatable $user, Builder $whereClause, array $arguments, array $context = [], ?EloquentModel $targetModel = null, ?string $rowQualifier = null): ?Builder
    {
        return ($arguments[0] ?? null) === null
            ? null
            : $whereClause->where(($rowQualifier ?? 'docs').'.id', '=', $arguments[0]);
    }

    public function applyCondition(string $name, Authenticatable $user, Builder $whereClause, bool $targeted, array $parameters, array $context = [], ?EloquentModel $targetModel = null, ?string $rowQualifier = null): Builder|bool
    {
        // Whatever the compiler calls this frame's row, else CompilerDocModel's table.
        return $whereClause->whereRaw(($rowQualifier ?? 'docs') . '.id = ?', ["teacher:{$user->role}"]);
    }
}

// treeToString() / argToString() / handleToString() live in Support/TestSupport.php,
// so the parser tests can compare trees with the same renderer.

// -- structure ----------------------------------------------------------------

it('builds an unconditional rule (no if) with null conditions', function () {
    $rule = WarrantRuleNode::build()->theyCan('view', 'update')->theyCannot('delete')->toRule();

    expect($rule->conditions)->toBeNull();
    expect($rule->canAbilities())->toBe(['view', 'update']);
    expect($rule->cannotAbilities())->toBe(['delete']);
});

it('attaches a denial message to a single ability via theyCannotBecause', function () {
    $rule = WarrantRuleNode::build()->theyCannotBecause('delete', 'This record is locked.')->toRule();

    expect($rule->cannotAbilities())->toBe(['delete']);
    expect($rule->messageFor('delete'))->toBe('This record is locked.');
});

it('shares one message across several abilities in a theyCannotBecause array', function () {
    $rule = WarrantRuleNode::build()->theyCannotBecause(['update', 'delete'], 'locked')->toRule();

    expect($rule->cannotClauses)->toHaveCount(1);
    expect($rule->cannotAbilities())->toBe(['update', 'delete']);
    expect($rule->messageFor('update'))->toBe('locked');
    expect($rule->messageFor('delete'))->toBe('locked');
});

it('gives each theyCannotBecause clause its own message', function () {
    $rule = WarrantRuleNode::build()
        ->theyCannotBecause('update', 'no update')
        ->theyCannotBecause('delete', 'no delete')
        ->toRule();

    expect($rule->cannotClauses)->toHaveCount(2);
    expect($rule->messageFor('update'))->toBe('no update');
    expect($rule->messageFor('delete'))->toBe('no delete');
});

it('accepts a closure denial message via theyCannotBecause', function () {
    $closure = fn () => 'dynamic';
    $rule = WarrantRuleNode::build()->theyCannotBecause('delete', $closure)->toRule();

    expect($rule->messageFor('delete'))->toBe($closure);
});

it('mixes message-less theyCannot and message-bearing theyCannotBecause', function () {
    $rule = WarrantRuleNode::build()
        ->theyCannot('archive')
        ->theyCannotBecause('delete', 'locked')
        ->toRule();

    expect($rule->cannotAbilities())->toBe(['archive', 'delete']);
    expect($rule->messageFor('archive'))->toBeNull();
    expect($rule->messageFor('delete'))->toBe('locked');
});

it('carries condition parameters onto the node', function () {
    $rule = WarrantRuleNode::build()->if('in_department', ['sales', 'eng'])->theyCan('view')->toRule();

    expect($rule->conditions)->toBeInstanceOf(ConditionNode::class);
    expect($rule->conditions->conditionKey)->toBe('in_department');
    expect($rule->conditions->parameters)->toBe(['sales', 'eng']);
});

it('wraps negated terms in a NotNode', function () {
    $rule = WarrantRuleNode::build()->ifNot('is_locked')->theyCan('view')->toRule();

    expect($rule->conditions)->toBeInstanceOf(NotNode::class);
    expect($rule->conditions->operand->conditionKey)->toBe('is_locked');
});

it('rejects a rule with no they-can/they-cannot clause, matching the DSL', function () {
    expect(fn () => WarrantRuleNode::build()->if('is_self')->toRule())
        ->toThrow(LogicException::class);
});

it('hands a group closure a bare condition builder with no clause methods', function () {
    $received = null;

    WarrantRuleNode::build()
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
    $tree = WarrantRuleNode::build()->if('a')->andIf('b')->orIf('c')->buildConditions();
    expect(treeToString($tree))->toBe('((a and b) or c)');

    // a or b and c  ->  a or (b and c)
    $tree = WarrantRuleNode::build()->if('a')->orIf('b')->andIf('c')->buildConditions();
    expect(treeToString($tree))->toBe('(a or (b and c))');
});

it('treats a closure as a parenthesized group', function () {
    // (a or b) and c
    $tree = WarrantRuleNode::build()
        ->if(fn ($c) => $c->if('a')->orIf('b'))
        ->andIf('c')
        ->buildConditions();

    expect(treeToString($tree))->toBe('((a or b) and c)');
});

it('negates a whole group', function () {
    // not (a and b)
    $tree = WarrantRuleNode::build()
        ->ifNot(fn ($c) => $c->if('a')->andIf('b'))
        ->buildConditions();

    expect(treeToString($tree))->toBe('!(a and b)');
});

// -- parity with the string DSL -----------------------------------------------

it('produces the identical tree to the equivalent DSL expression', function (string $dsl, Closure $rule) {
    $fromDsl = WarrantSyntax::parse($dsl)->conditionExpression();
    $fromBuilder = $rule()->toRule()->conditions;

    expect(treeToString($fromBuilder))->toBe(treeToString($fromDsl));
})->with([
    'and/or precedence' => ['a and b or c', fn () => WarrantRuleNode::build()->if('a')->andIf('b')->orIf('c')->theyCan('x')],
    'or/and precedence' => ['a or b and c', fn () => WarrantRuleNode::build()->if('a')->orIf('b')->andIf('c')->theyCan('x')],
    'explicit grouping'  => ['(a or b) and c', fn () => WarrantRuleNode::build()->if(fn ($g) => $g->if('a')->orIf('b'))->andIf('c')->theyCan('x')],
    'leading not'        => ['not a and b', fn () => WarrantRuleNode::build()->ifNot('a')->andIf('b')->theyCan('x')],
    'or not group'       => ['a or not (b and c)', fn () => WarrantRuleNode::build()->if('a')->orIfNot(fn ($g) => $g->if('b')->andIf('c'))->theyCan('x')],

    // Cross-schema leaves. A plain schema key needs no registration — the registry
    // returns an unrecognized key unchanged, so these stay parser-only comparisons.
    'can unbound' => [
        'can(access for xs_capability)',
        fn () => WarrantRuleNode::build()->ifCan('access', 'xs_capability')->theyCan('x'),
    ],
    'can row-bound by @context' => [
        'can(manage for xs_target(@context id))',
        fn () => WarrantRuleNode::build()->ifCan('manage', 'xs_target', Ref::context('id'))->theyCan('x'),
    ],
    'can row-bound by literal' => [
        "can(manage for xs_target('t-1'))",
        fn () => WarrantRuleNode::build()->ifCan('manage', 'xs_target', 't-1')->theyCan('x'),
    ],
    'can with map' => [
        'can(create for xs_target(@context id) with tenant = @context t, plan = 3)',
        fn () => WarrantRuleNode::build()
            ->ifCan('create', 'xs_target', Ref::context('id'), ['tenant' => Ref::context('t'), 'plan' => 3])
            ->theyCan('x'),
    ],
    'can with a @column selector' => [
        'is_self and can(manage for xs_target(@column docs.target_id))',
        fn () => WarrantRuleNode::build()
            ->if('is_self')
            ->andIfCan('manage', 'xs_target', Ref::column('docs', 'target_id'))
            ->theyCan('x'),
    ],
    'can with no schema at all' => [
        'can(view)',
        fn () => WarrantRuleNode::build()->ifCan('view')->theyCan('x'),
    ],
    'can with an alias' => [
        'can(view for xs_target(@context id) as t2)',
        fn () => WarrantRuleNode::build()
            ->ifCan('view', 'xs_target', Ref::context('id'), as: 't2')
            ->theyCan('x'),
    ],
    'check with an alias' => [
        'check(is_published for xs_target(@context id) as t2)',
        fn () => WarrantRuleNode::build()
            ->ifCheck('is_published', 'xs_target', Ref::context('id'), as: 't2')
            ->theyCan('x'),
    ],
    'condition with a bare @column' => [
        'is_owner(@column owner_id)',
        fn () => WarrantRuleNode::build()->if('is_owner', [Ref::column('owner_id')])->theyCan('x'),
    ],
    'condition with a qualified @column' => [
        'is_owner(@column docs.owner_id)',
        fn () => WarrantRuleNode::build()->if('is_owner', [Ref::column('docs', 'owner_id')])->theyCan('x'),
    ],
    'can with a @sql selector' => [
        'can(manage for xs_target(@sql "select 1"))',
        fn () => WarrantRuleNode::build()->ifCan('manage', 'xs_target', Ref::sql('select 1'))->theyCan('x'),
    ],
    'can negated through a group' => [
        'a or not can(view for xs_target)',
        fn () => WarrantRuleNode::build()->if('a')->orIfNot(fn ($g) => $g->ifCan('view', 'xs_target'))->theyCan('x'),
    ],
    'check with a string predicate' => [
        'check(is_open for xs_target)',
        fn () => WarrantRuleNode::build()->ifCheck('is_open', 'xs_target')->theyCan('x'),
    ],
    'check predicate precedence' => [
        'check(a or b and not c for xs_target(@context id))',
        fn () => WarrantRuleNode::build()
            ->ifCheck(fn ($p) => $p->if('a')->orIf('b')->andIfNot('c'), 'xs_target', Ref::context('id'))
            ->theyCan('x'),
    ],
    'check or-joined with a with map' => [
        'is_self or check(is_published for xs_target(@context id) with tenant = @context t)',
        fn () => WarrantRuleNode::build()
            ->if('is_self')
            ->orIfCheck('is_published', 'xs_target', Ref::context('id'), ['tenant' => Ref::context('t')])
            ->theyCan('x'),
    ],
    'check predicate leaf with parameters' => [
        "check(is_open('maintenance') for xs_target)",
        fn () => WarrantRuleNode::build()->ifCheck(fn ($p) => $p->if('is_open', ['maintenance']), 'xs_target')->theyCan('x'),
    ],
    'check predicate with a nested group' => [
        'check((a or b) and c for xs_target)',
        fn () => WarrantRuleNode::build()
            ->ifCheck(fn ($p) => $p->if(fn ($g) => $g->if('a')->orIf('b'))->andIf('c'), 'xs_target')
            ->theyCan('x'),
    ],
]);

// -- when() -------------------------------------------------------------------

it('applies when() branches only when the condition is truthy', function () {
    $make = fn (bool $flag) => WarrantRuleNode::build()
        ->if('a')
        ->when($flag, fn ($c) => $c->orIf('b'))
        ->buildConditions();

    expect(treeToString($make(true)))->toBe('(a or b)');
    expect(treeToString($make(false)))->toBe('a');
});

// -- folding a dynamic list ---------------------------------------------------

it('folds a list of conditions inside a group', function () {
    $ids = ['d1', 'd2', 'd3'];

    $tree = WarrantRuleNode::build()
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
    $tree = WarrantRuleNode::build()
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
    $can = WarrantRuleNode::build()->ifCan('access', 'xs_capability')->buildConditions();
    $check = WarrantRuleNode::build()->ifCheck('is_open', 'xs_capability')->buildConditions();

    foreach ([$can, $check] as $node) {
        expect($node->isRowBound)->toBeFalse();
        expect($node->boundKey)->toBe([]);
    }
});

it('keeps an explicit null row selector row-bound so validation can reject it', function () {
    // The whole point of NoRow: a missing id must fail loudly, not quietly widen
    // a row question into a schema-wide one.
    $can = WarrantRuleNode::build()->ifCan('manage', 'xs_target', null)->buildConditions();
    $check = WarrantRuleNode::build()->ifCheck('is_open', 'xs_target', null)->buildConditions();

    foreach ([$can, $check] as $node) {
        expect($node->isRowBound)->toBeTrue();
        expect($node->boundKey)->toBe([null]);
    }
});

it('treats an explicitly passed NoRow as an unbound handle', function () {
    $id = null;
    $node = WarrantRuleNode::build()->ifCan('manage', 'xs_target', $id ?? new NoRow)->buildConditions();

    expect($node->isRowBound)->toBeFalse();
});

it('passes a model row selector through untouched', function () {
    $model = (new WarrantTestModel)->forceFill(['id' => 't-1']);
    $node = WarrantRuleNode::build()->ifCan('manage', 'xs_target', $model)->buildConditions();

    expect($node->isRowBound)->toBeTrue();
    expect($node->boundKey)->toBe([$model]);
});

it('preserves the with map insertion order', function () {
    $node = WarrantRuleNode::build()
        ->ifCan('manage', 'xs_target', 't-1', ['zebra' => 1, 'apple' => 2, 'mango' => 3])
        ->buildConditions();

    expect(array_keys($node->contextMap))->toBe(['zebra', 'apple', 'mango']);
});

it('rejects an empty check(...) predicate closure', function (string $method) {
    expect(fn () => WarrantRuleNode::build()->{$method}(function ($p) {}, 'xs_target'))
        ->toThrow(LogicException::class, 'predicate cannot be empty');
})->with(['ifCheck', 'andIfCheck', 'orIfCheck']);

it('normalizes a model or schema reference to its schema key', function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);

    $keys = array_map(
        fn ($schema) => WarrantRuleNode::build()->ifCan('view', $schema)->buildConditions()->schemaKey,
        [WarrantTestModel::class, new WarrantTestModel, WarrantTestSchema::class, new WarrantTestSchema, 'course_sections'],
    );

    expect($keys)->toBe(array_fill(0, 5, 'course_sections'));
});

it('throws when a class-string reference resolves to no registered schema', function () {
    useWarrantSchemas([]);

    // A plain *key* string passes through unresolved by design — the builder stays
    // usable without a warm registry, and a typo'd key is caught by validate().
    expect(WarrantRuleNode::build()->ifCan('view', 'never_registered')->buildConditions()->schemaKey)
        ->toBe('never_registered');

    expect(fn () => WarrantRuleNode::build()->ifCan('view', WarrantTestModel::class))
        ->toThrow(OutOfBoundsException::class);
});

// -- round-tripping cross-schema terms back to DSL text -----------------------

it('renders a built can/check back to DSL text that re-parses identically', function () {
    $rule = WarrantRuleNode::build()
        ->if('is_self')
        ->andIfCan('manage', 'xs_target', Ref::context('id'), ['tenant' => Ref::context('t')])
        ->orIfCheck(fn ($p) => $p->if('is_published')->andIfNot('is_locked'), 'xs_other', Ref::column('xs_owner', 'other_id'))
        ->theyCan('update')
        ->toRule();

    $syntax = writeSyntax($rule);

    expect($syntax)->toContain('can(manage for xs_target(@context id) with tenant = @context t)');
    expect($syntax)->toContain('check(is_published and not is_locked for xs_other(@column xs_owner.other_id))');
    expect(treeToString(WarrantSyntax::parse($syntax)->rule()->conditions))->toBe(treeToString($rule->conditions));
});

it('renders a built unbound handle without a row selector', function () {
    // The NoRow distinction has to survive the writer too: an unbound handle has
    // no parens at all, where a row-bound one always does.
    $rule = WarrantRuleNode::build()
        ->ifCan('access', 'xs_capability')
        ->orIfCheck('is_open', 'xs_capability')
        ->theyCan('view')
        ->toRule();

    expect(writeSyntax($rule))->toContain('can(access for xs_capability) or check(is_open for xs_capability)');
});

it('renders built literal and @sql row selectors', function () {
    $rule = WarrantRuleNode::build()
        ->ifCan('manage', 'xs_target', 't-1')
        ->orIfCan('manage', 'xs_target', 42)
        ->orIfCheck('is_open', 'xs_target', Ref::sql('select id from xs_targets limit 1'))
        ->theyCan('update')
        ->toRule();

    $syntax = writeSyntax($rule);

    expect($syntax)->toContain("can(manage for xs_target('t-1'))");
    expect($syntax)->toContain('can(manage for xs_target(42))');
    // The writer quotes an @sql body in its own literal style; the lexer takes
    // either quote, so it re-parses regardless.
    expect($syntax)->toContain("check(is_open for xs_target(@sql 'select id from xs_targets limit 1'))");
    expect(treeToString(WarrantSyntax::parse($syntax)->rule()->conditions))->toBe(treeToString($rule->conditions));
});

it('carries a non-inlinable row selector as a binding in bound syntax', function () {
    $rule = WarrantRuleNode::build()
        ->ifCan('manage', 'xs_target', (new WarrantTestModel)->forceFill(['id' => 't-1']))
        ->theyCan('update')
        ->toRule();

    // Same contract as a condition parameter with no literal form.
    expect(fn () => writeSyntax($rule))->toThrow(LogicException::class);

    $bound = writeBoundSyntax($rule);

    expect($bound->syntax)->toContain('can(manage for xs_target(?))');
    expect($bound->bindings)->toHaveCount(1);

    // The round-trip only closes if the binding refills the selector.
    $reparsed = WarrantSyntax::parse($bound->syntax, $bound->bindings)->rule();

    expect($reparsed->conditions->boundKey)->toBe([$bound->bindings[0]]);
    expect(treeToString($reparsed->conditions))->toBe(treeToString($rule->conditions));
});

// -- ifRaw bridge -------------------------------------------------------------

it('splices a parsed DSL fragment in as one group', function () {
    $tree = WarrantRuleNode::build()
        ->ifRaw('a or b')
        ->andIf('c')
        ->buildConditions();

    expect(treeToString($tree))->toBe('((a or b) and c)');
});

// -- fromRules accepts builders -----------------------------------------------

it('accepts builders directly in fromRules', function () {
    $set = RuleSetNode::fromRules(
        'timesheets',
        WarrantRuleNode::build()->if('is_self')->theyCan('view'),
        WarrantRuleNode::build()->theyCannot('delete'),
    );

    expect($set->flatEntries())->toHaveCount(2);
    expect($set->flatEntries()[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->flatEntries()[1]->conditions)->toBeNull();
});

// -- RuleSetNode::build (callback, one rule per $rule() call) ----------------

it('builds a rule set with one rule per $rule() call, no toRule() needed', function () {
    $set = RuleSetNode::build('timesheets', function ($rule) {
        $rule()->if('is_self')->theyCan('edit', 'view');
        $rule()->theyCan('list');
    });

    expect($set->schemaKey)->toBe('timesheets');
    expect($set->flatEntries())->toHaveCount(2);
    expect($set->flatEntries()[0]->conditions->conditionKey)->toBe('is_self');
    expect($set->flatEntries()[0]->canAbilities())->toBe(['edit', 'view']);
    expect($set->flatEntries()[1]->conditions)->toBeNull();
    expect($set->flatEntries()[1]->canAbilities())->toBe(['list']);
});

it('produces an empty rule set when the callback adds nothing', function () {
    $set = RuleSetNode::build('timesheets', function ($rule) {});

    expect($set->flatEntries())->toBe([]);
});

it('rejects a $rule() with no they-can/they-cannot clause', function () {
    expect(fn () => RuleSetNode::build('timesheets', function ($rule) {
        $rule()->if('is_self');
    }))->toThrow(LogicException::class);
});

// -- end-to-end compilation (reuses the compiler test fakes) ------------------

it('compiles a built rule to SQL that filters rows', function () {
    Schema::create('docs', fn ($t) => $t->string('id'));
    DB::table('docs')->insert([['id' => 'teacher:role-1'], ['id' => 'other']]);

    $ruleSet = (new RuleSetExpander)->expand(
        RuleSetNode::fromRules('docs', WarrantRuleNode::build()->if('is_teacher')->theyCan('view')),
        new FakeConditionResolver,
    );

    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $query = DB::table('docs');
    $compiler->compile(
        CompilationContext::ability(QueryFactory::for($query), new CompilerTestUser('role-1'), 'view', $ruleSet)
            ->forTargetRow(),
    )->spliceInto($query);

    expect($query->orderBy('id')->pluck('id')->all())->toBe(['teacher:role-1']);
});
