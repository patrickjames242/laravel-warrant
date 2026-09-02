<?php

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
| A row condition handed the row it is judging
|------------------------------------------------------------------------------
|
| A row condition normally describes the row in SQL. When the check names one
| specific row and the caller already holds it, `$c->model` is that instance and
| the condition may answer outright instead — a literal the where-clause tree
| folds, exactly as it folds a global condition's bool.
|
| `$c->model` is null whenever more than one row is in play (filterQuery, the
| per-row ability select) or the row is unproven (a bare key, an unsaved or
| deleted model), so every condition still needs its SQL branch. The fixture
| below writes both and reports which one ran, so a test can tell them apart
| beyond the query count.
|
*/

class RowModelSectionModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'model_sections';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return RowModelSectionSchema::class;
    }
}

class RowModelSectionSchema extends WarrantSchema
{
    public const model = RowModelSectionModel::class;

    /** Which branch of isOwner ran last: 'php' or 'sql'. */
    public static string $lastOwnerBranch = '';

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[Ability]
    public const ABILITY_UPDATE = 'update';

    /**
     * Answers in PHP when the row is in hand, and in SQL otherwise. The two
     * branches must agree — same rule, same verdict, whichever way it is reached.
     */
    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract|bool
    {
        if ($c->model !== null) {
            self::$lastOwnerBranch = 'php';

            return $c->model->owner_id === $c->user->role_id;
        }

        self::$lastOwnerBranch = 'sql';

        return $c->query->whereRaw("{$c->row('owner_id')} = ?", [$c->user->role_id]);
    }

    /** A plain row condition that ignores the model — always SQL. */
    #[RowCondition]
    public function isPublished(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('published')} = 1");
    }
}

beforeEach(function () {
    useWarrantSchemas(['model_sections' => RowModelSectionSchema::class]);
    RowModelSectionSchema::$lastOwnerBranch = '';

    Schema::create('model_sections', function ($table) {
        $table->string('id');
        $table->string('owner_id');
        $table->integer('published')->default(1);
    });

    DB::table('model_sections')->insert([
        ['id' => 'mine', 'owner_id' => 'teacher-role', 'published' => 1],
        ['id' => 'theirs', 'owner_id' => 'someone-else', 'published' => 1],
        ['id' => 'mine-draft', 'owner_id' => 'teacher-role', 'published' => 0],
    ]);
});

function rowModelGuard(string $role = 'teacher-role'): WarrantGuardForSchema
{
    return Warrant::guard(makeWarrantTestUser($role))->forSchema(new RowModelSectionSchema);
}

function loadedRowModel(string $id): RowModelSectionModel
{
    return RowModelSectionModel::query()->findOrFail($id);
}

// -- a hydrated model lets the condition answer in PHP ------------------------

it('grants from the model without a query', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');
    $mine = loadedRowModel('mine');

    expect(measureQueries(fn () => rowModelGuard()->can('view', $mine)))->toBe([true, 0]);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('php');
});

it('denies from the model without a query', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');
    $theirs = loadedRowModel('theirs');

    expect(measureQueries(fn () => rowModelGuard()->can('view', $theirs)))->toBe([false, 0]);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('php');
});

// -- and the SQL branch answers identically -----------------------------------

it('falls back to SQL for a bare key, with the same answers', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');

    expect(measureQueries(fn () => rowModelGuard()->can('view', 'mine')))->toBe([true, 1]);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('sql');

    expect(measureQueries(fn () => rowModelGuard()->can('view', 'theirs')))->toBe([false, 1]);
});

it('falls back to SQL for an unsaved model', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');

    $unsaved = new RowModelSectionModel;
    $unsaved->id = 'mine';
    $unsaved->owner_id = 'teacher-role';

    // The instance describes a row nobody has looked up, so it is not evidence —
    // neither of the row's existence nor of its contents.
    expect(measureQueries(fn () => rowModelGuard()->can('view', $unsaved)))->toBe([true, 1]);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('sql');
});

it('falls back to SQL for a deleted model', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');
    $mine = loadedRowModel('mine');
    $mine->delete();

    expect(measureQueries(fn () => rowModelGuard()->can('view', $mine)))->toBe([false, 1]);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('sql');
});

// -- negation: the model has to survive the `not` ----------------------------

it('folds a negated row condition from the model', function () {
    bindWarrantRules('if not is_owner they can view', schemaKey: 'model_sections');
    $mine = loadedRowModel('mine');
    $theirs = loadedRowModel('theirs');

    // If CompilationContext::negated() dropped the model, these would still be
    // correct — but by way of a query. The count is what pins it.
    expect(measureQueries(fn () => rowModelGuard()->can('view', $mine)))->toBe([false, 0]);
    expect(measureQueries(fn () => rowModelGuard()->can('view', $theirs)))->toBe([true, 0]);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('php');
});

// -- mixing a PHP-folding condition with a SQL one ---------------------------

it('still queries for the conditions that need SQL', function () {
    bindWarrantRules('if is_owner and is_published they can view', schemaKey: 'model_sections');
    $mine = loadedRowModel('mine');
    $draft = loadedRowModel('mine-draft');

    // is_owner folds to true in PHP and drops out; is_published still has to ask.
    expect(measureQueries(fn () => rowModelGuard()->can('view', $mine)))->toBe([true, 1]);
    expect(measureQueries(fn () => rowModelGuard()->can('view', $draft)))->toBe([false, 1]);
});

it('skips the query entirely when the folding condition already decided', function () {
    bindWarrantRules('if is_owner and is_published they can view', schemaKey: 'model_sections');
    $theirs = loadedRowModel('theirs');

    // is_owner folds to false, so the AND is settled and is_published never runs.
    expect(measureQueries(fn () => rowModelGuard()->can('view', $theirs)))->toBe([false, 0]);
});

// -- the denial path has to agree with the decision --------------------------

it('diagnoses a denial with the same model the check used', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');
    $theirs = loadedRowModel('theirs');

    expect(fn () => rowModelGuard()->authorize('view', $theirs))
        ->toThrow(WarrantAuthorizationException::class);

    /* The check denies in PHP, then diagnosis recompiles the rules to find
       something to blame. It has to see the same model — the branch recorded
       last is the diagnosis compile, and reads 'sql' if the model was not
       threaded through, which is a re-run that could disagree with the decision
       it is supposed to be explaining. */
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('php');
});

// -- filtering many rows never gets a model ----------------------------------

it('compiles to SQL when filtering a query', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');

    $ids = rowModelGuard()
        ->filterQuery(DB::table('model_sections'), 'model_sections.id', 'view')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($ids)->toBe(['mine', 'mine-draft']);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('sql');
});

it('compiles to SQL for the per-row ability list', function () {
    bindWarrantRules('if is_owner they can view', schemaKey: 'model_sections');

    expect(rowModelGuard()->abilities(loadedRowModel('mine')))->toBe(['view']);
    expect(RowModelSectionSchema::$lastOwnerBranch)->toBe('sql');
});
