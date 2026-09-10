<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
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
| matchKey — how a schema's rows are addressed
|------------------------------------------------------------------------------
|
| A handle's row selector is the argument list of the target schema's row key.
| The default key addresses a row by its primary key, which is what the single
| argument of `documents(@context id)` has always meant; a schema overrides
| matchKey() to address rows by something else — a natural key, or several
| columns where no single one is unique.
|
| The key is dispatched exactly as a row condition is, so it may follow an alias
| through $c->row() and may answer unknown by returning null. Its parameters
| decide how many arguments a handle must supply, and both the validator and the
| key's own dispatch enforce that.
|
*/

class MkShiftModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'mk_shifts';

    public static function warrantSchema(): string
    {
        return MkShiftSchema::class;
    }
}

class MkDayModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'mk_days';

    public static function warrantSchema(): string
    {
        return MkDaySchema::class;
    }
}

class MkPathModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'mk_paths';

    public static function warrantSchema(): string
    {
        return MkPathSchema::class;
    }
}

/** The caller. Keeps the default key. */
class MkShiftSchema extends WarrantSchema
{
    public const model = MkShiftModel::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const CREATE = 'create';
}

/** Addressed by (team_id, day) — no single column is unique. */
class MkDaySchema extends WarrantSchema
{
    public const model = MkDayModel::class;

    /** The alias the key saw, so a test can prove it follows the row. */
    public static ?string $seenRow = null;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const ASSIGN = 'assign';

    public function matchKey(RowConditionContext $c, mixed $teamId, mixed $day = 'today'): ?BuilderContract
    {
        static::$seenRow = $c->row('team_id');

        return $c->query
            ->where($c->row('team_id'), '=', $teamId)
            ->where($c->row('day'), '=', $day);
    }

    #[RowCondition]
    public function isOpen(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->row('is_open'), '=', 1);
    }
}

/**
 * Two required key parameters — the shape PHP would forbid if matchKey() were a
 * concrete method on WarrantSchema, since an override may not add required
 * parameters to an inherited signature.
 */
class MkStrictSchema extends WarrantSchema
{
    public const model = MkStrictModel::class;

    #[Ability]
    public const VIEW = 'view';

    public function matchKey(RowConditionContext $c, mixed $left, mixed $right): ?BuilderContract
    {
        return $c->query
            ->where($c->row('left_id'), '=', $left)
            ->where($c->row('right_id'), '=', $right);
    }
}

class MkStrictModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'mk_strict';

    public static function warrantSchema(): string
    {
        return MkStrictSchema::class;
    }
}

/** A variadic key: any number of path segments, including none. */
class MkPathSchema extends WarrantSchema
{
    public const model = MkPathModel::class;

    #[Ability]
    public const VIEW = 'view';

    /** @param mixed ...$segments */
    public function matchKey(RowConditionContext $c, mixed ...$segments): ?BuilderContract
    {
        return $c->query->where($c->row('path'), '=', implode('/', $segments));
    }
}

beforeEach(function () {
    Schema::create('mk_shifts', function ($table) {
        $table->string('id');
        $table->string('team_id');
        $table->string('work_date');
    });
    Schema::create('mk_days', function ($table) {
        $table->string('id');
        $table->string('team_id');
        $table->string('day');
        $table->integer('is_open');
    });
    Schema::create('mk_paths', fn ($table) => $table->string('path'));
    Schema::create('mk_strict', function ($table) {
        $table->string('left_id');
        $table->string('right_id');
    });

    MkDaySchema::$seenRow = null;

    useWarrantSchemas([
        'mk_shifts' => MkShiftSchema::class,
        'mk_days' => MkDaySchema::class,
        'mk_paths' => MkPathSchema::class,
        'mk_strict' => MkStrictSchema::class,
    ]);
});

/** @param array<string, string> $syntaxByKey */
function bindMkRules(array $syntaxByKey): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantRuleSet::fromSyntax($syntax, $key);
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

    /* Resolved rule sets are memoized per guard, so a test binding twice would
       otherwise compile the first set again. */
    Warrant::flush();
}

/** @param array<string, string> $otherSyntax */
function mkShiftSql(string $shiftSyntax, array $otherSyntax = [], string $ability = 'create', array $context = []): string
{
    bindMkRules(['mk_shifts' => $shiftSyntax, ...$otherSyntax]);

    return normalizeWarrantSql(
        Warrant::guard(makeWarrantTestUser())
            ->forSchema(new MkShiftSchema)
            ->filterQuery(warrantTestQuery('mk_shifts'), $ability, context: $context)
            ->toRawSql()
    );
}

