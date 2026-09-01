<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\ConditionResolver;
use Warrant\DSL\Parsing\Validation\RuleSetValidator;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ConditionDefinition;
use Warrant\WarrantGate;

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
    private const TARGETED = ['is_teacher' => true, 'is_owner' => true, 'is_admin' => false, 'id_is' => true, 'ctx_id_is' => true, 'id_is_optional' => true, 'adds_nothing' => true];

    public static function schemaKey(): string
    {
        return 'docs';
    }

    public static function abilityNames(): array
    {
        return ['view', 'edit', 'delete', 'publish'];
    }

    public function getAbilityDefinition(string $name): ?AbilityDefinition
    {
        return in_array($name, self::abilityNames(), true) ? new AbilityDefinition($name) : null;
    }

    public function getConditionDefinition(string $name): ?ConditionDefinition
    {
        if (! array_key_exists($name, self::TARGETED)) {
            return null;
        }

        // is_owner and id_is read $parameters[0]; the rest take no required args.
        $required = in_array($name, ['is_owner', 'id_is'], true) ? 1 : 0;

        return new ConditionDefinition($name, $name, self::TARGETED[$name], $required);
    }

    public function applyCondition(string $name, Authenticatable $user, Builder $whereClause, ?string $targetSqlId, array $parameters, array $context = []): Builder|bool
    {
        return match ($name) {
            'is_teacher' => $whereClause->whereRaw("{$targetSqlId} = ?", ["teacher:{$user->role}"]),
            'is_owner' => $whereClause->whereRaw("{$targetSqlId} = ?", [$parameters[0]]),
            // Matches a row whose id equals the (context- or literal-supplied) argument.
            'id_is' => $whereClause->whereRaw("{$targetSqlId} = ?", [$parameters[0]]),
            // Like id_is, but a null argument means "match every row" — proves the
            // condition (not the compiler) decides what an absent @context key
            // means. It has to say so with a literal true: returning the query
            // untouched is an error (see adds_nothing).
            'id_is_optional' => $parameters[0] === null
                ? true
                : $whereClause->whereRaw("{$targetSqlId} = ?", [$parameters[0]]),
            // Returns its query without adding anything — always an error.
            'adds_nothing' => $whereClause,
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
    $ruleSet = WarrantRuleSet::fromSyntax($syntax, 'docs', $bindings);

    $query = DB::table('docs');
    $predicate = $compiler->compileAbility(new CompilerTestUser($role), $query, $ability, $ruleSet, 'docs.id', $context);
    $query->addNestedWhereQuery($predicate);

    return $query->orderBy('id')->pluck('id')->all();
}

/**
 * Compile a whole gate (abilities + match mode) through compileGate and return
 * the matching doc ids — the compiler-level equivalent of filterQuery.
 *
 * @param list<string> $abilities
 */
function compileGateDocIds(string $syntax, array $abilities, AbilityMatchMode $matchMode, ?string $role = 'role-1'): array
{
    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $ruleSet = WarrantRuleSet::fromSyntax($syntax, 'docs');

    $query = DB::table('docs');
    $predicate = $compiler->compileGate(
        new CompilerTestUser($role),
        $query,
        new WarrantGate($abilities, $matchMode),
        $ruleSet,
        'docs.id',
    );
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

it('grants only rows matching a row condition', function () {
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

it('negates a row condition with not', function () {
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

it('compileGate ANY ORs the per-ability predicates (union of rows)', function () {
    // view → {doc-9, teacher:role-1}; edit → {doc-9, other}.
    $syntax = "if id_is('doc-9') they can view\n"
        . "if id_is('teacher:role-1') they can view\n"
        . "if id_is('doc-9') they can edit\n"
        . "if id_is('other') they can edit";

    expect(compileGateDocIds($syntax, ['view', 'edit'], AbilityMatchMode::ANY))
        ->toBe(['doc-9', 'other', 'teacher:role-1']);
});

it('compileGate ALL ANDs the per-ability predicates (intersection of rows)', function () {
    // Same rules: the only row granted BOTH view and edit is doc-9.
    $syntax = "if id_is('doc-9') they can view\n"
        . "if id_is('teacher:role-1') they can view\n"
        . "if id_is('doc-9') they can edit\n"
        . "if id_is('other') they can edit";

    expect(compileGateDocIds($syntax, ['view', 'edit'], AbilityMatchMode::ALL))
        ->toBe(['doc-9']);
});

it('expands a wildcard can to every declared ability', function () {
    expect(compileDocIds('if is_teacher they can *', 'edit'))->toBe(['teacher:role-1']);
    expect(compileDocIds('if is_teacher they can *', 'delete'))->toBe(['teacher:role-1']);
});

it('expands a wildcard cannot to every declared ability', function () {
    expect(compileDocIds('they can * if is_teacher they cannot *', 'edit'))
        ->toBe(['doc-9', 'other']);
});

it('resolves a global boolean condition', function () {
    expect(compileDocIds('if is_admin they can view', 'view', 'admin'))
        ->toBe(['doc-9', 'other', 'teacher:role-1']);
    expect(compileDocIds('if is_admin they can view', 'view', 'not-admin'))->toBe([]);
});

it('forces a row condition to false with no target, true under not', function () {
    $compiler = new RuleSetCompiler(new FakeConditionResolver);
    $user = new CompilerTestUser('role-1');

    // No targetSqlId: is_teacher is forced false.
    $granted = WarrantRuleSet::fromSyntax('if is_teacher they can view', 'docs');
    $q = DB::table('docs');
    $q->addNestedWhereQuery($compiler->compileAbility($user, $q, 'view', $granted, null));
    expect($q->count())->toBe(0);

    // not is_teacher => true, so every row.
    $negated = WarrantRuleSet::fromSyntax('if not is_teacher they can view', 'docs');
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

it('over-applies a context-gated cannot when the key is absent (fail-closed)', function () {
    // id_is gets null → `id = null` is UNKNOWN for every row, so the inline
    // `not (id = null)` deny term is UNKNOWN and excludes them all. Since SQL's
    // three-valued logic is no longer normalized away, an absent deny-gating key
    // now fails safe (blocks everything) instead of silently lifting the veto.
    expect(compileDocIds('they can view if id_is(@context doc_id) they cannot view', 'view'))
        ->toBe([]);

    // With the key present, the veto subtracts only the matching row.
    expect(compileDocIds('they can view if id_is(@context doc_id) they cannot view', 'view', 'role-1', [], ['doc_id' => 'doc-9']))
        ->toBe(['other', 'teacher:role-1']);
});

it('grants nothing for a negated condition whose context key is absent (fail-closed)', function () {
    // not id_is(@context doc_id): id_is(null) is `id = null` → UNKNOWN, and
    // `not (UNKNOWN)` is UNKNOWN, so no row is granted. An unknown condition
    // contributes no access — never granting is the safe direction.
    expect(compileDocIds('if not id_is(@context doc_id) they can view', 'view'))
        ->toBe([]);
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

it('rejects a condition that adds no where clause', function () {
    // Emitting nothing would silently mean "match every row"; a condition that
    // really decides the outcome has to return a bool instead.
    expect(fn () => compileDocIds('if adds_nothing they can view', 'view'))
        ->toThrow(InvalidArgumentException::class, 'added no where clause');
});

it('accepts a rule referencing any context key without declaration', function () {
    $validator = new RuleSetValidator(new FakeConditionResolver, 'docs');

    // Context keys need no declaration; an absent one just makes its condition
    // false at compile time. Required-ness is enforced at check time, not here.
    $validator->validate(WarrantRuleSet::fromSyntax('if id_is(@context nope) they can view', 'docs'));
    expect(true)->toBeTrue();
});

it('validates unknown ability and condition names', function () {
    $validator = new RuleSetValidator(new FakeConditionResolver, 'docs');

    expect(fn () => $validator->validate(WarrantRuleSet::fromSyntax('they can fly', 'docs')))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly]');

    expect(fn () => $validator->validate(WarrantRuleSet::fromSyntax('if is_wizard they can view', 'docs')))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_wizard]');

    // A valid set passes silently.
    $validator->validate(WarrantRuleSet::fromSyntax('if is_teacher they can view, edit', 'docs'));
    expect(true)->toBeTrue();
});
