<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Builders\Build;
use Warrant\Builders\NoRow;
use Warrant\Builders\Ref;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\SyntaxSource;

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

// treeToString() / argToString() / handleToString() live in Support/TestSupport.php,
// so the parser tests can compare trees with the same renderer.

// -- structure ----------------------------------------------------------------

it('builds an unconditional rule (no if) with null conditions', function () {
    $rule = Build::rule()->theyCan('view', 'update')->theyCannot('delete')->toRule();

    expect($rule->conditions)->toBeNull();
    expect(grantedAbilitiesOf($rule))->toBe(['view', 'update']);
    expect(deniedAbilitiesOf($rule))->toBe(['delete']);
});

it('attaches a denial message to a single ability via theyCannotBecause', function () {
    $rule = Build::rule()->theyCannotBecause('delete', 'This record is locked.')->toRule();

    expect(deniedAbilitiesOf($rule))->toBe(['delete']);
    expect(denialMessageOf($rule, 'delete'))->toBe('This record is locked.');
});

it('shares one message across several abilities in a theyCannotBecause array', function () {
    $rule = Build::rule()->theyCannotBecause(['update', 'delete'], 'locked')->toRule();

    expect($rule->cannotClauses)->toHaveCount(1);
    expect(deniedAbilitiesOf($rule))->toBe(['update', 'delete']);
    expect(denialMessageOf($rule, 'update'))->toBe('locked');
    expect(denialMessageOf($rule, 'delete'))->toBe('locked');
});

it('gives each theyCannotBecause cannot clause its own message', function () {
    $rule = Build::rule()
        ->theyCannotBecause('update', 'no update')
        ->theyCannotBecause('delete', 'no delete')
        ->toRule();

    expect($rule->cannotClauses)->toHaveCount(2);
    expect(denialMessageOf($rule, 'update'))->toBe('no update');
    expect(denialMessageOf($rule, 'delete'))->toBe('no delete');
});

it('accepts a closure denial message via theyCannotBecause', function () {
    $closure = fn () => 'dynamic';
    $rule = Build::rule()->theyCannotBecause('delete', $closure)->toRule();

    expect(denialMessageOf($rule, 'delete'))->toBe($closure);
});

it('mixes message-less theyCannot and message-bearing theyCannotBecause', function () {
    $rule = Build::rule()
        ->theyCannot('archive')
        ->theyCannotBecause('delete', 'locked')
        ->toRule();

    expect(deniedAbilitiesOf($rule))->toBe(['archive', 'delete']);
    expect(denialMessageOf($rule, 'archive'))->toBeNull();
    expect(denialMessageOf($rule, 'delete'))->toBe('locked');
});

it('carries condition parameters onto the node', function () {
    $rule = Build::rule()->if('in_department', ['sales', 'eng'])->theyCan('view')->toRule();

    expect($rule->conditions)->toBeInstanceOf(ConditionNode::class);
    expect($rule->conditions->conditionKey?->name)->toBe('in_department');
    expect(argumentValues($rule->conditions->parameters))->toEqualSyntax((['sales', 'eng']));
});

it('wraps negated terms in a NotNode', function () {
    $rule = Build::rule()->ifNot('is_locked')->theyCan('view')->toRule();

    expect($rule->conditions)->toBeInstanceOf(NotNode::class);
    expect($rule->conditions->operand->conditionKey?->name)->toBe('is_locked');
});

it('rejects a rule with no they-can/they-cannot clause, matching the DSL', function () {
    expect(fn () => Build::rule()->if('is_self')->toRule())
        ->toThrow(LogicException::class);
});

it('hands a group closure a bare condition builder with no can/cannot clause methods', function () {
    $received = null;

    Build::rule()
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
    $tree = Build::rule()->if('a')->andIf('b')->orIf('c')->buildConditions(new SyntaxSource(null));
    expect(treeToString($tree))->toBe('((a and b) or c)');

    // a or b and c  ->  a or (b and c)
    $tree = Build::rule()->if('a')->orIf('b')->andIf('c')->buildConditions(new SyntaxSource(null));
    expect(treeToString($tree))->toBe('(a or (b and c))');
});

