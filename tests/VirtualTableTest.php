<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| virtualTable — a schema whose rows come from a query
|------------------------------------------------------------------------------
|
| A schema draws its rows from a model's table or from a virtualTable(), never
| both. A virtual table is a database view defined in the schema instead of in
| DDL: the compiler selects from it as a subquery, aliased to the schema's key,
| so conditions and rules read exactly as they do over a table.
|
| What such a schema gives up is everything a model was buying beyond the rows
| themselves: there are no Eloquent scopes to spend, no hydrated $c->model, and
| no primary key — so it must address its rows with a matchKey() of its own, and
| a condition names its columns through row('<column>').
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

class VtTeamSchema extends WarrantSchema
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
class VtDaySchema extends WarrantSchema
{
    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const ASSIGN = 'assign';

    public static function virtualTable(): ?QueryBuilder
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
            ->where($c->row('team_id'), '=', $teamId)
            ->where($c->row('day'), '=', $day);
    }

    /** Reads a column the virtual table computes, as if it were stored. */
    #[RowCondition]
    public function isUnderstaffed(RowConditionContext $c, int $below = 2): BuilderContract
    {
        return $c->query->where($c->row('shift_count'), '<', $below);
    }

    #[RowCondition]
    public function inMyRegion(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->row('region'), '=', 'north');
    }
}

/**
 * A virtual table over a spine table, declaring the column its rows are
 * identified by. That is all the built-in key needs, so it declares no
 * matchKey() of its own — and its conditions may write row() bare.
 */
class VtSpineSchema extends WarrantSchema
{
    public const key = 'team_id';

    #[Ability]
    public const VIEW = 'view';

    public static function virtualTable(): ?QueryBuilder
    {
        return DB::table('vt_teams')
            ->select(['vt_teams.id as team_id', 'vt_teams.region']);
    }

    #[RowCondition]
    public function inMyRegion(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->row('region'), '=', 'north');
    }

    /** Names no column, so it leans on the declared key. */
    #[RowCondition]
    public function isNamedTeam(RowConditionContext $c, string $id): BuilderContract
    {
        return $c->query->where($c->row(), '=', $id);
    }
}

/** A virtual table declaring no key, so row() has no column to use. */
class VtKeylessSchema extends WarrantSchema
{
    #[Ability]
    public const VIEW = 'view';

    public static function virtualTable(): ?QueryBuilder
    {
        return DB::table('vt_teams')->select(['vt_teams.id as team_id']);
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
        'vt_keyless' => VtKeylessSchema::class,
        'vt_spine' => VtSpineSchema::class,
    ]);
});

