<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Builders\Ref;
use Warrant\Builders\WarrantConditionBuilder;
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
| A derived condition is held to the same rules as the text it stands in
|------------------------------------------------------------------------------
|
| A condition may answer with an expression instead of SQL, and the compiler walks
| the result as though it had been written inline. That tree reaches the compiler
| after validation has already run and cannot be seen by it at all, so anything
| the compiler does not check for itself would go unreported here.
|
| Each test pairs the mistake as rule text — caught by validation — with the same
| mistake produced by PHP, which has to be caught by the compiler. The two need
| not report identically, but both have to report.
|
| A malformed handle is not the same as an unanswerable question: a row condition
| with no row, an absent @context key or a @column about another frame all still
| fold to an unknown. See CrossSchemaConditionCompilerTest.
|
*/

beforeEach(function () {
    Schema::create('dv_docs', function ($table) {
        $table->string('id');
        $table->string('owner');
    });

    useWarrantSchemas([
        'dv_docs' => DvDocSchema::class,
        'dv_caps' => DvCapSchema::class,
    ]);
});

function compiledDvSql(string $syntax): string
{
    bindWarrantRules($syntax, schemaKey: 'dv_docs');
    Warrant::flush();

    return normalizeWarrantSql(
        Warrant::guard(makeWarrantTestUser('role-1'))
            ->forSchema((new DvDocSchema))
            ->filterQuery(warrantTestQuery('dv_docs'), 'view', AbilityMatchMode::ALL, [])
            ->toRawSql()
    );
}

function compileDvRule(string $syntax): void
{
    bindWarrantRules($syntax, schemaKey: 'dv_docs');
    Warrant::flush();

    Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema((new DvDocSchema))
        ->filterQuery(warrantTestQuery('dv_docs'), 'view', AbilityMatchMode::ALL, []);
}

// -- an ability nobody declares ------------------------------------------------

