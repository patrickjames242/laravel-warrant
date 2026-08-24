<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\RuleSyntaxTree\ConditionResolver;
use Warrant\RuleSyntaxTree\RuleSetCompiler;
use Warrant\RuleSyntaxTree\RuleSetValidator;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

/**
 * A tiny user carrying just a role, enough for the fake conditions below.
 */
final class CompilerTestUser implements Authenticatable
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

/**
 * Fake schema seam:
 *  - abilities: view, edit, delete, publish
 *  - is_teacher   (targeted)        : id = "teacher:{role}"
 *  - is_owner(id) (targeted, param) : id = param[0]
 *  - is_admin     (no-target bool)  : role === 'admin'
 */
final class FakeConditionResolver implements ConditionResolver
{
    private const TARGETED = ['is_teacher' => true, 'is_owner' => true, 'is_admin' => false, 'id_is' => true, 'ctx_id_is' => true, 'id_is_optional' => true];

    public static function abilityNames(): array
    {
        return ['view', 'edit', 'delete', 'publish'];
    }

    public function conditionExists(string $name): bool
    {
        return array_key_exists($name, self::TARGETED);
    }

    public function conditionIsTargeted(string $name): bool
    {
        return self::TARGETED[$name] ?? false;
    }

    public function applyCondition(string $name, Authenticatable $user, Builder $whereClause, ?string $targetSqlId, array $parameters, array $context = []): Builder|bool
    {
        return match ($name) {
            'is_teacher' => $whereClause->whereRaw("{$targetSqlId} = ?", ["teacher:{$user->role}"]),
            'is_owner' => $whereClause->whereRaw("{$targetSqlId} = ?", [$parameters[0]]),
            // Matches a row whose id equals the (context- or literal-supplied) argument.
            'id_is' => $whereClause->whereRaw("{$targetSqlId} = ?", [$parameters[0]]),
            // Like id_is, but a null argument means "match every row" — proves the
            // condition (not the compiler) decides what an absent @context key means.
            'id_is_optional' => $parameters[0] === null
                ? $whereClause
                : $whereClause->whereRaw("{$targetSqlId} = ?", [$parameters[0]]),
            // Reads the ambient context bag directly (no @context arg in the rule).
            'ctx_id_is' => $whereClause->whereRaw("{$targetSqlId} = ?", [$context['doc_id'] ?? '__missing__']),
            'is_admin' => $user->role === 'admin',
            default => throw new RuntimeException("unknown condition {$name}"),
        };
    }
}

function compileDocIds(string $syntax, string $ability, ?string $role = 'role-1', array $bindings = [], array $context = []): array
{
    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $ruleSet = WarrantRuleSet::fromSyntax('docs', $syntax, $bindings);

    $query = DB::table('docs');
    $predicate = $compiler->compileAbility(new CompilerTestUser($role), $query, $ability, $ruleSet, 'docs.id', $context);
    $query->addNestedWhereQuery($predicate);

    return $query->orderBy('id')->pluck('id')->all();
}

beforeEach(function () {
    Schema::create('docs', function ($table) {
        $table->string('id');
    });

    DB::table('docs')->insert([
        ['id' => 'teacher:role-1'],
        ['id' => 'doc-9'],
        ['id' => 'other'],
    ]);
});

it('grants every row for an unconditional can', function () {
    expect(compileDocIds('they can view', 'view'))->toBe(['doc-9', 'other', 'teacher:role-1']);
});

it('grants no row when the ability is never mentioned', function () {
    expect(compileDocIds('they can view', 'edit'))->toBe([]);
});

it('grants only rows matching a targeted condition', function () {
    expect(compileDocIds('if is_teacher they can view', 'view'))->toBe(['teacher:role-1']);
});

it('passes condition parameters through bindings', function () {
    expect(compileDocIds('if is_owner(:id) they can view', 'view', 'role-1', ['id' => 'doc-9']))
        ->toBe(['doc-9']);
});

it('ORs conditions', function () {
    expect(compileDocIds("if is_teacher or is_owner('doc-9') they can view", 'view'))
        ->toBe(['doc-9', 'teacher:role-1']);
});

it('ANDs conditions', function () {
    expect(compileDocIds("if is_teacher and is_owner('teacher:role-1') they can view", 'view'))
        ->toBe(['teacher:role-1']);
    expect(compileDocIds("if is_teacher and is_owner('doc-9') they can view", 'view'))
        ->toBe([]);
});

it('negates a targeted condition with not', function () {
    expect(compileDocIds('if not is_teacher they can view', 'view'))
        ->toBe(['doc-9', 'other']);
});

it('applies deny-overrides: a conditional cannot subtracts from a grant', function () {
    expect(compileDocIds('they can view if is_teacher they cannot view', 'view'))
        ->toBe(['doc-9', 'other']);
});

it('applies deny-overrides: an unconditional cannot denies everything', function () {
    expect(compileDocIds('they can view they cannot view', 'view'))->toBe([]);
});

