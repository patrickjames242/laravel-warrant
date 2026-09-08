<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;
require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| @column <schema>.<column> references
|------------------------------------------------------------------------------
|
| A @column ref resolves, at compile time, to a grammar-wrapped Expression of the
| referenced schema's REAL table column — the schema key is mapped through the
| registry to the model's table (so key ≠ table is handled), and the identifier is
| quoted with the query's own grammar so it is emitted verbatim, never re-wrapped
| or bound as a value. These tests lock in the emitted SQL and the validation.
|
*/

beforeEach(function () {
    Schema::create('col_timesheets', function ($table) {
        $table->string('id');
        $table->string('pay_period_id');
    });
    Schema::create('col_docs', function ($table) {
        $table->string('id');
        $table->string('target_id');
    });
    Schema::create('col_targets', function ($table) {
        $table->string('id');
        $table->string('state');
    });

    useWarrantSchemas(['timesheets' => ColTsSchema::class, 'col_docs' => ColDocSchema::class, 'col_targets' => ColTargetSchema::class, 'col_cap' => ColCapSchema::class]);
});

/**
 * Bind a resolver returning $syntax for its own schema key, empty elsewhere.
 */
function bindColRules(string $syntax, string $schemaKey): void
{
    $set = WarrantRuleSet::fromSyntax($syntax, $schemaKey);

    app()->instance(RuleResolver::class, new class($set, $schemaKey) implements RuleResolver {
        public function __construct(private WarrantRuleSet $set, private string $key) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $context->schemaKey === $this->key
                ? $this->set
                : new WarrantRuleSet($context->schemaKey, []);
        }
    });
}

// -- resolution to a grammar-wrapped Expression -------------------------------

it('resolves a @column arg to the real table column, grammar-wrapped and unbound', function () {
    // Schema key `timesheets` maps to the real table `col_timesheets`; the arg is an
    // Expression, so it is emitted verbatim (quoted) as the LHS — no extra binding,
    // no double-wrapping — while role-1 binds normally.
    bindColRules('if pay_period_matches(@column timesheets.pay_period_id) they can view', 'timesheets');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColTsSchema))->filterQuery(
        warrantTestQuery('col_timesheets'),
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "col_timesheets" where (
            "col_timesheets"."pay_period_id" = 'role-1'
        )
    SQL));
});

it('resolves a @column against the host query alias when the caller aliased its from', function () {
    /* Same rule text, same schema — but the query selects `col_timesheets as t`,
       so the only name those rows answer to is `t`. Reading the table off the
       model would emit a reference to a table this query never joined. */
    bindColRules('if pay_period_matches(@column timesheets.pay_period_id) they can view', 'timesheets');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColTsSchema))->filterQuery(
        warrantTestQuery('col_timesheets as t'),
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "col_timesheets" as "t" where (
            "t"."pay_period_id" = 'role-1'
        )
    SQL));
});

// -- the unqualified form ------------------------------------------------------

it('resolves an unqualified @column against the rows the rule is about', function () {
    /* No frame named, so it means "this rule's own rows" — which is what the
       qualified form was almost always saying the long way round. */
    bindColRules('if pay_period_matches(@column pay_period_id) they can view', 'timesheets');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColTsSchema))->filterQuery(
        warrantTestQuery('col_timesheets'),
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "col_timesheets" where ("col_timesheets"."pay_period_id" = 'role-1')
    SQL));
});

it('follows the host query alias without being told', function () {
    /* The payoff of leaving it unqualified: the rule text names no table, so it
       cannot name the wrong one. */
    bindColRules('if pay_period_matches(@column pay_period_id) they can view', 'timesheets');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColTsSchema))->filterQuery(
        warrantTestQuery('col_timesheets as t'),
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "col_timesheets" as "t" where ("t"."pay_period_id" = 'role-1')
    SQL));
});

it('needs no schema in scope for an unqualified @column to validate', function () {
    // Nothing is named, so there is nothing for validation to reject.
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if pay_period_matches(@column pay_period_id) they can view',
        'timesheets',
    )->validate())->not->toThrow(Exception::class);
});

// -- a row that is not in scope ------------------------------------------------

it('emits a @column normally when the row is in scope', function () {
    bindColRules('if column_is_set(@column timesheets.pay_period_id) they can view', 'timesheets');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColTsSchema))->filterQuery(
        warrantTestQuery('col_timesheets'),
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "col_timesheets" where ("col_timesheets"."pay_period_id" is not null)
    SQL));
});

