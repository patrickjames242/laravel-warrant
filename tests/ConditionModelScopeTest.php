<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| A row condition reusing its model's query scopes
|------------------------------------------------------------------------------
|
| A condition is handed an Eloquent builder over its schema's model, wrapping the
| very where clause the compiler reads back. So a condition may spend a scope the
| model already defines instead of restating its SQL, and the constraints land
| where a hand-written $c->query->where() would.
|
| Two properties of that wrapper are worth pinning, because both are silent when
| they break. The model answers to whatever the compiler named the row, so a scope
| that qualifies through the model follows an alias. And the wrapper carries no
| global scopes: a condition answers the question it was asked, not whatever the
| model would otherwise volunteer.
|
*/

class ScopeShoutingGlobalScope implements Scope
{
    public function apply(EloquentBuilder $builder, Model $model): void
    {
        $builder->whereRaw('global_scope_leaked = 1');
    }
}

class ConditionScopeModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ConditionScopeSchema::class;
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new ScopeShoutingGlobalScope);
    }

    /** Hard-codes its table, the way most scopes in the wild do. */
    public function scopePublished($query)
    {
        return $query->where('course_sections.state', 'published');
    }

    /** Qualifies through the model, the usual way a scope names its columns. */
    public function scopeOwnedBy($query, string $userId)
    {
        return $query->where($this->qualifyColumn('owner_id'), $userId);
    }

    /**
     * The alias-capable shape: the caller names the row, because only the caller
     * knows what it is called there. Defaults to the table for ordinary use.
     */
    public function scopeOwnedByIn($query, string $userId, ?string $as = null)
    {
        return $query->where(($as ?? $this->getTable()).'.owner_id', $userId);
    }

    /** Built on warrantQualifyColumn, so it follows whatever the row is called. */
    public function scopeOwnedByFollowingRow($query, string $userId)
    {
        return $query->where($this->warrantQualifyColumn('owner_id'), $userId);
    }

    /** Reads getTable() to build a subquery — the reason it must stay true. */
    public function scopeHasSibling($query)
    {
        $table = $this->getTable();

        return $query->whereExists(function ($sub) use ($table) {
            $sub->selectRaw('1')->from($table)->whereColumn("{$table}.parent_id", "{$table}.id");
        });
    }
}

class ConditionScopeSchema extends WarrantSchema
{
    public const model = ConditionScopeModel::class;

    /** SQL of the builder a condition was handed, unwrapped the way the library unwraps it. */
    public static ?string $seenBaseSql = null;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isPublished(RowConditionContext $c): BuilderContract
    {
        static::$seenBaseSql = $c->query->toBase()->toSql();

        return $c->query->published();
    }

    #[RowCondition]
    public function isOwned(RowConditionContext $c): BuilderContract
    {
        return $c->query->ownedBy($c->user->getAuthIdentifier());
    }

    #[RowCondition]
    public function hasSibling(RowConditionContext $c): BuilderContract
    {
        return $c->query->hasSibling();
    }

    #[RowCondition]
    public function isOwnedAliasSafe(RowConditionContext $c): BuilderContract
    {
        return $c->query->ownedByIn($c->user->getAuthIdentifier(), $c->table);
    }

    #[RowCondition]
    public function isOwnedFollowingRow(RowConditionContext $c): BuilderContract
    {
        return $c->query->ownedByFollowingRow($c->user->getAuthIdentifier());
    }
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => ConditionScopeSchema::class]);
});

it('spends a model scope from inside a condition', function () {
    bindWarrantRules('if is_published they can view');

    $sql = Warrant::guard(makeWarrantTestUser())->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery(),
        'view',
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "course_sections"
        where ("course_sections"."state" = 'published')
    SQL));
});

it('hands the condition a builder carrying none of the model\'s global scopes', function () {
    bindWarrantRules('if is_published they can view');
    ConditionScopeSchema::$seenBaseSql = null;

    Warrant::guard(makeWarrantTestUser())->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery(),
        'view',
    )->toRawSql();

    /* Asserted on toBase(), because that is what applies pending scopes — and
       what the library itself calls when unwrapping a condition's return. A
       builder made with newQuery() instead of newModelQuery() would surface the
       fixture's global scope right here. Asserting on the finished SQL would
       not: applyScopes() works on a clone, so the leak never reaches the query
       the compiler reads, and the test would pass either way. */
    expect(ConditionScopeSchema::$seenBaseSql)
        ->toBeString()
        ->not->toContain('global_scope_leaked');
});

it('keeps the model\'s global scopes out of the compiled query', function () {
    bindWarrantRules('if is_published they can view');

    $sql = Warrant::guard(makeWarrantTestUser())->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery(),
        'view',
    )->toRawSql();

    expect($sql)->not->toContain('global_scope_leaked');
});