it('treats a closure as a parenthesized group', function () {
    // (a or b) and c
    $tree = Build::rule()
        ->if(fn ($c) => $c->if('a')->orIf('b'))
        ->andIf('c')
        ->buildConditions(new SyntaxSource(null));

    expect(treeToString($tree))->toBe('((a or b) and c)');
});

it('negates a whole group', function () {
    // not (a and b)
    $tree = Build::rule()
        ->ifNot(fn ($c) => $c->if('a')->andIf('b'))
        ->buildConditions(new SyntaxSource(null));

    expect(treeToString($tree))->toBe('not (a and b)');
});

// -- parity with the string DSL -----------------------------------------------

it('produces the identical tree to the equivalent DSL expression', function (string $dsl, Closure $rule) {
    $fromDsl = WarrantSyntax::parse($dsl)->validateWithoutSchemas()->conditionExpression();
    $fromBuilder = $rule()->toRule()->conditions;

    expect(treeToString($fromBuilder))->toBe(treeToString($fromDsl));
})->with([
    'and/or precedence' => ['a and b or c', fn () => Build::rule()->if('a')->andIf('b')->orIf('c')->theyCan('x')],
    'or/and precedence' => ['a or b and c', fn () => Build::rule()->if('a')->orIf('b')->andIf('c')->theyCan('x')],
    'explicit grouping'  => ['(a or b) and c', fn () => Build::rule()->if(fn ($g) => $g->if('a')->orIf('b'))->andIf('c')->theyCan('x')],
    'leading not'        => ['not a and b', fn () => Build::rule()->ifNot('a')->andIf('b')->theyCan('x')],
    'or not group'       => ['a or not (b and c)', fn () => Build::rule()->if('a')->orIfNot(fn ($g) => $g->if('b')->andIf('c'))->theyCan('x')],

    // Cross-schema leaves. A plain schema key needs no registration — the registry
    // returns an unrecognized key unchanged, so these stay parser-only comparisons.
    'can unbound' => [
        'can(access for xs_capability)',
        fn () => Build::rule()->ifCan(Build::can('access')->for('xs_capability'))->theyCan('x'),
    ],
    'can row-bound by @context' => [
        'can(manage for xs_target(@context id))',
        fn () => Build::rule()->ifCan(Build::can('manage')->for('xs_target', Ref::context('id')))->theyCan('x'),
    ],
    'can row-bound by literal' => [
        "can(manage for xs_target('t-1'))",
        fn () => Build::rule()->ifCan(Build::can('manage')->for('xs_target', 't-1'))->theyCan('x'),
    ],
    'can with map' => [
        'can(create for xs_target(@context id) with tenant = @context t, plan = 3)',
        fn () => Build::rule()
            ->ifCan(Build::can('create')->for('xs_target', Ref::context('id'))->with(['tenant' => Ref::context('t'), 'plan' => 3]))
            ->theyCan('x'),
    ],
    'can with a @column selector' => [
        'is_self and can(manage for xs_target(@column docs.target_id))',
        fn () => Build::rule()
            ->if('is_self')
            ->andIfCan(Build::can('manage')->for('xs_target', Ref::column('docs', 'target_id')))
            ->theyCan('x'),
    ],
    'can with no schema at all' => [
        'can(view)',
        fn () => Build::rule()->ifCan('view')->theyCan('x'),
    ],
    'can with an alias' => [
        'can(view for xs_target(@context id) as t2)',
        fn () => Build::rule()
            ->ifCan(Build::can('view')->for('xs_target', Ref::context('id'))->as('t2'))
            ->theyCan('x'),
    ],
    'check with an alias' => [
        'check(is_published for xs_target(@context id) as t2)',
        fn () => Build::rule()
            ->ifCheck(Build::check('is_published')->for('xs_target', Ref::context('id'))->as('t2'))
            ->theyCan('x'),
    ],
    'condition with a bare @column' => [
        'is_owner(@column owner_id)',
        fn () => Build::rule()->if('is_owner', [Ref::column('owner_id')])->theyCan('x'),
    ],
    'condition with a qualified @column' => [
        'is_owner(@column docs.owner_id)',
        fn () => Build::rule()->if('is_owner', [Ref::column('docs', 'owner_id')])->theyCan('x'),
    ],
    'can with a @sql selector' => [
        'can(manage for xs_target(@sql "select 1"))',
        fn () => Build::rule()->ifCan(Build::can('manage')->for('xs_target', Ref::sql('select 1')))->theyCan('x'),
    ],
    'can negated through a group' => [
        'a or not can(view for xs_target)',
        fn () => Build::rule()->if('a')->orIfNot(fn ($g) => $g->ifCan(Build::can('view')->for('xs_target')))->theyCan('x'),
    ],
    'check with a string predicate' => [
        'check(is_open for xs_target)',
        fn () => Build::rule()->ifCheck(Build::check('is_open')->for('xs_target'))->theyCan('x'),
    ],
    'check predicate precedence' => [
        'check(a or b and not c for xs_target(@context id))',
        fn () => Build::rule()
            ->ifCheck(fn ($p) => $p->if('a')->orIf('b')->andIfNot('c')->for('xs_target', Ref::context('id')))
            ->theyCan('x'),
    ],
    'check or-joined with a with map' => [
        'is_self or check(is_published for xs_target(@context id) with tenant = @context t)',
        fn () => Build::rule()
            ->if('is_self')
            ->orIfCheck(Build::check('is_published')->for('xs_target', Ref::context('id'))->with(['tenant' => Ref::context('t')]))
            ->theyCan('x'),
    ],
    'check predicate leaf with parameters' => [
        "check(is_open('maintenance') for xs_target)",
        fn () => Build::rule()->ifCheck(fn ($p) => $p->if('is_open', ['maintenance'])->for('xs_target'))->theyCan('x'),
    ],
    'check predicate with a nested group' => [
        'check((a or b) and c for xs_target)',
        fn () => Build::rule()
            ->ifCheck(fn ($p) => $p->if(fn ($g) => $g->if('a')->orIf('b'))->andIf('c')->for('xs_target'))
            ->theyCan('x'),
    ],
]);