it('expands a wildcard can to every declared ability', function () {
    expect(compileDocIds('if is_teacher they can *', 'edit'))->toBe(['teacher:role-1']);
    expect(compileDocIds('if is_teacher they can *', 'delete'))->toBe(['teacher:role-1']);
});

it('expands a wildcard cannot to every declared ability', function () {
    expect(compileDocIds('they can * if is_teacher they cannot *', 'edit'))
        ->toBe(['doc-9', 'other']);
});

it('resolves a no-target boolean condition', function () {
    expect(compileDocIds('if is_admin they can view', 'view', 'admin'))
        ->toBe(['doc-9', 'other', 'teacher:role-1']);
    expect(compileDocIds('if is_admin they can view', 'view', 'not-admin'))->toBe([]);
});

it('forces a targeted condition to false with no target, true under not', function () {
    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $user = new CompilerTestUser('role-1');

    // No targetSqlId: is_teacher is forced false.
    $granted = WarrantRuleSet::fromSyntax('docs', 'if is_teacher they can view');
    $q = DB::table('docs');
    $q->addNestedWhereQuery($compiler->compileAbility($user, $q, 'view', $granted, null));
    expect($q->count())->toBe(0);

    // not is_teacher => true, so every row.
    $negated = WarrantRuleSet::fromSyntax('docs', 'if not is_teacher they can view');
    $q2 = DB::table('docs');
    $q2->addNestedWhereQuery($compiler->compileAbility($user, $q2, 'view', $negated, null));
    expect($q2->count())->toBe(3);
});

// -- @context resolution ------------------------------------------------------

it('resolves a @context value into a condition argument', function () {
    expect(compileDocIds('if id_is(@context doc_id) they can view', 'view', 'role-1', [], ['doc_id' => 'doc-9']))
        ->toBe(['doc-9']);
});

it('exposes the whole context bag to every condition automatically', function () {
    // ctx_id_is reads $c->context['doc_id'] directly — no @context in the rule.
    expect(compileDocIds('if ctx_id_is they can view', 'view', 'role-1', [], ['doc_id' => 'doc-9']))
        ->toBe(['doc-9']);

    // With no context supplied, the condition still runs; it just sees no value.
    expect(compileDocIds('if ctx_id_is they can view', 'view'))->toBe([]);
});

it('soft-falses a grant when a referenced context key is absent', function () {
    // No context: id_is receives null → `id = NULL` matches nothing → no grant.
    expect(compileDocIds('if id_is(@context doc_id) they can view', 'view'))->toBe([]);
});

it('lifts a context-gated cannot when the key is absent (fail-open)', function () {
    // id_is gets null → the veto's condition matches nothing, so the unconditional
    // grant stands — the documented reason a deny-gating key should be required.
    expect(compileDocIds('they can view if id_is(@context doc_id) they cannot view', 'view'))
        ->toBe(['doc-9', 'other', 'teacher:role-1']);

    // With the key present, the veto subtracts the matching row.
    expect(compileDocIds('they can view if id_is(@context doc_id) they cannot view', 'view', 'role-1', [], ['doc_id' => 'doc-9']))
        ->toBe(['other', 'teacher:role-1']);
});

it('treats a negated absent context condition as true (De Morgan-safe)', function () {
    // not id_is(@context doc_id): id_is(null) matches nothing → NOT EXISTS → all rows.
    expect(compileDocIds('if not id_is(@context doc_id) they can view', 'view'))
        ->toBe(['doc-9', 'other', 'teacher:role-1']);
});

it('passes an absent context key to the condition as null, letting it decide', function () {
    // id_is_optional treats a null arg as "match every row" — impossible under the
    // old force-false fold, which never called the condition.
    expect(compileDocIds('if id_is_optional(@context doc_id) they can view', 'view'))
        ->toBe(['doc-9', 'other', 'teacher:role-1']);

    // A supplied value still narrows to the matching row.
    expect(compileDocIds('if id_is_optional(@context doc_id) they can view', 'view', 'role-1', [], ['doc_id' => 'doc-9']))
        ->toBe(['doc-9']);
});

it('accepts a rule referencing any context key without declaration', function () {
    $validator = new RuleSetValidator(new FakeConditionResolver);

    // Context keys need no declaration; an absent one just makes its condition
    // false at compile time. Required-ness is enforced at check time, not here.
    $validator->validate(WarrantRuleSet::fromSyntax('docs', 'if id_is(@context nope) they can view'));
    expect(true)->toBeTrue();
});

it('validates unknown ability and condition names', function () {
    $validator = new RuleSetValidator(new FakeConditionResolver);

    expect(fn () => $validator->validate(WarrantRuleSet::fromSyntax('docs', 'they can fly')))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly]');

    expect(fn () => $validator->validate(WarrantRuleSet::fromSyntax('docs', 'if is_wizard they can view')))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_wizard]');

    // A valid set passes silently.
    $validator->validate(WarrantRuleSet::fromSyntax('docs', 'if is_teacher they can view, edit'));
    expect(true)->toBeTrue();
});