// -- a key of several parts ---------------------------------------------------

it('emits one conjunct per key part in a row-bound hop', function () {
    expect(mkShiftSql(
        'if can(assign for mk_days(@column team_id, @column work_date)) they can create',
        ['mk_days' => 'if is_open they can assign'],
    ))->toBe(normalizeWarrantSql(<<<SQL
        select * from "mk_shifts" where (
            exists (
                select * from "mk_days"
                where "mk_days"."team_id" = "mk_shifts"."team_id"
                    and "mk_days"."day" = "mk_shifts"."work_date"
                    and ("mk_days"."is_open" = 1)
            )
        )
    SQL));
});

it('mixes a @context argument with a @column one', function () {
    expect(mkShiftSql(
        'if can(assign for mk_days(@context team, @column work_date)) they can create',
        ['mk_days' => 'if is_open they can assign'],
        context: ['team' => 'team-7'],
    ))->toBe(normalizeWarrantSql(<<<SQL
        select * from "mk_shifts" where (
            exists (
                select * from "mk_days"
                where "mk_days"."team_id" = 'team-7'
                    and "mk_days"."day" = "mk_shifts"."work_date"
                    and ("mk_days"."is_open" = 1)
            )
        )
    SQL));
});

it('hands the key the alias the hop named the row, not the table', function () {
    mkShiftSql(
        'if can(assign for mk_days(@column team_id, @column work_date) as d) they can create',
        ['mk_days' => 'if is_open they can assign'],
    );

    expect(MkDaySchema::$seenRow)->toBe('d.team_id');
});

// -- arity --------------------------------------------------------------------

it('accepts a handle that omits an argument with a default', function () {
    // matchKey's $day defaults, so one argument is enough and 'today' fills in.
    expect(mkShiftSql(
        'if can(assign for mk_days(@column team_id)) they can create',
        ['mk_days' => 'if is_open they can assign'],
    ))->toContain("\"mk_days\".\"day\" = 'today'");
});

it('accepts any number of arguments for a variadic key', function () {
    foreach ([
        "mk_paths('a')" => 'a',
        "mk_paths('a', 'b', 'c')" => 'a/b/c',
    ] as $handle => $expectedPath) {
        expect(mkShiftSql(
            "if can(view for {$handle}) they can create",
            ['mk_paths' => 'they can view'],
        ))->toContain("\"mk_paths\".\"path\" = '{$expectedPath}'");
    }
});

it('accepts empty parens when the key requires no arguments', function () {
    // A variadic key requires nothing, so mk_paths() addresses a row with no
    // arguments — distinct from bare mk_paths, which addresses no row at all.
    expect(mkShiftSql(
        'if can(view for mk_paths()) they can create',
        ['mk_paths' => 'they can view'],
    ))->toBe(normalizeWarrantSql(<<<SQL
        select * from "mk_shifts" where (
            exists (select * from "mk_paths" where "mk_paths"."path" = '' and (1 = 1))
        )
    SQL));
});

it('rejects empty parens when the key requires arguments', function () {
    expect(fn () => mkShiftSql(
        'if can(assign for mk_days()) they can create',
        ['mk_days' => 'if is_open they can assign'],
    ))->toThrow(InvalidArgumentException::class, 'supplies 0 row-key argument(s)');
});

it('rejects a handle supplying fewer arguments than the key requires', function () {
    // MkShiftSchema keeps the default key, which requires exactly one.
    expect(fn () => mkShiftSql(
        'if can(view for mk_shifts()) they can create',
        [],
    ))->toThrow(InvalidArgumentException::class, 'row key requires at least 1');
});

it('accepts a key declaring two required parameters', function () {
    expect(mkShiftSql(
        'if can(view for mk_strict(@column team_id, @column work_date)) they can create',
        ['mk_strict' => 'they can view'],
    ))->toBe(normalizeWarrantSql(<<<SQL
        select * from "mk_shifts" where (
            exists (
                select * from "mk_strict"
                where "mk_strict"."left_id" = "mk_shifts"."team_id"
                    and "mk_strict"."right_id" = "mk_shifts"."work_date"
                    and (1 = 1)
            )
        )
    SQL));
});