// -- when() -------------------------------------------------------------------

it('applies when() branches only when the condition is truthy', function () {
    $make = fn (bool $flag) => Build::rule()
        ->if('a')
        ->when($flag, fn ($c) => $c->orIf('b'))
        ->buildConditions(new SyntaxSource(null));

    expect(treeToString($make(true)))->toBe('(a or b)');
    expect(treeToString($make(false)))->toBe('a');
});

// -- folding a dynamic list ---------------------------------------------------

it('folds a list of conditions inside a group', function () {
    $ids = ['d1', 'd2', 'd3'];

    $tree = Build::rule()
        ->if('is_self')
        ->orIf(function ($c) use ($ids) {
            foreach ($ids as $id) {
                $c->orIf('in_department', [$id]);
            }
        })
        ->buildConditions(new SyntaxSource(null));

    expect(treeToString($tree))
        ->toBe("(is_self or ((in_department('d1') or in_department('d2')) or in_department('d3')))");
});

it('treats an empty group as false', function () {
    $tree = Build::rule()
        ->if('is_self')
        ->orIf(function ($c) {
            foreach ([] as $id) {
                $c->orIf('in_department', [$id]);
            }
        })
        ->buildConditions(new SyntaxSource(null));

    // false contributes nothing to the OR.
    expect(treeToString($tree))->toBe('(is_self or false)');
});

// -- cross-schema handles -----------------------------------------------------

it('leaves a cross-schema reference unbound when no row selector is given', function () {
    $can = Build::rule()->ifCan(Build::can('access')->for('xs_capability'))->buildConditions(new SyntaxSource(null));
    $check = Build::rule()->ifCheck(Build::check('is_open')->for('xs_capability'))->buildConditions(new SyntaxSource(null));

    foreach ([$can, $check] as $node) {
        expect($node->isRowBound)->toBeFalse();
        expect(argumentValues($node->boundKey))->toEqualSyntax(([]));
    }
});

it('keeps an explicit null row selector row-bound so validation can reject it', function () {
    // The whole point of NoRow: a missing id must fail loudly, not quietly widen
    // a row question into a schema-wide one.
    $can = Build::rule()->ifCan(Build::can('manage')->for('xs_target', null))->buildConditions(new SyntaxSource(null));
    $check = Build::rule()->ifCheck(Build::check('is_open')->for('xs_target', null))->buildConditions(new SyntaxSource(null));

    foreach ([$can, $check] as $node) {
        expect($node->isRowBound)->toBeTrue();
        expect(argumentValues($node->boundKey))->toEqualSyntax(([null]));
    }
});

