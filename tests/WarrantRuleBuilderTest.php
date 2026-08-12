<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\RuleSyntaxTree\AndNode;
use Warrant\RuleSyntaxTree\BooleanNode;
use Warrant\RuleSyntaxTree\ConditionNode;
use Warrant\RuleSyntaxTree\ConditionResolver;
use Warrant\RuleSyntaxTree\NotNode;
use Warrant\RuleSyntaxTree\OrNode;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\RuleSyntaxTree\RuleSetCompiler;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

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
    public static function declaredAbilities(): array { return ['view']; }
    public static function declaredContextKeys(): array { return []; }
    public function conditionExists(string $name): bool { return $name === 'is_teacher'; }
    public function conditionIsTargeted(string $name): bool { return true; }

    public function applyCondition(string $name, Authenticatable $user, Builder $whereClause, ?string $targetSqlId, array $parameters, array $context = []): Builder|bool
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
            : '(' . implode(',', array_map(fn ($p) => var_export($p, true), $node->parameters)) . ')'),
        $node instanceof NotNode => '!' . treeToString($node->operand),
        $node instanceof AndNode => '(' . treeToString($node->leftSide) . ' and ' . treeToString($node->rightSide) . ')',
        $node instanceof OrNode => '(' . treeToString($node->leftSide) . ' or ' . treeToString($node->rightSide) . ')',
        $node instanceof BooleanNode => $node->value ? 'true' : 'false',
        default => throw new RuntimeException('unexpected node ' . $node::class),
    };
}

// -- structure ----------------------------------------------------------------

it('builds an unconditional rule (no if) with null conditions', function () {
    $rule = WarrantRule::build()->theyCan('view', 'update')->theyCannot('delete')->toRule();

    expect($rule->conditions)->toBeNull();
    expect($rule->canAbilities)->toBe(['view', 'update']);
    expect($rule->cannotAbilities)->toBe(['delete']);
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

    expect($received)->toBeInstanceOf(\Warrant\RuleSyntaxTree\WarrantConditionBuilder::class);
    expect($received)->not->toBeInstanceOf(\Warrant\RuleSyntaxTree\WarrantRuleBuilder::class);
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