it('folds an unqualified @column when the rule has no rows in scope', function () {
    /* Validation lets it through because it names nothing; the compiler is where
       "no rows at all" is discovered, and it folds rather than erroring, since
       the same rule is fine wherever a row *is* in scope. */
    bindColRules('if column_is_set(@column pay_period_id) they can view', 'timesheets');

    expect(
        Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColTsSchema))->getAbilitiesWithoutTarget()
    )->toBe([]);
});

it('folds a @column leaf to false when there is no row in scope to qualify it', function () {
    /* Same rule, compiled with no target: the condition is global, so it would
       happily run — but there is no `from` for `timesheets` to hang on, so asking
       about that column is unanswerable rather than false-in-SQL. The leaf folds,
       which is also what stops this emitting a reference to a table the query
       never selected. */
    bindColRules('if column_is_set(@column timesheets.pay_period_id) they can view', 'timesheets');

    $abilities = Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema((new ColTsSchema))
        ->getAbilitiesWithoutTarget();

    expect($abilities)->toBe([]);
});

it('folds a can(...) whose row selector names a row that is not in scope', function () {
    // The selector correlates B's row to A's, and with no A row there is nothing
    // to correlate to — so the reference folds instead of emitting a dangling one.
    bindColRules('if can(view for col_targets(@column col_docs.target_id)) they can view', 'col_docs');

    $abilities = Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema((new ColDocSchema))
        ->getAbilitiesWithoutTarget();

    expect($abilities)->toBe([]);
});

// -- correlated subquery via check(...) / can(...) ----------------------------

it('correlates a check(...) subquery to the outer table via a @column row selector', function () {
    // The row selector is the OUTER schema's own column, in scope of A's query, so
    // B's exists-subquery correlates `col_targets.id = col_docs.target_id`.
    bindColRules('if check(is_open for col_targets(@column col_docs.target_id)) they can view', 'col_docs');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColDocSchema))->filterQuery(
        warrantTestQuery('col_docs'),
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "col_docs" where (
            exists (
                select * from "col_targets"
                where "col_targets"."id" = "col_docs"."target_id"
                    and ("col_targets"."state" = 'open')
            )
        )
    SQL));
});

it('correlates a can(...) subquery to the outer table via a @column row selector', function () {
    // can(view for col_targets(@column col_docs.target_id)): B grants view
    // unconditionally, so the exists correlates on the row selector alone.
    bindColRules('if can(view for col_targets(@column col_docs.target_id)) they can view', 'col_docs');

    // The target schema grants view to everyone.
    app()->instance(RuleResolver::class, new class implements RuleResolver {
        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $context->schemaKey === 'col_docs'
                ? WarrantRuleSet::fromSyntax('if can(view for col_targets(@column col_docs.target_id)) they can view', 'col_docs')
                : WarrantRuleSet::fromSyntax('they can view', 'col_targets');
        }
    });

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColDocSchema))->filterQuery(
        warrantTestQuery('col_docs'),
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toContain('"col_targets"."id" = "col_docs"."target_id"');
});

// -- end-to-end row filtering --------------------------------------------------

it('filters rows through a @column-correlated check subquery', function () {
    DB::table('col_docs')->insert([
        ['id' => 'd1', 'target_id' => 't-open'],
        ['id' => 'd2', 'target_id' => 't-closed'],
    ]);
    DB::table('col_targets')->insert([
        ['id' => 't-open', 'state' => 'open'],
        ['id' => 't-closed', 'state' => 'closed'],
    ]);

    bindColRules('if check(is_open for col_targets(@column col_docs.target_id)) they can view', 'col_docs');

    $ids = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColDocSchema))->filterQuery(
        warrantTestQuery('col_docs'),
        'view',
        AbilityMatchMode::ALL,
    )->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe(['d1']);
});

// -- validation (both layers) --------------------------------------------------

it('rejects a @column reference naming a table that is not in scope', function (string $name) {
    /* An unknown schema, a registered schema whose table this query never joined,
       and a capability schema with no table at all are one error now: from these
       rules, none of those names refers to anything. The message names what *is*
       in scope, which is the useful half. */
    expect(fn () => WarrantRuleSet::fromSyntax(
        "if pay_period_matches(@column {$name}.col) they can view",
        'timesheets',
    )->validate())->toThrow(
        InvalidArgumentException::class,
        "A @column reference names [{$name}], which is not in scope here; the names in scope are [timesheets].",
    );
})->with([
    'unknown schema' => 'no_such',
    'another schema, not joined here' => 'col_docs',
    'capability schema, no table' => 'col_cap',
]);

it('allows a @column reference to the owning schema (self-reference)', function () {
    // Unlike can(...)/check(...), referencing your own table's column is the point.
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if pay_period_matches(@column timesheets.pay_period_id) they can view',
        'timesheets',
    )->validate())->not->toThrow(Exception::class);
});