it('treats an explicitly passed NoRow as an unbound handle', function () {
    $id = null;
    $node = Build::rule()->ifCan(Build::can('manage')->for('xs_target', $id ?? new NoRow))->buildConditions(new SyntaxSource(null));

    expect($node->isRowBound)->toBeFalse();
});

it('passes a model row selector through untouched', function () {
    $model = (new WarrantTestModel)->forceFill(['id' => 't-1']);
    $node = Build::rule()->ifCan(Build::can('manage')->for('xs_target', $model))->buildConditions(new SyntaxSource(null));

    expect($node->isRowBound)->toBeTrue();
    expect(argumentValues($node->boundKey))->toEqualSyntax(([$model]));
});

it('preserves the with map insertion order', function () {
    $node = Build::rule()
        ->ifCan(Build::can('manage')->for('xs_target', 't-1')->with(['zebra' => 1, 'apple' => 2, 'mango' => 3]))
        ->buildConditions(new SyntaxSource(null));

    expect(array_keys(withEntryValues($node->contextMap)))->toBe(['zebra', 'apple', 'mango']);
});

it('rejects an empty check(...) predicate closure', function (string $method) {
    expect(fn () => Build::rule()->{$method}(fn ($check) => $check->for('xs_target')))
        ->toThrow(LogicException::class, 'predicate cannot be empty');
})->with(['ifCheck', 'andIfCheck', 'orIfCheck']);

it('normalizes a model or schema reference to its schema key', function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);

    $keys = array_map(
        fn ($schema) => Build::rule()->ifCan(Build::can('view')->for($schema))->buildConditions(new SyntaxSource(null))->schemaKey->name,
        [WarrantTestModel::class, new WarrantTestModel, WarrantTestSchema::class, new WarrantTestSchema, 'course_sections'],
    );

    expect($keys)->toBe(array_fill(0, 5, 'course_sections'));
});

it('throws when a class-string reference resolves to no registered schema', function () {
    useWarrantSchemas([]);

    // A plain *key* string passes through unresolved by design — the builder stays
    // usable without a warm registry, and a typo'd key is caught by validate().
    expect(Build::rule()->ifCan(Build::can('view')->for('never_registered'))->buildConditions(new SyntaxSource(null))->schemaKey?->name)
        ->toBe('never_registered');

    expect(fn () => Build::rule()->ifCan(Build::can('view')->for(WarrantTestModel::class)))
        ->toThrow(OutOfBoundsException::class);
});

// -- round-tripping cross-schema terms back to DSL text -----------------------

it('renders a built can/check back to DSL text that re-parses identically', function () {
    $rule = Build::rule()
        ->if('is_self')
        ->andIfCan(Build::can('manage')->for('xs_target', Ref::context('id'))->with(['tenant' => Ref::context('t')]))
        ->orIfCheck(fn ($p) => $p->if('is_published')->andIfNot('is_locked')->for('xs_other', Ref::column('xs_owner', 'other_id')))
        ->theyCan('update')
        ->toRule();

    $syntax = writeSyntax($rule);

    expect($syntax)->toContain('can(manage for xs_target(@context id) with tenant = @context t)');
    expect($syntax)->toContain('is_published and not is_locked')
        ->and($syntax)->toContain('for xs_other(@column xs_owner.other_id)');
    expect(treeToString(WarrantSyntax::parse($syntax)->validateWithoutSchemas()->rule()->conditions))->toBe(treeToString($rule->conditions));
});

it('renders a built unbound handle without a row selector', function () {
    // The NoRow distinction has to survive the writer too: an unbound handle has
    // no parens at all, where a row-bound one always does.
    $rule = Build::rule()
        ->ifCan(Build::can('access')->for('xs_capability'))
        ->orIfCheck(Build::check('is_open')->for('xs_capability'))
        ->theyCan('view')
        ->toRule();

    expect(writeSyntax($rule))->toContain('can(access for xs_capability) or check(is_open for xs_capability)');
});

