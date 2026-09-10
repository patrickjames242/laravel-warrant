<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| A condition answering unknown
|------------------------------------------------------------------------------
|
| A condition has a fourth way to answer: null, meaning the question has no
| answer here. That is the third truth value the compiler already speaks — it
| negates to itself, so it neither grants nor lifts a deny, which is what makes
| it the safe answer for a question nobody can settle.
|
| PHP returns null from a method with no `return` statement, so a deliberate
| unknown and a forgotten return arrive at the seam as the same value. The
| builder's state is the second signal that tells them apart: an unknown must
| leave it untouched, and doing both is rejected rather than guessed at.
|
*/

class UnknownAnswerModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'unknown_docs';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return UnknownAnswerSchema::class;
    }
}

class UnknownAnswerSchema extends WarrantSchema
{
    public const model = UnknownAnswerModel::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const DELETE = 'delete';

    /** The question has no answer here. */
    #[RowCondition]
    public function unanswerable(RowConditionContext $c): ?BuilderContract
    {
        return null;
    }

    /** A global condition may answer unknown too. */
    #[GlobalCondition]
    public function unanswerableGlobal(GlobalConditionContext $c): ?bool
    {
        return null;
    }

    #[RowCondition]
    public function isOwned(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->row('owner_id'), '=', $c->user->getAuthIdentifier());
    }

    #[GlobalCondition]
    public function always(GlobalConditionContext $c): bool
    {
        return true;
    }

    #[GlobalCondition]
    public function never(GlobalConditionContext $c): bool
    {
        return false;
    }

    /** Derives itself into an expression whose first leaf cannot be answered. */
    #[RowCondition]
    public function derivedFromUnanswerable(RowConditionContext $c)
    {
        return Warrant::condition('unanswerable or is_owned');
    }

    /** Constrains the query, then falls off the end — the forgotten return. */
    #[RowCondition]
    public function forgotItsReturn(RowConditionContext $c)
    {
        $c->query->where($c->row('owner_id'), '=', 'someone');
    }

    /** Adds nothing and returns its builder, which is the older mistake. */
    #[RowCondition]
    public function addsNothing(RowConditionContext $c): BuilderContract
    {
        return $c->query;
    }
}

beforeEach(function () {
    useWarrantSchemas(['unknown_docs' => UnknownAnswerSchema::class]);

    Schema::dropIfExists('unknown_docs');
    Schema::create('unknown_docs', function ($table) {
        $table->string('id')->primary();
        $table->string('owner_id');
    });

    DB::table('unknown_docs')->insert([
        ['id' => 'mine', 'owner_id' => 'role-1'],
        ['id' => 'theirs', 'owner_id' => 'someone-else'],
    ]);
});

/** The guard for the fixture schema and the default test user. */
function unknownGuard(?string $roleId = 'role-1')
{
    return Warrant::guard(makeWarrantTestUser($roleId))->forSchema(new UnknownAnswerSchema);
}

function unknownFilterSql(string $syntax, string $ability = 'view'): string
{
    bindWarrantRules($syntax, schemaKey: 'unknown_docs');

    return normalizeWarrantSql(
        unknownGuard()->filterQuery(warrantTestQuery('unknown_docs'), $ability)->toRawSql()
    );
}

// -- the answer itself --------------------------------------------------------

it('compiles a row condition answering unknown to the third truth value', function () {
    expect(unknownFilterSql('if unanswerable they can view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "unknown_docs" where (null)
    SQL));
});

it('compiles a global condition answering unknown the same way', function () {
    expect(unknownFilterSql('if unanswerable_global they can view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "unknown_docs" where (null)
    SQL));
});

it('grants nothing when the only rule granting an ability answers unknown', function () {
    bindWarrantRules('if unanswerable they can view', schemaKey: 'unknown_docs');

    expect(unknownGuard()->can('view', 'mine'))->toBeFalse();
});

// -- the safety property ------------------------------------------------------

it('does not lift a deny when the cannot rule answers unknown', function () {
    /* The deny must stand. If an unknown negated into a false, `not unknown`
       would become true, the cannot would fail to fire, and the grant above it
       would come through — a missing answer turning into access. */
    bindWarrantRules(<<<'RULES'
        if is_owned they can view
        if unanswerable they cannot view
    RULES, schemaKey: 'unknown_docs');

    expect(unknownGuard()->can('view', 'mine'))->toBeFalse();
});

it('leaves an unknown unknown through negation in the emitted SQL', function () {
    /* `not (null)` is null again, so the unknown is emitted bare rather than
       negated into something that could select a row. */
    expect(unknownFilterSql(<<<'RULES'
        if is_owned they can view
        if unanswerable they cannot view
    RULES))->toBe(normalizeWarrantSql(<<<SQL
        select * from "unknown_docs"
        where ("unknown_docs"."owner_id" = 'role-1' and null)
    SQL));
});

// -- how it folds against its neighbours --------------------------------------

it('lets a false decide against an unknown', function () {
    // null and false -> false: the only decidable `and`.
    expect(unknownFilterSql('if unanswerable and never they can view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "unknown_docs" where (1 = 0)
    SQL));
});

it('stays unknown when anded with a true', function () {
    // null and true -> null: unknown, and it stays unknown.
    expect(unknownFilterSql('if unanswerable and always they can view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "unknown_docs" where (null)
    SQL));
});

it('lets a sibling settle an unknown under or', function () {
    /* The unknown survives into the SQL for the database to settle per row, and
       lands last: simplify() keeps the operands that decide something and
       appends the unknown behind them. */
    expect(unknownFilterSql('if unanswerable or is_owned they can view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "unknown_docs"
        where ("unknown_docs"."owner_id" = 'role-1' or null)
    SQL));
});

// -- every dispatch path reaches the same handling ----------------------------

it('answers unknown from inside a derived condition\'s expansion', function () {
    expect(unknownFilterSql('if derived_from_unanswerable they can view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "unknown_docs"
        where ("unknown_docs"."owner_id" = 'role-1' or null)
    SQL));
});

it('answers unknown from inside a per-row ability selection', function () {
    bindWarrantRules(<<<'RULES'
        if unanswerable they can view
        if is_owned they can delete
    RULES, schemaKey: 'unknown_docs');

    $rows = unknownGuard()
        ->selectAbilitiesInQuery(warrantTestQuery('unknown_docs')->orderBy('id'))
        ->get();

    // The unknown grants nothing, so only the ability that really matched shows.
    expect(json_decode($rows[0]->abilities, true))->toBe(['delete']);
    expect(json_decode($rows[1]->abilities, true))->toBe([]);
});

// -- the forgotten return -----------------------------------------------------

it('rejects a condition that answers unknown and also constrains the query', function () {
    bindWarrantRules('if forgot_its_return they can view', schemaKey: 'unknown_docs');

    expect(fn () => unknownGuard()->filterQuery(warrantTestQuery('unknown_docs'), 'view'))
        ->toThrow(
            InvalidArgumentException::class,
            'returned null, answering unknown, but also added a where clause',
        );
});

it('still rejects a condition that adds no where clause and returns its builder', function () {
    bindWarrantRules('if adds_nothing they can view', schemaKey: 'unknown_docs');

    expect(fn () => unknownGuard()->filterQuery(warrantTestQuery('unknown_docs'), 'view'))
        ->toThrow(InvalidArgumentException::class, 'added no where clause');
});
