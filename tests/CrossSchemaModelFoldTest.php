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
| A cross-schema reference whose row was handed over as a model
|------------------------------------------------------------------------------
|
| `can(view for folders(@context folder))` normally compiles to an EXISTS over
| the referenced table: does that row exist, and does B grant the ability on it?
|
| When the row selector IS the row — a hydrated model passed through @context —
| both halves can be answered without the subquery. B's row conditions get the
| model as `$c->model` and may fold to a constant, and the model's own
| `Model::$exists` already settled whether the row is there. So the whole
| reference becomes a literal and the EXISTS is never built.
|
| Without a hydrated model nothing changes: the constant still has to be asked in
| SQL, because existence is precisely what has not been established. These tests
| assert the query count for exactly that reason — the answers are the same
| either way, and only the count says which path ran.
|
*/

class FoldDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'fold_docs';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return FoldDocSchema::class;
    }
}

class FoldDocSchema extends WarrantSchema
{
    public const model = FoldDoc::class;

    #[Ability]
    public const VIEW = 'view';
}

class FoldFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'fold_folders';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return FoldFolderSchema::class;
    }
}

class FoldFolderSchema extends WarrantSchema
{
    public const model = FoldFolder::class;

    /** Which branch of isOwner ran last: 'php' or 'sql'. */
    public static string $lastBranch = '';

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract|bool
    {
        if ($c->model !== null) {
            self::$lastBranch = 'php';

            return $c->model->owner === $c->user->role_id;
        }

        self::$lastBranch = 'sql';

        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }
}

beforeEach(function () {
    useWarrantSchemas(['fold_docs' => FoldDocSchema::class, 'fold_folders' => FoldFolderSchema::class]);
    FoldFolderSchema::$lastBranch = '';

    Schema::create('fold_docs', fn ($t) => $t->string('id'));
    Schema::create('fold_folders', function ($t) {
        $t->string('id');
        $t->string('owner');
    });

    DB::table('fold_docs')->insert([['id' => 'd-1']]);
    DB::table('fold_folders')->insert([
        ['id' => 'mine', 'owner' => 'role-1'],
        ['id' => 'theirs', 'owner' => 'someone-else'],
    ]);
});

/**
 * @param array<string, string> $syntaxByKey
 */
function bindFoldRules(array $syntaxByKey): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantRuleSet::fromSyntax($syntax, $key);
    }

    app()->instance(RuleResolver::class, new class($sets) implements RuleResolver {
        /** @param array<string, WarrantRuleSet> $sets */
        public function __construct(private array $sets) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $this->sets[$context->schemaKey] ?? new WarrantRuleSet($context->schemaKey, []);
        }
    });
}

/**
 * The A-side check, with whatever the rule should read as the folder handle.
 *
 * $doc is passed in rather than loaded here: these tests count queries, and
 * fetching it inside the closure under measurement would count as one.
 */
function foldCheck(FoldDoc $doc, mixed $folder): bool
{
    return Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema(new FoldDocSchema)
        ->can('view', $doc, ['folder' => $folder]);
}

function foldDoc(): FoldDoc
{
    return FoldDoc::query()->findOrFail('d-1');
}

// -- a hydrated model settles the reference outright -------------------------

it('denies without a query when B decides from the model', function () {
    bindFoldRules([
        'fold_docs' => 'if can(view for fold_folders(@context folder)) they can view',
        'fold_folders' => 'if is_owner they can view',
    ]);
    $doc = foldDoc();
    $theirs = FoldFolder::query()->findOrFail('theirs');

    expect(measureQueries(fn () => foldCheck($doc, $theirs)))->toBe([false, 0]);
    expect(FoldFolderSchema::$lastBranch)->toBe('php');
});

it('grants without a query when B decides from the model', function () {
    bindFoldRules([
        'fold_docs' => 'if can(view for fold_folders(@context folder)) they can view',
        'fold_folders' => 'if is_owner they can view',
    ]);
    $doc = foldDoc();
    $mine = FoldFolder::query()->findOrFail('mine');

    // Granting is the case that rests on Model::$exists: the EXISTS was also
    // asking whether the folder is there, and a hydrated model already answered.
    expect(measureQueries(fn () => foldCheck($doc, $mine)))->toBe([true, 0]);
    expect(FoldFolderSchema::$lastBranch)->toBe('php');
});

it('folds a negated reference to the opposite constant', function () {
    bindFoldRules([
        'fold_docs' => 'if not can(view for fold_folders(@context folder)) they can view',
        'fold_folders' => 'if is_owner they can view',
    ]);
    $doc = foldDoc();
    $mine = FoldFolder::query()->findOrFail('mine');
    $theirs = FoldFolder::query()->findOrFail('theirs');

    expect(measureQueries(fn () => foldCheck($doc, $mine)))->toBe([false, 0]);
    expect(measureQueries(fn () => foldCheck($doc, $theirs)))->toBe([true, 0]);
});

// -- anything unproven still goes to SQL --------------------------------------

it('builds the exists for a scalar row selector', function () {
    bindFoldRules([
        'fold_docs' => 'if can(view for fold_folders(@context folder)) they can view',
        'fold_folders' => 'if is_owner they can view',
    ]);
    $doc = foldDoc();

    expect(measureQueries(fn () => foldCheck($doc, 'mine')))->toBe([true, 1]);
    expect(FoldFolderSchema::$lastBranch)->toBe('sql');

    expect(measureQueries(fn () => foldCheck($doc, 'theirs')))->toBe([false, 1]);
});

it('builds the exists for an unsaved model, which proves no row', function () {
    bindFoldRules([
        'fold_docs' => 'if can(view for fold_folders(@context folder)) they can view',
        'fold_folders' => 'if is_owner they can view',
    ]);
    $doc = foldDoc();

    $unsaved = new FoldFolder;
    $unsaved->id = 'mine';
    $unsaved->owner = 'role-1';

    // Its key still names the row, so the answer is the same — but it is read
    // from the database rather than taken from the instance.
    expect(measureQueries(fn () => foldCheck($doc, $unsaved)))->toBe([true, 1]);
    expect(FoldFolderSchema::$lastBranch)->toBe('sql');
});

it('agrees with itself across every way of naming the same row', function () {
    bindFoldRules([
        'fold_docs' => 'if can(view for fold_folders(@context folder)) they can view',
        'fold_folders' => 'if is_owner they can view',
    ]);
    $doc = foldDoc();
    $mine = FoldFolder::query()->findOrFail('mine');

    expect(foldCheck($doc, $mine))->toBeTrue();
    expect(foldCheck($doc, 'mine'))->toBeTrue();
});