it('renders built literal and @sql row selectors', function () {
    $rule = Build::rule()
        ->ifCan(Build::can('manage')->for('xs_target', 't-1'))
        ->orIfCan(Build::can('manage')->for('xs_target', 42))
        ->orIfCheck(Build::check('is_open')->for('xs_target', Ref::sql('select id from xs_targets limit 1')))
        ->theyCan('update')
        ->toRule();

    $syntax = writeSyntax($rule);

    expect($syntax)->toContain("can(manage for xs_target('t-1'))");
    expect($syntax)->toContain('can(manage for xs_target(42))');
    // The writer quotes an @sql body in its own literal style; the lexer takes
    // either quote, so it re-parses regardless.
    expect($syntax)->toContain("check(is_open for xs_target(@sql 'select id from xs_targets limit 1'))");
    expect(treeToString(WarrantSyntax::parse($syntax)->validateWithoutSchemas()->rule()->conditions))->toBe(treeToString($rule->conditions));
});

it('carries a non-inlinable row selector as a binding in bound syntax', function () {
    $rule = Build::rule()
        ->ifCan(Build::can('manage')->for('xs_target', (new WarrantTestModel)->forceFill(['id' => 't-1'])))
        ->theyCan('update')
        ->toRule();

    // Same contract as a condition parameter with no literal form.
    expect(fn () => writeSyntax($rule))->toThrow(LogicException::class);

    $bound = writeBoundSyntax($rule);

    expect($bound->syntax)->toContain('can(manage for xs_target(:b0))');
    expect($bound->bindings)->toHaveCount(1);

    // The round-trip only closes if the binding refills the selector.
    $reparsed = WarrantSyntax::parse(new BoundSyntax($bound->syntax, $bound->bindings))->validateWithoutSchemas()->rule();

    expect(argumentValues($reparsed->conditions->boundKey))->toEqualSyntax(([$bound->bindings['b0']]));
    expect(treeToString($reparsed->conditions))->toBe(treeToString($rule->conditions));
});

// -- ifRaw bridge -------------------------------------------------------------

it('splices a parsed DSL fragment in as one group', function () {
    $tree = Build::rule()
        ->ifRaw('a or b')
        ->andIf('c')
        ->buildConditions(new SyntaxSource(null));

    expect(treeToString($tree))->toBe('((a or b) and c)');
});

// -- Build::ruleSet (callback, one rule per $rule() call) -------------------

it('builds a rule set with one rule per $rule() call, no toRule() needed', function () {
    $set = Build::ruleSet('timesheets', function ($rule) {
        $rule()->if('is_self')->theyCan('edit', 'view');
        $rule()->theyCan('list');
    });

    expect($set->schemaKey?->name)->toBe('timesheets');
    expect($set->entries)->toHaveCount(2);
    expect($set->entries[0]->conditions->conditionKey?->name)->toBe('is_self');
    expect(grantedAbilitiesOf($set->entries[0]))->toBe(['edit', 'view']);
    expect($set->entries[1]->conditions)->toBeNull();
    expect(grantedAbilitiesOf($set->entries[1]))->toBe(['list']);
});

it('produces an empty rule set when the callback adds nothing', function () {
    $set = Build::ruleSet('timesheets', function ($rule) {});

    expect($set->entries)->toBe([]);
});

it('rejects a $rule() with no they-can/they-cannot clause', function () {
    expect(fn () => Build::ruleSet('timesheets', function ($rule) {
        $rule()->if('is_self');
    }))->toThrow(LogicException::class);
});

// -- end-to-end compilation (reuses the compiler test fakes) ------------------

it('compiles a built rule to SQL that filters rows', function () {
    Schema::create('docs', fn ($t) => $t->string('id'));
    DB::table('docs')->insert([['id' => 'teacher:role-1'], ['id' => 'other']]);

    $ruleSet = expandedFor(
        Build::ruleSet('docs', fn ($rule) => $rule()->if('is_teacher')->theyCan('view')),
        new CompilerDocSchema,
    );

    $compiler = new RuleSetCompiler(new CompilerDocSchema, 'docs');
    $query = DB::table('docs');
    $compiler->compile(
        CompilationContext::ability(QueryFactory::for($query), new CompilerTestUser('role-1'), 'view', $ruleSet)
            ->forTargetRow(),
    )->spliceInto($query);

    expect($query->orderBy('id')->pluck('id')->all())->toBe(['teacher:role-1']);
});
