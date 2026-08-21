<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Warrant\Ability;
use Warrant\AbilityMatchMode;
use Warrant\ComputedAbility;
use Warrant\HasWarrantSchema;
use Warrant\Reachability;
use Warrant\Schema\ComputedAbilityContext;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantAuthorizationException;

// -- fixtures -----------------------------------------------------------------

class ComputedModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';
    public $incrementing = false;
    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return ComputedSchema::class;
    }
}

class ComputedSchema extends WarrantSchema
{
    public const model = ComputedModel::class;

    #[Ability] public const VIEW = 'view';

    // computed, needs context, returns bool
    #[ComputedAbility(requiredContext: ['as_of'])]
    public function audit(ComputedAbilityContext $c): bool
    {
        return $c->context['as_of'] >= '2026-01-01';
    }

    // computed, no context, returns a Response (message + allow/deny)
    #[ComputedAbility]
    public function publishReport(ComputedAbilityContext $c): Response
    {
        return $c->user->role_id === 'admin'
            ? Response::allow()
            : Response::deny('only admins can publish reports');
    }
}

class BadComputedSchema extends WarrantSchema
{
    public const model = ComputedModel::class;

    #[ComputedAbility]
    public function bad(string $notTheContext): bool
    {
        return true;
    }
}

class CollisionComputedSchema extends WarrantSchema
{
    public const model = ComputedModel::class;

    #[Ability] public const FOO = 'foo';

    #[ComputedAbility(name: 'foo')]
    public function whatever(ComputedAbilityContext $c): bool
    {
        return true;
    }
}

class NameDefaultComputedSchema extends WarrantSchema
{
    public const model = ComputedModel::class;

    #[ComputedAbility]
    public function createTimesheet(ComputedAbilityContext $c): bool
    {
        return true;
    }
}

function admin(): \Illuminate\Contracts\Auth\Authenticatable
{
    return makeWarrantTestUser('admin');
}

// -- named check --------------------------------------------------------------

it('answers a named computed ability by running its method', function () {
    expect(ComputedSchema::userHasAbilities('audit', user: admin(), context: ['as_of' => '2026-06-01']))->toBeTrue();
    expect(ComputedSchema::userHasAbilities('audit', user: admin(), context: ['as_of' => '2020-01-01']))->toBeFalse();
});

it('normalizes bool and Response returns', function () {
    expect(ComputedSchema::userHasAbilities('publish_report', user: admin()))->toBeTrue();
    expect(ComputedSchema::userHasAbilities('publish_report', user: makeWarrantTestUser('member')))->toBeFalse();
});

it('surfaces a computed denial Response message through authorize', function () {
    ComputedSchema::authorize('publish_report', user: admin()); // allowed → no throw

    expect(fn () => ComputedSchema::authorize('publish_report', user: makeWarrantTestUser('member')))
        ->toThrow(WarrantAuthorizationException::class, 'only admins can publish reports');
});

// -- required context ---------------------------------------------------------

it('throws when a named computed ability is missing its required context', function () {
    expect(fn () => ComputedSchema::userHasAbilities('audit', user: admin()))
        ->toThrow(InvalidArgumentException::class, 'Ability [audit] requires context key(s) [as_of]');
});

// -- constraints --------------------------------------------------------------

it('rejects a computed ability named against a concrete target', function () {
    expect(fn () => ComputedSchema::userHasAbilities('audit', 'some-id', admin(), context: ['as_of' => '2026-06-01']))
        ->toThrow(InvalidArgumentException::class, 'cannot be checked against a target');
});

// -- mixed computed + compiled checks -----------------------------------------

it('combines computed and compiled abilities in one no-target check', function () {
    useWarrantSchemas([ComputedSchema::class]);
    bindWarrantRules('they can view');

    // ALL: view (granted to everyone) and publish_report (admin only) both hold
    expect(ComputedSchema::userHasAbilities(['view', 'publish_report'], user: admin()))->toBeTrue();

    // ALL: publish_report is denied for a member, so the whole check fails
    expect(ComputedSchema::userHasAbilities(['view', 'publish_report'], user: makeWarrantTestUser('member')))
        ->toBeFalse();

    // ANY: the member still holds view even though publish_report is denied
    expect(ComputedSchema::userHasAbilities(
        ['view', 'publish_report'],
        user: makeWarrantTestUser('member'),
        matchMode: AbilityMatchMode::ANY,
    ))->toBeTrue();
});

it('surfaces the computed denial message when a mixed ALL check fails on the computed half', function () {
    useWarrantSchemas([ComputedSchema::class]);
    bindWarrantRules('they can view');

    // view is granted; publish_report is the failing half → its Response message wins
    expect(fn () => ComputedSchema::authorize(['view', 'publish_report'], user: makeWarrantTestUser('member')))
        ->toThrow(WarrantAuthorizationException::class, 'only admins can publish reports');
});