it('rejects a handle supplying one argument to a two-part key', function () {
    expect(fn () => mkShiftSql(
        'if can(view for mk_strict(@column team_id)) they can create',
        ['mk_strict' => 'they can view'],
    ))->toThrow(InvalidArgumentException::class, 'row key requires at least 2');
});

// -- nulls --------------------------------------------------------------------

it('folds the whole reference when the default key is given nothing', function () {
    /* The default key answers unknown for a null, and an unknown neither grants
       nor lifts a deny — the behaviour an absent @context has always had. */
    expect(mkShiftSql(
        'if can(view for mk_shifts(@context missing)) they can create',
        [],
    ))->toBe(normalizeWarrantSql('select * from "mk_shifts" where (null)'));
});

it('hands a null to a key that declared the parameter, letting it decide', function () {
    // MkDaySchema's key takes the null as its $teamId and compares against it.
    expect(mkShiftSql(
        'if can(assign for mk_days(@context missing, @column work_date)) they can create',
        ['mk_days' => 'if is_open they can assign'],
    ))->toContain('"mk_days"."team_id" is null');
});

it('rejects a literal null in any argument position', function () {
    expect(fn () => mkShiftSql(
        "if can(assign for mk_days('team-7', null)) they can create",
        ['mk_days' => 'if is_open they can assign'],
    ))->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

// -- the key is not vocabulary ------------------------------------------------

it('rejects a condition attribute on matchKey', function () {
    expect(fn () => MkAttributedKeySchema::keyDefinition())
        ->toThrow(InvalidArgumentException::class, 'declares a condition attribute on matchKey()');
});

it('does not expose the key as a condition a rule can name', function () {
    expect(MkDaySchema::conditionKeys())->not->toContain('match_key');

    expect(fn () => WarrantRuleSet::fromSyntax('if match_key they can view', 'mk_days')->validate())
        ->toThrow(InvalidArgumentException::class);
});

// -- addressing a row from PHP ------------------------------------------------

it('takes the key arguments as a target on the schema-bound guard', function () {
    DB::table('mk_days')->insert([
        ['id' => 'd1', 'team_id' => 'team-7', 'day' => '2026-09-14', 'is_open' => 1],
        ['id' => 'd2', 'team_id' => 'team-7', 'day' => '2026-09-15', 'is_open' => 0],
    ]);
    bindMkRules(['mk_days' => 'if is_open they can assign']);

    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(new MkDaySchema);

    expect($guard->can('assign', ['team-7', '2026-09-14']))->toBeTrue();
    expect($guard->can('assign', ['team-7', '2026-09-15']))->toBeFalse();
    expect($guard->abilities(['team-7', '2026-09-14']))->toBe(['assign']);
});

it('takes the key arguments inside a tuple target on the plain guard', function () {
    DB::table('mk_days')->insert(
        ['id' => 'd1', 'team_id' => 'team-7', 'day' => '2026-09-14', 'is_open' => 1]
    );
    bindMkRules(['mk_days' => 'if is_open they can assign']);

    expect(Warrant::guard(makeWarrantTestUser())->can('assign', [MkDaySchema::class, ['team-7', '2026-09-14']]))
        ->toBeTrue();
});

it('rejects an empty target array against a key that requires arguments', function () {
    bindMkRules(['mk_days' => 'if is_open they can assign']);

    expect(fn () => Warrant::guard(makeWarrantTestUser())->forSchema(new MkDaySchema)->can('assign', []))
        ->toThrow(InvalidArgumentException::class, 'requires at least 1 argument(s), but 0 were supplied');
});

it('takes an empty target array when the key requires no arguments', function () {
    /* The counterpart of `schema()` in rule text: a key taking no arguments
       addresses a row without any, so an empty list is a complete argument list
       rather than a no-target check. */
    DB::table('mk_paths')->insert([['path' => ''], ['path' => 'a/b']]);
    bindMkRules(['mk_paths' => 'they can view']);

    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(new MkPathSchema);

    expect($guard->can('view', []))->toBeTrue();
    expect($guard->can('view', ['a', 'b']))->toBeTrue();
    expect($guard->can('view', ['nope']))->toBeFalse();
});

/** Declares the key as a condition, which it is not. */
class MkAttributedKeySchema extends WarrantSchema
{
    public const model = MkPathModel::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function matchKey(RowConditionContext $c, mixed $key): ?BuilderContract
    {
        return $c->query->where($c->row(), '=', $key);
    }
}