it('puts the target of a check(...) in scope for its own predicate, alongside the caller', function () {
    /* The predicate is written in col_docs' rule text but asks col_targets'
       questions, so both frames are nameable there — that is what lets one
       predicate correlate the two. */
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if check(id_matches(@column col_targets.id) and id_matches(@column col_docs.target_id) '
            .'for col_targets(@column col_docs.target_id)) they can view',
        'col_docs',
    )->validate())->not->toThrow(Exception::class);
});

it('rejects a @column in a check(...) predicate naming neither frame', function () {
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if check(id_matches(@column timesheets.id) for col_targets(@column col_docs.target_id)) they can view',
        'col_docs',
    )->validate())->toThrow(
        InvalidArgumentException::class,
        'the names in scope are [col_docs, col_targets].',
    );
});

it('rejects an alias on a handle that selects no row', function (string $syntax, string $builtin) {
    // An unbound handle emits no `from`, so its alias names nothing.
    expect(fn () => WarrantRuleSet::fromSyntax($syntax, 'col_docs')->validate())->toThrow(
        InvalidArgumentException::class,
        "A {$builtin}(...) reference to schema [col_targets] is aliased [as t2] but selects no row, "
            .'so the alias names nothing',
    );
})->with([
    'can' => ['if can(view for col_targets as t2) they can view', 'can'],
    'check' => ['if check(is_open for col_targets as t2) they can view', 'check'],
]);

it('names a check(...) target by its alias, leaving its schema key for the caller', function () {
    /* Aliasing the inner frame is how a predicate reaches both: `t2` is the
       target, and `col_targets` — had the caller been col_targets itself — would
       still mean the enclosing row. Here the caller is col_docs, so both names
       resolve and neither shadows. */
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if check(id_matches(@column t2.id) for col_targets(@column col_docs.target_id) as t2) they can view',
        'col_docs',
    )->validate())->not->toThrow(Exception::class);
});

it('stops the target schema key naming an aliased check(...) frame', function () {
    /* With `as t2` the target is bound under `t2` only, so `col_targets` is no
       longer a name in scope — the whole point, since it is what lets the key go
       on meaning an outer frame of the same table. */
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if check(id_matches(@column col_targets.id) for col_targets(@column col_docs.target_id) as t2) they can view',
        'col_docs',
    )->validate())->toThrow(
        InvalidArgumentException::class,
        'A @column reference names [col_targets], which is not in scope here; '
            .'the names in scope are [col_docs, t2].',
    );
});

// -- fixtures -----------------------------------------------------------------

class ColTs extends Model
{
    use HasWarrantSchema;

    protected $table = 'col_timesheets';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ColTsSchema::class;
    }
}

class ColTsSchema extends WarrantSchema
{
    // Key deliberately differs from the table name, to prove key → table resolution.
    public const model = ColTs::class;

    #[Ability]
    public const VIEW = 'view';

    // Receives the resolved @column Expression as its argument and uses it as a raw
    // column operand — where(Expression, '=', value) emits `<expr> = ?` with the
    // value bound and the Expression left verbatim.
    #[RowCondition]
    public function payPeriodMatches(RowConditionContext $c, mixed $column): BuilderContract
    {
        return $c->query->where($column, '=', $c->user->role_id);
    }

    /* A GLOBAL condition that still takes a column operand. It runs with or
       without a target row, so it is the case where a @column about a row that is
       not in scope has to be what settles the leaf — a row condition would have
       folded before ever reaching its arguments. */
    #[GlobalCondition]
    public function columnIsSet(GlobalConditionContext $c, mixed $column): BuilderContract
    {
        return $c->query->whereNotNull($column);
    }
}

class ColDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'col_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ColDocSchema::class;
    }
}

class ColDocSchema extends WarrantSchema
{
    public const model = ColDoc::class;

    #[Ability]
    public const VIEW = 'view';
}

class ColTarget extends Model
{
    use HasWarrantSchema;

    protected $table = 'col_targets';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ColTargetSchema::class;
    }
}

class ColTargetSchema extends WarrantSchema
{
    public const model = ColTarget::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOpen(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->row('state'), '=', 'open');
    }

    /* Takes one operand, so a check(...) predicate can correlate this frame to
       another one with a @column — `where(<expr>, '=', <expr>)`. */
    #[RowCondition]
    public function idMatches(RowConditionContext $c, mixed $column): BuilderContract
    {
        return $c->query->where($c->row(), '=', $column);
    }
}

class ColCapSchema extends WarrantSchema
{
    // A modelless capability schema — no table, so @column cannot reference it.

    #[GlobalCondition]
    public function always(GlobalConditionContext $c): bool
    {
        return true;
    }
}