/** @param array<string, string> $syntaxByKey */
function bindVtRules(array $syntaxByKey, array $bindings = []): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantRuleSet::fromSyntax($syntax, $key, $bindings);
    }

    app()->instance(RuleResolver::class, new class($sets) implements RuleResolver
    {
        /** @param array<string, WarrantRuleSet> $sets */
        public function __construct(private array $sets) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $this->sets[$context->schemaKey] ?? new WarrantRuleSet($context->schemaKey, []);
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

it('selects from the virtual table under the schema key', function () {
    seedVtRows();
    bindVtRules(['vt_days' => 'if in_my_region they can view']);

    $guard = vtDayGuard();
    $rows = $guard->filterQuery($guard->query(), 'view')->orderBy('vt_days.day')->get();

    // Both of the north team's days, and neither of the south team's.
    expect($rows)->toHaveCount(2);
    expect(collect($rows)->pluck('team_id')->all())->toBe(['north-1', 'north-1']);
    expect($rows[0]->shift_count)->toBe(2);
    expect($rows[1]->shift_count)->toBe(0);
});

it('filters on a column the virtual table computes', function () {
    seedVtRows();
    bindVtRules(['vt_days' => 'if is_understaffed they can assign']);

    $guard = vtDayGuard();
    $rows = $guard->filterQuery($guard->query(), 'assign')->orderBy('vt_days.day')->get();

    // Only the days with fewer than two shifts: everything but north-1's 14th.
    expect($rows)->toHaveCount(3);
    expect(collect($rows)->every(fn ($row) => $row->shift_count < 2))->toBeTrue();
});

it('wraps the virtual table as an aliased subquery in the emitted SQL', function () {
    bindVtRules(['vt_days' => 'if in_my_region they can view']);

    $guard = vtDayGuard();
    $sql = normalizeWarrantSql($guard->filterQuery($guard->query(), 'view')->toRawSql());

    expect($sql)->toContain('from (select');
    expect($sql)->toContain(') as "vt_days"');
    expect($sql)->toContain('"vt_days"."region" = \'north\'');
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
    $rows = $guard->selectAbilitiesInQuery($guard->query()->orderBy('vt_days.day'))->get();

    expect($rows)->toHaveCount(4);
    expect(json_decode($rows[0]->abilities, true))->toBe([]);          // north-1, 14th
    expect(json_decode($rows[1]->abilities, true))->toBe(['assign']);  // south-1, 14th
});

// -- what a hop into one looks like -------------------------------------------

it('selects a hop into a virtual table as a subquery', function () {
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
    expect($sql)->toContain(') as "vt_days" where "vt_days"."team_id" = "vt_teams"."id"');
    expect($sql)->toContain('"vt_days"."day" = \'2026-09-15\'');
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

    // row() with no argument resolved to the declared key, under the schema key.
    expect($sql)->toContain('"vt_spine"."team_id" = \'north-1\'');
});

it('folds a targeted check whose declared key is given nothing', function () {
    seedVtRows();
    bindVtRules(['vt_spine' => 'if in_my_region they can view']);

    // The built-in key answers unknown for a null, exactly as over a model.
    expect(Warrant::guard(makeWarrantTestUser())->forSchema(new VtSpineSchema)->can('view', [null]))
        ->toBeFalse();
});

it('rejects a schema that names a model and also declares a key', function () {
    useWarrantSchemas(['vt_keyed_model' => VtKeyedModelSchema::class]);

    expect(fn () => Warrant::registry()->resolveSchemaClassOrFail('vt_keyed_model'))
        ->toThrow(LogicException::class, 'answers for its own key');
});

it('refuses a targeted check against rows it cannot name', function () {
    bindVtRules(['vt_keyless' => 'they can view']);

    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(new VtKeylessSchema);

    // Refused up front, rather than failing later on a key that was never there.
    expect(fn () => $guard->can('view', 'anything'))
        ->toThrow(InvalidArgumentException::class, 'has rows but no way to name one');
});

it('refuses a row-bound reference to rows it cannot name', function () {
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if can(view for vt_keyless(@column id)) they can plan',
        'vt_teams',
    )->validate())->toThrow(InvalidArgumentException::class, 'has no way to name one');
});

it('still filters and lists abilities for rows it cannot name', function () {
    /* Why a keyless virtual table is allowed at all: neither of these needs to
       name a row, because both correlate against rows the surrounding query
       already produced. */
    seedVtRows();
    bindVtRules(['vt_keyless' => 'they can view']);

    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(new VtKeylessSchema);

    expect($guard->filterQuery($guard->query(), 'view')->get())->toHaveCount(2);

    $rows = $guard->selectAbilitiesInQuery($guard->query())->get();
    expect(json_decode($rows[0]->abilities, true))->toBe(['view']);

    // And a no-target check is unaffected.
    expect($guard->can('view'))->toBeTrue();
});

it('rejects a schema that names both a model and a virtual table', function () {
    useWarrantSchemas(['vt_both' => VtBothSchema::class]);

    expect(fn () => Warrant::registry()->resolveSchemaClassOrFail('vt_both'))
        ->toThrow(LogicException::class, 'draws its rows from one or the other');
});

it('still refuses a targeted check against a schema with no rows at all', function () {
    useWarrantSchemas(['vt_cap' => VtCapabilitySchema::class]);
    bindVtRules(['vt_cap' => 'they can view']);

    expect(fn () => Warrant::guard(makeWarrantTestUser())->forSchema(new VtCapabilitySchema)->can('view', 'x'))
        ->toThrow(InvalidArgumentException::class, 'has no rows and does not support targeted checks');
});

/** Names both sources, which is the one thing a schema may not do. */
class VtBothSchema extends WarrantSchema
{
    public const model = VtTeamModel::class;

    #[Ability]
    public const VIEW = 'view';

    public static function virtualTable(): ?QueryBuilder
    {
        return DB::table('vt_teams');
    }
}

/** A model and a key both: the model's own key already answers. */
class VtKeyedModelSchema extends WarrantSchema
{
    public const model = VtTeamModel::class;

    public const key = 'id';

    #[Ability]
    public const VIEW = 'view';
}

/** Neither source: a capability schema, unchanged by any of this. */
class VtCapabilitySchema extends WarrantSchema
{
    #[Ability]
    public const VIEW = 'view';
}
