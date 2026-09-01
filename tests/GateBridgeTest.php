<?php

use Warrant\Facades\Warrant;

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Warrant\Ability;
use Warrant\GlobalCondition;
use Warrant\HasWarrantSchema;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\WarrantSchema;
use Warrant\RowCondition;

// -- fixtures -----------------------------------------------------------------

class GateTestModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return GateTestSchema::class;
    }
}

class GateTestSchema extends WarrantSchema
{
    public const model = GateTestModel::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const PUBLISH = 'publish';

    #[Ability]
    public const APPROVE = 'approve';

    #[RowCondition]
    public function isTeacher(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row()} = ?", ["teacher:{$c->user->role_id}"]);
    }

    #[GlobalCondition]
    public function regionAllowed(GlobalConditionContext $c): bool
    {
        return ($c->context['region'] ?? null) === 'us';
    }
}

/** A model with no Warrant schema at all. */
class GateNoSchemaModel extends Model
{
    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';
}

/** A plain Laravel policy the bridge must fall through to. */
class GatePassthroughPolicy
{
    public function export($user, GateTestModel $model): bool
    {
        return true;
    }

    public function anything($user, GateNoSchemaModel $model): bool
    {
        return true;
    }
}

// -- helpers ------------------------------------------------------------------

function seedGateSections(): void
{
    Schema::create('course_sections', fn ($table) => $table->string('id'));

    DB::table('course_sections')->insert([
        ['id' => 'teacher:teacher-role'],
        ['id' => 'other-section'],
    ]);
}

/** Register GateTestSchema and bind a standard rule set the tests share. */
function bootGateSchema(): void
{
    useWarrantSchemas(['course_sections' => GateTestSchema::class]);
    bindWarrantRules('they can publish if is_teacher they can view if region_allowed they can approve');
}

function gateTeacherRow(): GateTestModel
{
    return GateTestModel::query()->find('teacher:teacher-role');
}

function gateOtherRow(): GateTestModel
{
    return GateTestModel::query()->find('other-section');
}

// -- targeted checks ----------------------------------------------------------

it('resolves a targeted grant/deny through the Gate', function () {
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');

    expect(Gate::forUser($user)->allows('view', gateTeacherRow()))->toBeTrue();
    expect(Gate::forUser($user)->allows('view', gateOtherRow()))->toBeFalse();
    expect(Gate::forUser($user)->denies('view', gateOtherRow()))->toBeTrue();
});

it('agrees with the model check for a targeted ability', function () {
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');
    $row = gateTeacherRow();

    expect(Gate::forUser($user)->allows('view', $row))->toBe(Warrant::can('view', $row, user: $user));
});

// -- ALL / ANY across abilities (native Laravel) ------------------------------

it('supports ALL via check() and ANY via any()', function () {
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');

    // teacher row: publish (unconditional) + view (teacher) both hold
    expect(Gate::forUser($user)->check(['publish', 'view'], gateTeacherRow()))->toBeTrue();

    // other row: view fails, so ALL fails but ANY still passes on publish
    expect(Gate::forUser($user)->check(['publish', 'view'], gateOtherRow()))->toBeFalse();
    expect(Gate::forUser($user)->any(['publish', 'view'], gateOtherRow()))->toBeTrue();
});

// -- context passthrough ------------------------------------------------------

it('passes check-time context through a targeted call', function () {
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');

    expect(Gate::forUser($user)->allows('approve', [gateTeacherRow(), ['region' => 'us']]))->toBeTrue();
    expect(Gate::forUser($user)->allows('approve', [gateTeacherRow(), ['region' => 'eu']]))->toBeFalse();
});

// -- no-target checks by class ------------------------------------------------

it('resolves a no-target check via the model class', function () {
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');

    expect(Gate::forUser($user)->allows('publish', GateTestModel::class))->toBeTrue();
});

it('resolves a no-target check via the schema class', function () {
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');

    expect(Gate::forUser($user)->allows('publish', GateTestSchema::class))->toBeTrue();
});

it('passes context to a no-target check by class', function () {
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');

    expect(Gate::forUser($user)->allows('approve', [GateTestModel::class, ['region' => 'us']]))->toBeTrue();
    expect(Gate::forUser($user)->allows('approve', [GateTestModel::class, ['region' => 'eu']]))->toBeFalse();
});

// -- fall-through to policies -------------------------------------------------

it('falls through to a policy for an ability the schema does not declare', function () {
    seedGateSections();
    bootGateSchema();
    Gate::policy(GateTestModel::class, GatePassthroughPolicy::class);
    $user = makeWarrantTestUser('teacher-role');

    // `export` is not a declared Warrant ability -> the policy answers.
    expect(Gate::forUser($user)->allows('export', gateTeacherRow()))->toBeTrue();
});

it('falls through to a policy for a model with no schema', function () {
    seedGateSections();
    bootGateSchema();
    Gate::policy(GateNoSchemaModel::class, GatePassthroughPolicy::class);
    $user = makeWarrantTestUser('teacher-role');

    $row = new GateNoSchemaModel;
    $row->id = 'x';

    expect(Gate::forUser($user)->allows('anything', $row))->toBeTrue();
});

// -- opt out ------------------------------------------------------------------

it('does not resolve Warrant abilities when register_gate is false', function () {
    config()->set('warrant.register_gate', false);
    seedGateSections();
    bootGateSchema();
    $user = makeWarrantTestUser('teacher-role');

    // Warrant would grant publish, but the hook is disabled and no policy exists.
    expect(Gate::forUser($user)->allows('publish', GateTestModel::class))->toBeFalse();
    expect(Gate::forUser($user)->allows('view', gateTeacherRow()))->toBeFalse();
});

// -- denial message surfaced through the Gate ---------------------------------

it('carries the Warrant denial message through inspect/authorize', function () {
    seedGateSections();
    useWarrantSchemas(['course_sections' => GateTestSchema::class]);
    bindWarrantRuleSet(WarrantRuleSet::fromRules(GateTestSchema::class, [
        WarrantRule::build()->theyCan('view')->toRule(),
        WarrantRule::build()->if('is_teacher')
            ->theyCannotBecause('view', 'teacher blocked')->toRule(),
    ]));
    $user = makeWarrantTestUser('teacher-role');

    $response = Gate::forUser($user)->inspect('view', gateTeacherRow());

    expect($response->allowed())->toBeFalse();
    expect($response->message())->toBe('teacher blocked');

    expect(fn () => Gate::forUser($user)->authorize('view', gateTeacherRow()))
        ->toThrow(AuthorizationException::class, 'teacher blocked');
});

// -- guests -------------------------------------------------------------------

it('skips the bridge for a guest (falls through)', function () {
    seedGateSections();
    bootGateSchema();

    // No authenticated user: the non-nullable-typed before hook is skipped, and
    // with no policy the ability is simply not granted.
    expect(Gate::allows('view', gateTeacherRow()))->toBeFalse();
});