it('rejects an ability the rule text names that no schema declares', function () {
    expect(fn () => compileDvRule('if can(nope for dv_docs(@column id)) they can view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [nope] is not declared by schema [dv_docs]');
});

it('rejects an ability an expansion names that no schema declares', function () {
    /* Left unchecked this compiles to `1 = 0` — a true statement about an ability
       that does not exist, and indistinguishable from one no rule grants. */
    expect(fn () => compileDvRule('if hop_to_missing_ability they can view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [nope] is not declared by schema [dv_docs]');
});

it('rejects it under a not, where folding would have granted instead of denied', function () {
    /* The direction that makes this more than a message: `not exists (… 1 = 0)`
       is true of every row, so the same typo grants what it cannot verify. */
    expect(fn () => compileDvRule('if not hop_to_missing_ability they can view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [nope] is not declared by schema [dv_docs]');
});

it('rejects it on a boolean check, not only while building a query', function () {
    bindWarrantRules('if not hop_to_missing_ability they can view', schemaKey: 'dv_docs');
    Warrant::flush();
    DB::table('dv_docs')->insert(['id' => 'd1', 'owner' => 'someone-else']);

    expect(fn () => Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema((new DvDocSchema))
        ->can('view', 'd1'))
        ->toThrow(InvalidArgumentException::class, 'Ability [nope] is not declared by schema [dv_docs]');
});

it('rejects an ability a schema-less can names, which stays on the frame it sits in', function () {
    /* The third leaf that reaches an ability: can(<ability>) crosses to no schema,
       so the name is read against whichever frame the expression is about. */
    expect(fn () => compileDvRule('if bare_can_missing_ability they can view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [nope] is not declared by schema [dv_docs]');
});

it('still denies an ability that is declared but granted by no rule', function () {
    // The answer the check above must not be confused with.
    bindWarrantRules('if is_owner they can view', schemaKey: 'dv_docs');
    Warrant::flush();

    expect(
        Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DvDocSchema))->getAbilitiesWithoutTarget()
    )->toBe([]);
});

// -- a row-bound hop into a schema with no rows --------------------------------

it('rejects a row-bound hop into a model-less schema in rule text', function () {
    expect(fn () => compileDvRule('if check(is_ok for dv_caps(@column id)) they can view'))
        ->toThrow(InvalidArgumentException::class, 'has no rows and cannot be row-targeted');
});

it('rejects a row-bound hop into a model-less schema an expansion built', function () {
    // Left unchecked this reaches `new ('')` and fails as `Class "" not found`.
    expect(fn () => compileDvRule('if row_bound_capability they can view'))
        ->toThrow(InvalidArgumentException::class, 'has no rows and cannot be row-targeted');
});

// -- an alias on a handle that selects no row ----------------------------------

it('rejects an alias on an unbound handle in rule text', function () {
    expect(fn () => compileDvRule('if check(is_owner for dv_docs as f) they can view'))
        ->toThrow(InvalidArgumentException::class, 'is aliased [as f] but selects no row');
});

it('rejects an alias on an unbound handle an expansion built', function () {
    expect(fn () => compileDvRule('if alias_without_row they can view'))
        ->toThrow(InvalidArgumentException::class, 'is aliased [as f] but selects no row');
});

// -- a row selector that is a literal null -------------------------------------

it('rejects a literal null row selector in rule text', function () {
    expect(fn () => compileDvRule('if check(is_owner for dv_docs(null)) they can view'))
        ->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

it('rejects a literal null row selector an expansion built', function () {
    expect(fn () => compileDvRule('if null_row_selector they can view'))
        ->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

it('still folds a row selector whose @context key is absent', function () {
    /* The distinction the null check has to preserve: a @context selector is a
       symbol until compile time, so an absent key is an unanswered question
       rather than a handle that names no row. */
    bindWarrantRules('if check(is_owner for dv_docs(@context other_id)) they can view', schemaKey: 'dv_docs');
    Warrant::flush();

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema((new DvDocSchema))
        ->filterQuery(warrantTestQuery('dv_docs'), 'view', AbilityMatchMode::ALL, [])
        ->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql('select * from "dv_docs" where (null)'));
});

// -- a @column in a frame whose schema has no rows -----------------------------

it('rejects a @column naming a model-less schema in rule text', function () {
    expect(fn () => compileDvRule('if check(cap_col(@column dv_caps.x) for dv_caps) they can view'))
        ->toThrow(InvalidArgumentException::class, 'A @column reference names [dv_caps], which is not in scope here');
});

it('rejects a @column naming a model-less schema an expansion built', function () {
    /* The name resolves to no frame — dv_caps has no table in any compile, reached
       from anywhere — so it fails the same way as any other name nobody bound,
       rather than folding as a frame merely out of scope does. */
    expect(fn () => compileDvRule('if derived_cap_col_own they can view'))
        ->toThrow(InvalidArgumentException::class, 'A @column reference names [dv_caps], which is not in scope here');
});

it('still folds a bare @column in a model-less frame, rather than reading it as the caller\'s', function () {
    /* The unqualified form means the rows this frame is about, and a model-less
       frame is about none — so it has no answer here. Reading it as the enclosing
       frame's rows would silently compile a column of the wrong table. */
    expect(compiledDvSql('if derived_cap_col_bare they can view'))
        ->toBe(normalizeWarrantSql('select * from "dv_docs" where (null)'));
});

it('still resolves a @column naming the frame the predicate was written in', function () {
    // A model-less predicate keeps the caller's names, so it can correlate back.
    expect(compiledDvSql('if derived_cap_col_caller they can view'))
        ->toBe(normalizeWarrantSql('select * from "dv_docs" where ("dv_docs"."id" = \'x\')'));
});

// -- fixtures -----------------------------------------------------------------

class DvDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'dv_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return DvDocSchema::class;
    }
}

class DvDocSchema extends WarrantSchema
{
    public const model = DvDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }

    /** Hops to an ability no schema declares. */
    #[RowCondition]
    public function hopToMissingAbility(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCan('nope', DvDocSchema::class, Ref::column('id'));
    }

    /** Names the model-less target's own key, which stands for no frame at all. */
    #[RowCondition]
    public function derivedCapColOwn(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            fn ($p) => $p->if('cap_col', [Ref::column('dv_caps', 'x')]),
            DvCapSchema::class,
        );
    }

    /** Names no frame, so it means whichever rows the frame is about — none. */
    #[RowCondition]
    public function derivedCapColBare(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            fn ($p) => $p->if('cap_col', [Ref::column('x')]),
            DvCapSchema::class,
        );
    }

    /** Correlates back to the frame the predicate was written in. */
    #[RowCondition]
    public function derivedCapColCaller(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            fn ($p) => $p->if('cap_col', [Ref::column('dv_docs', 'id')]),
            DvCapSchema::class,
        );
    }

    /** Asks about another ability of this same frame, naming no schema. */
    #[RowCondition]
    public function bareCanMissingAbility(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCan('nope');
    }

    /** Row-binds a hop into a schema that has no model, and so no rows. */
    #[RowCondition]
    public function rowBoundCapability(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck('is_ok', DvCapSchema::class, Ref::column('id'));
    }

    /** Names the rows of a handle that selects none. */
    #[RowCondition]
    public function aliasWithoutRow(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck('is_owner', DvDocSchema::class, as: 'f');
    }

    /** Stays row-bound while naming no row — an explicit null, not an omission. */
    #[RowCondition]
    public function nullRowSelector(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck('is_owner', DvDocSchema::class, key: null);
    }
}

/** No model, so no table and no rows to target. */
class DvCapSchema extends WarrantSchema
{
    #[Ability]
    public const USE_IT = 'use_it';

    #[GlobalCondition]
    public function isOk(GlobalConditionContext $c): bool
    {
        return true;
    }

    /* Compares whatever column it is handed against a literal, so the qualifier
       the reference resolved to is visible in the emitted SQL. */
    #[GlobalCondition]
    public function capCol(GlobalConditionContext $c, mixed $col = null): BuilderContract
    {
        return $c->query->where($col, '=', DB::raw("'x'"));
    }
}
