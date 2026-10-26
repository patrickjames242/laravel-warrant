<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Types\Classes\ModelSchema;
use Warrant\Schema\Types\Classes\QuerySchema;
use Warrant\Schema\Types\Classes\RowlessSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| QuerySchema — a schema whose rows come from a query
|------------------------------------------------------------------------------
|
| A query schema's rows are the output of its rows() query: a database view
| defined in the schema instead of in a migration. The compiler selects from it
| as a subquery, under the rows' name, so conditions and rules read exactly as
| they do over a table.
|
| What such a schema gives up is everything a model was buying beyond the rows
| themselves: there are no Eloquent scopes to spend, no hydrated $c->model, and
| no primary key. It names its rows by key() or a matchKey() of its own, and a
| condition names its columns through column('<column>').
|
*/

class VtTeamModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'vt_teams';

    public static function warrantSchema(): string
    {
        return VtTeamSchema::class;
    }
}

class VtTeamSchema extends ModelSchema
{
    public const model = VtTeamModel::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const PLAN = 'plan';
}

/**
 * One row per team per calendar day, carrying that day's shift count — the
 * product-shaped case, where no stored row corresponds to a view row.
 */
class VtDaySchema extends QuerySchema
{
    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const ASSIGN = 'assign';

    public function rows(): QueryBuilder
    {
        return DB::table('vt_teams')
            ->crossJoin('vt_calendar')
            ->leftJoin('vt_shifts', function ($join) {
                $join->on('vt_shifts.team_id', '=', 'vt_teams.id')
                    ->on('vt_shifts.day', '=', 'vt_calendar.day');
            })
            ->groupBy('vt_teams.id', 'vt_teams.region', 'vt_calendar.day')
            ->select([
                'vt_teams.id as team_id',
                'vt_teams.region',
                'vt_calendar.day',
                DB::raw('count(vt_shifts.id) as shift_count'),
            ]);
    }

    public function matchKey(RowConditionContext $c, mixed $teamId, mixed $day): ?BuilderContract
    {
        return $c->query
            ->where($c->column('team_id'), '=', $teamId)
            ->where($c->column('day'), '=', $day);
    }

    /** Reads a column the virtual table computes, as if it were stored. */
    #[RowCondition]
    public function isUnderstaffed(RowConditionContext $c, int $below = 2): BuilderContract
    {
        return $c->query->where($c->column('shift_count'), '<', $below);
    }

    #[RowCondition]
    public function inMyRegion(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('region'), '=', 'north');
    }
}

/**
 * A virtual table over a spine table, declaring the column its rows are
 * identified by. That is all the built-in key needs, so it declares no
 * matchKey() of its own — and its conditions may write column() bare.
 */
class VtSpineSchema extends QuerySchema
{
    protected function key(): string { return 'team_id'; }

    #[Ability]
    public const VIEW = 'view';

    public function rows(): QueryBuilder
    {
        return DB::table('vt_teams')
            ->select(['vt_teams.id as team_id', 'vt_teams.region']);
    }

    #[RowCondition]
    public function inMyRegion(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('region'), '=', 'north');
    }

    /** Names no column, so it leans on the declared key. */
    #[RowCondition]
    public function isNamedTeam(RowConditionContext $c, string $id): BuilderContract
    {
        return $c->query->where($c->column(), '=', $id);
    }
}

beforeEach(function () {
    Schema::create('vt_teams', function ($table) {
        $table->string('id');
        $table->string('region');
    });
    Schema::create('vt_calendar', fn ($table) => $table->string('day'));
    Schema::create('vt_shifts', function ($table) {
        $table->string('id');
        $table->string('team_id');
        $table->string('day');
    });

    useWarrantSchemas([
        'vt_teams' => VtTeamSchema::class,
        'vt_days' => VtDaySchema::class,
        'vt_spine' => VtSpineSchema::class,
    ]);
});

/** @param array<string, string> $syntaxByKey */
function bindVtRules(array $syntaxByKey, array $bindings = []): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantSyntax::parse(new BoundSyntax($syntax, $bindings))->validateWithoutSchemas()->scopedTo($key);
    }

    app()->instance(IRuleProvider::class, new class($sets) implements IRuleProvider
    {
        /** @param array<string, RuleSetNode> $sets */
        public function __construct(private array $sets) {}

        public function rules(RuleProviderContext $context): RuleSetNode
        {
            return $this->sets[$context->schemaKey] ?? new RuleSetNode(nameNode($context->schemaKey), [], SyntaxLocation::noProducer());
        }
    });

    Warrant::flush();
}

function vtDayGuard()
{
    return Warrant::guard(makeWarrantTestUser())->forSchema(new VtDaySchema);
}

function seedVtRows(): void
{
    DB::table('vt_teams')->insert([
        ['id' => 'north-1', 'region' => 'north'],
        ['id' => 'south-1', 'region' => 'south'],
    ]);
    DB::table('vt_calendar')->insert([['day' => '2026-09-14'], ['day' => '2026-09-15']]);
    DB::table('vt_shifts')->insert([
        ['id' => 's1', 'team_id' => 'north-1', 'day' => '2026-09-14'],
        ['id' => 's2', 'team_id' => 'north-1', 'day' => '2026-09-14'],
    ]);
}

// -- the rows themselves ------------------------------------------------------

it('selects from the query under the rows\' name', function () {
    seedVtRows();
    bindVtRules(['vt_days' => 'if in_my_region they can view']);

    $guard = vtDayGuard();
    $rows = $guard->filterQuery($guard->query(), 'view')->orderBy('warrant_rows.day')->get();

    // Both of the north team's days, and neither of the south team's.
    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('team_id')->all())->toBe(['north-1', 'north-1']);
    expect($rows[0]->shift_count)->toBe(2);
    expect($rows[1]->shift_count)->toBe(0);
});