it('speaks the model\'s table in a scope, even where the row is named otherwise', function () {
    bindWarrantRules('if is_owned they can view');

    /* The model keeps its own table rather than being retargeted at the alias:
       getTable() has to stay true for a scope that reads it to build a subquery.
       So a scope reached through an alias names a table the query does not have
       — a scope meeting an alias, failing as it would anywhere else. A condition
       that must follow the row's name uses row() instead. */
    $sql = Warrant::guard(makeWarrantTestUser('user-7'))->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery('course_sections as cs'),
        'view',
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "course_sections" as "cs"
        where ("course_sections"."owner_id" = 'user-7')
    SQL));
});

it('leaves a scope\'s own subquery pointed at a real table', function () {
    bindWarrantRules('if has_sibling they can view');

    /* The regression this guards: retargeting the model at the alias would put
       the alias in the subquery's FROM, selecting from a name that exists only
       in the enclosing query. */
    $sql = Warrant::guard(makeWarrantTestUser())->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery('course_sections as cs'),
        'view',
    )->toRawSql();

    expect($sql)->toContain('from "course_sections" where')
        ->and($sql)->not->toContain('from "cs" where');
});

it('still hands the condition something the compiler can read back', function () {
    bindWarrantRules('if is_published they can view');

    /* The condition returns the Eloquent wrapper, since that is what its own
       calls gave back. Callers are promised the query builder. */
    $returned = (new ConditionScopeSchema)->applyCondition(
        'is_published',
        makeWarrantTestUser(),
        warrantTestQuery(),
        targeted: true,
        parameters: [],
    );

    expect($returned)->toBeInstanceOf(Illuminate\Database\Query\Builder::class);
});


it('lets a scope follow the row when the condition hands it the row\'s name', function () {
    bindWarrantRules('if is_owned_alias_safe they can view');

    /* The library cannot make an arbitrary scope alias-aware, but a condition
       knows the row's name and a scope can take it. This is the shape to reach
       for when a scope has to work under an alias. */
    $sql = Warrant::guard(makeWarrantTestUser('user-7'))->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery('course_sections as cs'),
        'view',
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "course_sections" as "cs"
        where ("cs"."owner_id" = 'user-7')
    SQL));
});

it('spends a model scope on a targeted check, not just a filtered query', function () {
    bindWarrantRules('if is_published they can view');

    /* filterQuery compiles against many rows; a check names one. Both reach the
       same condition, so the scope has to survive the second path too. */
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema((new ConditionScopeSchema));

    expect(fn () => $guard->can('view', 'row-1'))->not->toThrow(Throwable::class);
});

it('spends a model scope when the per-row ability list is selected', function () {
    bindWarrantRules('if is_published they can view');

    $sql = Warrant::guard(makeWarrantTestUser())->forSchema((new ConditionScopeSchema))
        ->selectAbilitiesInQuery(warrantTestQuery())
        ->toRawSql();

    expect($sql)->toContain('published');
});

it('follows the row in a scope built on warrantQualifyColumn', function () {
    bindWarrantRules('if is_owned_following_row they can view');

    /* The scope takes no extra argument: the model was told what its rows are
       called before the condition ran. */
    $sql = Warrant::guard(makeWarrantTestUser('user-7'))->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery('course_sections as cs'),
        'view',
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "course_sections" as "cs"
        where ("cs"."owner_id" = 'user-7')
    SQL));
});

it('names the table in warrantQualifyColumn when nothing renamed the row', function () {
    bindWarrantRules('if is_owned_following_row they can view');

    $sql = Warrant::guard(makeWarrantTestUser('user-7'))->forSchema((new ConditionScopeSchema))->filterQuery(
        warrantTestQuery(),
        'view',
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "course_sections"
        where ("course_sections"."owner_id" = 'user-7')
    SQL));
});

it('names the table in warrantQualifyColumn outside a condition entirely', function () {
    /* Nothing has stamped the model, so it answers with the table — the state an
       ordinary Eloquent caller sees. */
    expect((new ConditionScopeModel)->warrantQualifyColumn('owner_id'))
        ->toBe('course_sections.owner_id')
        ->and((new ConditionScopeModel)->warrantQualifyColumn())
        ->toBe('course_sections.id')
        ->and((new ConditionScopeModel)->warrantQualifyColumn('other.owner_id'))
        ->toBe('other.owner_id');
});

it('keeps the rule alias off the model\'s attributes', function () {
    /* The alias lives in a declared property. Were it undeclared, assigning it
       would fall to Eloquent's __set and land in $attributes — where a column of
       the same name would collide with it, and where it would ride along into
       toArray(), fills and saves. Everything above would still pass: __get hands
       the value straight back, so the feature works by accident right up until
       something else reads the model. */
    $model = new ConditionScopeModel;
    $model->setWarrantCurrentRuleAlias('cs');

    expect((new ReflectionClass($model))->hasProperty('warrantCurrentRuleAlias'))->toBeTrue()
        ->and($model->getAttributes())->not->toHaveKey('warrantCurrentRuleAlias')
        ->and($model->toArray())->not->toHaveKey('warrantCurrentRuleAlias')
        ->and($model->warrantQualifyColumn('owner_id'))->toBe('cs.owner_id');
});
