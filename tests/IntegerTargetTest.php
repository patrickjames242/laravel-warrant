<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Facades\Warrant;
use Warrant\Guard\WarrantGuardForSchema;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantAuthorizationException;
require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| Integer keys as check targets
|------------------------------------------------------------------------------
|
| A bare scalar target means different things at different levels, which is why
| int support lives where it does:
|   - on the *schema-bound* guard a scalar is a row key, so an int is meaningful
|     and resolveCheckTarget() passes it through to the query untouched;
|   - on the schema-*less* facade a scalar names the schema itself, so an int
|     could never be valid there — the `[Model::class, $id]` tuple is how a
|     schema-less check names a row, and WarrantGuard::splitTarget() has always
|     accepted an int id in that position.
|
| This file declares strict_types deliberately. PHP decides argument coercion by
| the *calling* file, so before the target unions were widened, an int target
| from a strict-typed caller was a TypeError while the identical call from a
| coercive file silently worked. Every check below would have thrown.
|
*/

class IntKeyedSectionModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'int_sections';

    public $timestamps = false;

    protected $guarded = [];

    public static function warrantSchema(): string
    {
        return IntKeyedSectionSchema::class;
    }
}

class IntKeyedSectionSchema extends WarrantSchema
{
    public const model = IntKeyedSectionModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[Ability]
    public const ABILITY_UPDATE = 'update';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row()} = ?", [1]);
    }
}

beforeEach(function () {
    useWarrantSchemas(['int_sections' => IntKeyedSectionSchema::class]);

    Schema::create('int_sections', function ($table) {
        $table->increments('id');
    });

    DB::table('int_sections')->insert([['id' => 1], ['id' => 2]]);
});

function intTargetGuard(): WarrantGuardForSchema
{
    return Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(new IntKeyedSectionSchema);
}

// -- the schema-bound guard accepts an int key --------------------------------

it('accepts an int target for can()', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');

    expect(intTargetGuard()->can('view', 1))->toBeTrue();
});

it('evaluates a row condition against an int target', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'int_sections');

    // The condition matches row 1 only, so the int has to reach the query intact.
    expect(intTargetGuard()->can('view', 1))->toBeTrue();
    expect(intTargetGuard()->can('view', 2))->toBeFalse();
});

it('denies an int target that is not in the table', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');

    expect(intTargetGuard()->can('view', 999))->toBeFalse();
});

it('accepts an int target for canAny() and cannot()', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');

    expect(intTargetGuard()->canAny(['view', 'update'], 1))->toBeTrue();
    expect(intTargetGuard()->cannot('update', 1))->toBeTrue();
});

it('accepts an int target for authorize()', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');

    intTargetGuard()->authorize('view', 1);

    expect(fn () => intTargetGuard()->authorize('update', 1))
        ->toThrow(WarrantAuthorizationException::class);
});

it('accepts an int target for abilities()', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');

    expect(intTargetGuard()->abilities(1))->toBe(['view']);
});

// -- an int agrees with the equivalent model and string ----------------------

it('answers an int, a numeric string, and the loaded model identically', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'int_sections');

    $model = IntKeyedSectionModel::query()->findOrFail(1);

    expect(intTargetGuard()->can('view', 1))->toBeTrue();
    expect(intTargetGuard()->can('view', '1'))->toBeTrue();
    expect(intTargetGuard()->can('view', $model))->toBeTrue();
});

// -- the key reaches the query as written ------------------------------------

/**
 * The bindings of the single query a closure emits.
 *
 * @return array<int, mixed>
 */
function capturedBindings(Closure $run): array
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    $run();

    $connection->disableQueryLog();
    $log = $connection->getQueryLog();

    expect($log)->toHaveCount(1);

    return $log[0]['bindings'];
}

it('binds an int key as an int, not a stringified one', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');

    // toBe is strict, so ['1'] would fail here — which is the point: the key is
    // compared as an integer rather than leaning on the database to coerce it.
    expect(capturedBindings(fn () => intTargetGuard()->can('view', 1)))->toBe([1]);
});

it('binds a string key as a string', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');

    expect(capturedBindings(fn () => intTargetGuard()->can('view', '1')))->toBe(['1']);
});

it('binds an int tuple id as an int through the schema-less facade', function () {
    bindWarrantRules('they can view', schemaKey: 'int_sections');
    $user = makeWarrantTestUser('teacher-role');

    expect(capturedBindings(fn () => Warrant::can('view', [IntKeyedSectionModel::class, 1], user: $user)))
        ->toBe([1]);
});

// -- the schema-less tuple form, for contrast --------------------------------

it('still accepts an int id through the schema-less tuple form', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'int_sections');

    expect(Warrant::can('view', [IntKeyedSectionModel::class, 1], user: makeWarrantTestUser('teacher-role')))
        ->toBeTrue();
    expect(Warrant::can('view', [IntKeyedSectionModel::class, 2], user: makeWarrantTestUser('teacher-role')))
        ->toBeFalse();
});