it('filters on a column the query computes', function () {
    seedVtRows();
    bindVtRules(['vt_days' => 'if is_understaffed they can assign']);

    $guard = vtDayGuard();
    $rows = $guard->filterQuery($guard->query(), 'assign')->orderBy('warrant_rows.day')->get();

    // Only the days with fewer than two shifts: everything but north-1's 14th.
    expect($rows)->toHaveCount(3);
    expect(collect($rows)->every(fn ($row) => $row->shift_count < 2))->toBeTrue();
});

it('wraps the query as an aliased subquery in the emitted SQL', function () {
    bindVtRules(['vt_days' => 'if in_my_region they can view']);

    $guard = vtDayGuard();
    $sql = normalizeWarrantSql($guard->filterQuery($guard->query(), 'view')->toRawSql());

    expect($sql)->toContain('from (select');
    expect($sql)->toContain(') as "warrant_rows"');
    expect($sql)->toContain('"warrant_rows"."region" = \'north\'');
});

// -- addressing one of its rows -----------------------------------------------

it('checks one row through the schema key', function () {
    seedVtRows();
    bindVtRules(['vt_days' => 'if is_understaffed they can assign']);

    $guard = vtDayGuard();

    expect($guard->can('assign', ['north-1', '2026-09-15']))->toBeTrue();
    expect($guard->can('assign', ['north-1', '2026-09-14']))->toBeFalse();
    expect($guard->abilities(['north-1', '2026-09-15']))->toBe(['assign']);
});

it('carries a per-row abilities column with no key involved', function () {
    seedVtRows();
    bindVtRules(['vt_days' => 'if is_understaffed they can assign']);

    $guard = vtDayGuard();
    $rows = $guard->selectAbilitiesInQuery($guard->query()->orderBy('warrant_rows.day'))->get();

    expect($rows)->toHaveCount(4);
    expect(json_decode($rows[0]->abilities, true))->toBe([]);          // north-1, 14th
    expect(json_decode($rows[1]->abilities, true))->toBe(['assign']);  // south-1, 14th
});

// -- what a hop into one looks like -------------------------------------------

it('selects a hop into a query schema as a subquery', function () {
    bindVtRules([
        'vt_teams' => 'if can(assign for vt_days(@column id, @context day)) they can plan',
        'vt_days' => 'if is_understaffed they can assign',
    ]);

    $sql = normalizeWarrantSql(
        Warrant::guard(makeWarrantTestUser())
            ->forSchema(new VtTeamSchema)
            ->filterQuery(warrantTestQuery('vt_teams'), 'plan', context: ['day' => '2026-09-15'])
            ->toRawSql()
    );

    expect($sql)->toContain('exists (select * from (select');
    expect($sql)->toContain(') as "warrant_rows" where "warrant_rows"."team_id" = "vt_teams"."id"');
    expect($sql)->toContain('"warrant_rows"."day" = \'2026-09-15\'');
});

it('answers a hop against real rows', function () {
    seedVtRows();
    bindVtRules([
        'vt_teams' => 'if can(assign for vt_days(@column id, @context day)) they can plan',
        'vt_days' => 'if is_understaffed they can assign',
    ]);

    $planners = fn (string $day) => Warrant::guard(makeWarrantTestUser())
        ->forSchema(new VtTeamSchema)
        ->filterQuery(warrantTestQuery('vt_teams'), 'plan', context: ['day' => $day])
        ->pluck('id')
        ->all();

    // On the 14th north-1 is fully staffed, so only south-1 needs planning.
    expect($planners('2026-09-14'))->toBe(['south-1']);
    expect($planners('2026-09-15'))->toBe(['north-1', 'south-1']);
});

// -- what it gives up ---------------------------------------------------------

// -- a declared key column ----------------------------------------------------

it('addresses rows by a declared key with no matchKey of its own', function () {
    seedVtRows();
    bindVtRules(['vt_spine' => 'if in_my_region they can view']);

    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(new VtSpineSchema);

    expect($guard->can('view', 'north-1'))->toBeTrue();
    expect($guard->can('view', 'south-1'))->toBeFalse();
    expect($guard->abilities(['north-1']))->toBe(['view']);
});

it('compares the declared key column in the emitted SQL', function () {
    bindVtRules(['vt_spine' => 'if is_named_team(:id) they can view'], ['id' => 'north-1']);

    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(new VtSpineSchema);
    $sql = normalizeWarrantSql($guard->filterQuery($guard->query(), 'view')->toRawSql());

    // column() with no argument resolved to the declared key, under the rows' name.
    expect($sql)->toContain('"warrant_rows"."team_id" = \'north-1\'');
});

it('folds a targeted check whose declared key is given nothing', function () {
    seedVtRows();
    bindVtRules(['vt_spine' => 'if in_my_region they can view']);

    // The built-in key answers unknown for a null, exactly as over a model.
    expect(Warrant::guard(makeWarrantTestUser())->forSchema(new VtSpineSchema)->can('view', [null]))
        ->toBeFalse();
});

it('still refuses a targeted check against a schema with no rows at all', function () {
    useWarrantSchemas(['vt_cap' => VtCapabilitySchema::class]);
    bindVtRules(['vt_cap' => 'they can view']);

    expect(fn () => Warrant::guard(makeWarrantTestUser())->forSchema(new VtCapabilitySchema)->can('view', 'x'))
        ->toThrow(InvalidArgumentException::class, 'has no rows and does not support targeted checks');
});

/** No rows at all: a rowless schema. */
class VtCapabilitySchema extends RowlessSchema
{
    #[Ability]
    public const VIEW = 'view';
}
