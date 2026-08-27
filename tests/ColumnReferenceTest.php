<?php


use Warrant\Facades\Warrant;
require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Ability;
use Warrant\AbilityMatchMode;
use Warrant\GlobalCondition;
use Warrant\HasWarrantSchema;
use Warrant\RowCondition;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\WarrantSchema;

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

    useWarrantSchemas([ColTsSchema::class, ColDocSchema::class, ColTargetSchema::class, ColCapSchema::class]);
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
        'col_timesheets.id',
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "col_timesheets" where (
            "col_timesheets"."pay_period_id" = 'role-1'
        )
    SQL));
});

// -- correlated subquery via check(...) / can(...) ----------------------------

it('correlates a check(...) subquery to the outer table via a @column row selector', function () {
    // The row selector is the OUTER schema's own column, in scope of A's query, so
    // B's exists-subquery correlates `col_targets.id = col_docs.target_id`.
    bindColRules('if check(is_open for col_targets(@column col_docs.target_id)) they can view', 'col_docs');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new ColDocSchema))->filterQuery(
        warrantTestQuery('col_docs'),
        'col_docs.id',
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
        'col_docs.id',
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
        'col_docs.id',
        'view',
        AbilityMatchMode::ALL,
    )->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe(['d1']);
});

// -- validation (both layers) --------------------------------------------------

it('rejects a @column reference to an unknown schema at validation time', function () {
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if pay_period_matches(@column no_such.col) they can view',
        'timesheets',
    )->validate())->toThrow(InvalidArgumentException::class, 'unknown schema [no_such]');
});

it('rejects a @column reference to a modelless schema at validation time', function () {
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if pay_period_matches(@column col_cap.col) they can view',
        'timesheets',
    )->validate())->toThrow(InvalidArgumentException::class, 'has no model');
});

it('allows a @column reference to the owning schema (self-reference)', function () {
    // Unlike can(...)/check(...), referencing your own table's column is the point.
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if pay_period_matches(@column timesheets.pay_period_id) they can view',
        'timesheets',
    )->validate())->not->toThrow(Exception::class);
});

// -- fixtures -----------------------------------------------------------------

class ColTs extends Model
{
    use HasWarrantSchema;

    protected $table = 'col_timesheets';
    public $incrementing = false;
    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return ColTsSchema::class;
    }
}

class ColTsSchema extends WarrantSchema
{
    // Key deliberately differs from the table name, to prove key → table resolution.
    public const schemaKey = 'timesheets';
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
}

class ColDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'col_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return ColDocSchema::class;
    }
}

class ColDocSchema extends WarrantSchema
{
    public const schemaKey = 'col_docs';
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

    public function warrantSchema(): string
    {
        return ColTargetSchema::class;
    }
}

class ColTargetSchema extends WarrantSchema
{
    public const schemaKey = 'col_targets';
    public const model = ColTarget::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOpen(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->row('state'), '=', 'open');
    }
}

class ColCapSchema extends WarrantSchema
{
    // A modelless capability schema — no table, so @column cannot reference it.
    public const schemaKey = 'col_cap';

    #[GlobalCondition]
    public function always(GlobalConditionContext $c): bool
    {
        return true;
    }
}
