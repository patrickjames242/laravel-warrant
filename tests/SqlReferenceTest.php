<?php


use Warrant\Facades\Warrant;
require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Schema\Ability;
use Warrant\AbilityMatchMode;
use Warrant\HasWarrantSchema;
use Warrant\Schema\RowCondition;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\WarrantSchema;

/*
|------------------------------------------------------------------------------
| @sql "<sql>" references
|------------------------------------------------------------------------------
|
| An @sql ref resolves, at compile time, to an Illuminate Query Expression holding
| the author's SQL wrapped in a single pair of parentheses — exactly what
| DB::raw('(' . $sql . ')') produces. The body is spliced verbatim: never bound as
| a value, never re-quoted. It is usable anywhere @column is (condition params,
| handle row selectors, with-map values). These tests lock in the emitted SQL.
|
*/

beforeEach(function () {
    Schema::create('sql_items', function ($table) {
        $table->string('id');
        $table->integer('value');
    });
    Schema::create('sql_config', function ($table) {
        $table->integer('threshold');
    });

    useWarrantSchemas(['sql_items' => SqlItemSchema::class]);
});

/**
 * Bind a resolver returning $syntax for its own schema key, empty elsewhere.
 */
function bindSqlRules(string $syntax, string $schemaKey): void
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

// -- resolution to a parenthesized, unbound Expression ------------------------

it('resolves a @sql arg to a parenthesized raw expression, unbound', function () {
    // The arg is an Expression, so it is emitted verbatim — wrapped in exactly one
    // pair of parens — as the comparison operand, with no extra binding.
    bindSqlRules('if value_matches(@sql "select 1") they can view', 'sql_items');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new SqlItemSchema))->filterQuery(
        warrantTestQuery('sql_items'),
        'sql_items.id',
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "sql_items" where (
            "sql_items"."value" = (select 1)
        )
    SQL));
});

it('always parenthesizes, even when the author already wrapped the body', function () {
    bindSqlRules('if value_matches(@sql "(select 1)") they can view', 'sql_items');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new SqlItemSchema))->filterQuery(
        warrantTestQuery('sql_items'),
        'sql_items.id',
        'view',
        AbilityMatchMode::ALL,
    )->toRawSql();

    // Assert on the raw SQL: normalizeWarrantSql() collapses redundant parens, which
    // would hide the extra layer we are proving is emitted.
    expect($sql)->toContain('= ((select 1))');
});

// -- end-to-end row filtering --------------------------------------------------

it('filters rows through a @sql scalar-subquery comparison', function () {
    DB::table('sql_config')->insert(['threshold' => 5]);
    DB::table('sql_items')->insert([
        ['id' => 'a', 'value' => 3],
        ['id' => 'b', 'value' => 5],
        ['id' => 'c', 'value' => 9],
    ]);

    // value = (select max(threshold) from sql_config) -> value = 5
    bindSqlRules(
        'if value_matches(@sql "select max(threshold) from sql_config") they can view',
        'sql_items',
    );

    $ids = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new SqlItemSchema))->filterQuery(
        warrantTestQuery('sql_items'),
        'sql_items.id',
        'view',
        AbilityMatchMode::ALL,
    )->orderBy('id')->pluck('id')->all();

    expect($ids)->toBe(['b']);
});

// -- fixtures -----------------------------------------------------------------

class SqlItem extends Model
{
    use HasWarrantSchema;

    protected $table = 'sql_items';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return SqlItemSchema::class;
    }
}

class SqlItemSchema extends WarrantSchema
{
    public const model = SqlItem::class;

    #[Ability]
    public const VIEW = 'view';

    // Receives the resolved @sql Expression and compares the row's own column
    // against it: where("sql_items"."value", '=', <expr>) emits
    // `"sql_items"."value" = (<sql>)` with the Expression left verbatim.
    #[RowCondition]
    public function valueMatches(RowConditionContext $c, mixed $expr): BuilderContract
    {
        return $c->query->where($c->row('value'), '=', $expr);
    }
}