// -- list behavior ------------------------------------------------------------

it('excludes computed abilities from the no-target list', function () {
    useWarrantSchemas([ComputedSchema::class]);
    bindWarrantRules('they can view');

    // Only the compiled ability is listed, regardless of context or whether the
    // computed abilities would have been allowed.
    expect(ComputedModel::getUserAbilities(null, admin(), ['as_of' => '2026-06-01']))->toBe(['view']);
    expect(ComputedModel::getUserAbilities(null, admin()))->toBe(['view']);
    expect(ComputedModel::getUserAbilities(null, makeWarrantTestUser('member'), ['as_of' => '2026-06-01']))
        ->toBe(['view']);
});

// -- reachability -------------------------------------------------------------

it('treats a named computed ability as MAYBE in reachability, and omits it from lists', function () {
    useWarrantSchemas([ComputedSchema::class]);
    bindWarrantRules('they can view');

    expect(ComputedSchema::abilityReachability('publish_report', admin()))->toBe(Reachability::MAYBE);
    expect(ComputedSchema::userCouldEverHave('publish_report', admin()))->toBeTrue();
    expect(ComputedSchema::userAlwaysHas('publish_report', admin()))->toBeFalse();
    expect(ComputedSchema::userNeverHas('publish_report', admin()))->toBeFalse();

    // enumeration stays compiled-only
    expect(ComputedSchema::getUserPossibleAbilities(admin()))->not->toContain('publish_report');
});

// -- query scopes reject computed ---------------------------------------------

it('rejects a computed ability named in a query scope', function () {
    useWarrantSchemas([ComputedSchema::class]);
    bindWarrantRules('they can view');

    expect(fn () => ComputedModel::query()->userHasAbility('publish_report', admin()))
        ->toThrow(InvalidArgumentException::class, 'cannot be used in a query scope');

    expect(fn () => ComputedModel::query()->selectUserAbilities(admin(), 'abilities', ['publish_report']))
        ->toThrow(InvalidArgumentException::class, 'cannot be used in a query scope');
});

it('excludes computed abilities from the per-row list', function () {
    useWarrantSchemas([ComputedSchema::class]);
    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'row-1']]);
    bindWarrantRules('they can view');

    $row = ComputedModel::query()->find('row-1');

    // getUserAbilities($row) is per-row SQL → only the declared ability
    expect(ComputedModel::getUserAbilities($row, admin(), ['as_of' => '2026-06-01']))->toBe(['view']);

    // selectUserAbilities never lists a computed ability
    $rows = ComputedModel::query()
        ->selectUserAbilities(admin(), 'abilities', null, ['as_of' => '2026-06-01'])
        ->get();
    expect(json_decode($rows[0]->abilities, true))->toBe(['view']);
});

// -- projections --------------------------------------------------------------

it('projects declared vs computed abilities off the definitions', function () {
    expect(ComputedSchema::nonComputedAbilityNames())->toBe(['view']);
    expect(ComputedSchema::isComputedAbility('audit'))->toBeTrue();
    expect(ComputedSchema::isComputedAbility('publish_report'))->toBeTrue();
    expect(ComputedSchema::isComputedAbility('view'))->toBeFalse();
});

// -- reflection validation ----------------------------------------------------

it('defaults a computed ability name to the snake-cased method name', function () {
    expect(NameDefaultComputedSchema::isComputedAbility('create_timesheet'))->toBeTrue();
});

it('rejects a computed method with the wrong parameter type', function () {
    expect(fn () => BadComputedSchema::nonComputedAbilityNames())
        ->toThrow(InvalidArgumentException::class, 'must accept exactly one');
});

it('rejects a computed name that collides with a declared ability', function () {
    expect(fn () => CollisionComputedSchema::nonComputedAbilityNames())
        ->toThrow(InvalidArgumentException::class, 'declares ability [foo] more than once');
});

// -- Gate bridge --------------------------------------------------------------

it('resolves a computed ability through the Gate bridge', function () {
    useWarrantSchemas([ComputedSchema::class]);

    expect(Gate::forUser(admin())->allows('publish_report', ComputedSchema::class))->toBeTrue();
    expect(Gate::forUser(makeWarrantTestUser('member'))->allows('publish_report', ComputedSchema::class))->toBeFalse();
    expect(Gate::forUser(admin())->allows('audit', [ComputedModel::class, ['as_of' => '2026-06-01']]))->toBeTrue();
});

it('rejects a computed ability through the Gate bridge when given a model instance', function () {
    useWarrantSchemas([ComputedSchema::class]);
    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'row-1']]);

    $row = ComputedModel::query()->find('row-1');

    // A model instance is a concrete row target — a computed ability can't answer it.
    expect(fn () => Gate::forUser(admin())->allows('publish_report', $row))
        ->toThrow(InvalidArgumentException::class, 'cannot be checked against a target');
});
