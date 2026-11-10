<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Reachability;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Relations\RowRelation;
use Warrant\Schema\Relations\RowRelationContext;
use Warrant\Schema\Types\Classes\ModelSchema;

/*
|--------------------------------------------------------------------------
| Quantifiers: `any of` and `all of`
|--------------------------------------------------------------------------
|
| A handle that reaches rows may say how many of them must pass. `any of`, and
| a handle with no quantifier, is an exists. `all of` asks that there be at
| least one row and no row on which the target's rules are not true, so an
| empty set grants nothing and a rule answering null fails its row.
|
| Fixtures (at the foot of this file):
|   rq_folders (id, owner)            — RqFolderSchema
|     documents(): rq_docs.folder_id = rq_folders.id
|   rq_docs (id, folder_id, owner)    — RqDocSchema
|     matchKey(folder_id): addresses a folder's documents, as rq_docs(@column id)
|   is_owner on either: owner = the user's role
|
*/

beforeEach(function () {
    Schema::create('rq_folders', function ($table) {
        $table->string('id');
        $table->string('owner');
    });
    Schema::create('rq_docs', function ($table) {
        $table->string('id');
        $table->string('folder_id');
        $table->string('owner')->nullable();
    });

    useWarrantSchemas(['rq_folders' => RqFolderSchema::class, 'rq_docs' => RqDocSchema::class]);
});

/**
 * @param array<string, string> $syntaxByKey
 */
function bindRqRules(array $syntaxByKey): void
{
    $sets = array_map(
        static fn (string $syntax): WarrantSyntax => WarrantSyntax::parse($syntax),
        $syntaxByKey,
    );

    foreach ($sets as $key => $syntax) {
        $sets[$key] = $syntax->validateWithoutSchemas()->scopedTo($key);
    }

    app()->instance(IRuleProvider::class, new class($sets) implements IRuleProvider {
        /** @param array<string, RuleSetNode> $sets */
        public function __construct(private array $sets) {}

        public function rules(RuleProviderContext $context): RuleSetNode
        {
            return $this->sets[$context->schemaKey] ?? new RuleSetNode(nameNode($context->schemaKey), [], SyntaxLocation::noProducer());
        }
    });

    Warrant::flush();
}

function rqFolderSql(string $ability = 'view'): string
{
    return normalizeWarrantSql(Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new RqFolderSchema)->filterQuery(
        warrantTestQuery('rq_folders'),
        $ability,
        AbilityMatchMode::ALL,
        [],
    )->toRawSql());
}

/**
 * @return list<string>
 */
function rqPermittedFolders(string $ability = 'view'): array
{
    return Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new RqFolderSchema)
        ->filterQuery(warrantTestQuery('rq_folders'), $ability, AbilityMatchMode::ALL, [])
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

/**
 * Every folder is role-1's. Their documents are: all role-1's, some role-1's,
 * none at all, and one whose owner is null.
 */
function seedRqRows(): void
{
    DB::table('rq_folders')->insert([
        ['id' => 'f-all-mine', 'owner' => 'role-1'],
        ['id' => 'f-empty', 'owner' => 'role-1'],
        ['id' => 'f-mixed', 'owner' => 'role-1'],
        ['id' => 'f-null', 'owner' => 'role-1'],
    ]);
    DB::table('rq_docs')->insert([
        ['id' => 'd1', 'folder_id' => 'f-all-mine', 'owner' => 'role-1'],
        ['id' => 'd2', 'folder_id' => 'f-all-mine', 'owner' => 'role-1'],
        ['id' => 'd3', 'folder_id' => 'f-mixed', 'owner' => 'role-1'],
        ['id' => 'd4', 'folder_id' => 'f-mixed', 'owner' => 'role-2'],
        ['id' => 'd5', 'folder_id' => 'f-null', 'owner' => null],
    ]);
}

/**
 * The diagnostics for $source written for rq_folders, as [code, the text each covers].
 *
 * @return list<array{0: DiagnosticCode, 1: string}>
 */
function rqFolderDiagnostics(string $source): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): array => [
            $diagnostic->code,
            substr($source, $diagnostic->offset, $diagnostic->endOffset - $diagnostic->offset),
        ],
        analyzedDiagnostics($source, Warrant::registry(), 'rq_folders'),
    );
}

// -- the SQL ------------------------------------------------------------------

it('compiles any of over a relation to the same exists as a bare its', function () {
    bindRqRules([
        'rq_folders' => 'can they view { if can(view for any of its documents) they can }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);
    $quantified = rqFolderSql();

    bindRqRules([
        'rq_folders' => 'can they view { if can(view for its documents) they can }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect($quantified)->toBe(rqFolderSql());
});

it('compiles all of over a relation to an exists and a not exists of the rows that fail', function () {
    bindRqRules([
        'rq_folders' => 'can they view { if can(view for all of its documents) they can }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rqFolderSql())->toBe(normalizeWarrantSql(<<<SQL
        select * from "rq_folders" where (
            exists (
                select * from "rq_docs"
                where "rq_docs"."folder_id" = "rq_folders"."id"
            )
            and not exists (
                select * from "rq_docs"
                where "rq_docs"."folder_id" = "rq_folders"."id"
                    and ("rq_docs"."owner" = 'role-1') is not true
            )
        )
    SQL));
});

it('negates all of as a whole', function () {
    bindRqRules([
        'rq_folders' => 'can they view { if is_owner they can if can(view for all of its documents) they cannot }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rqFolderSql())->toBe(normalizeWarrantSql(<<<SQL
        select * from "rq_folders" where (
            "rq_folders"."owner" = 'role-1'
            and not (
                exists (
                    select * from "rq_docs"
                    where "rq_docs"."folder_id" = "rq_folders"."id"
                )
                and not exists (
                    select * from "rq_docs"
                    where "rq_docs"."folder_id" = "rq_folders"."id"
                        and ("rq_docs"."owner" = 'role-1') is not true
                )
            )
        )
    SQL));
});

it('compiles all of over a schema handle, narrowing both subqueries by its key', function () {
    bindRqRules([
        'rq_folders' => 'can they view { if can(view for all of rq_docs(@column id)) they can }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rqFolderSql())->toBe(normalizeWarrantSql(<<<SQL
        select * from "rq_folders" where (
            exists (
                select * from "rq_docs"
                where "rq_docs"."folder_id" = "rq_folders"."id"
            )
            and not exists (
                select * from "rq_docs"
                where "rq_docs"."folder_id" = "rq_folders"."id"
                    and ("rq_docs"."owner" = 'role-1') is not true
            )
        )
    SQL));
});

it('spells a target that always grants as a constant the failing rows never match', function () {
    bindRqRules([
        'rq_folders' => 'can they view { if can(view for all of its documents) they can }',
        'rq_docs' => 'can they view { they can }',
    ]);

    expect(rqFolderSql())->toContain('(1 = 1) is not true');
});

// -- the answers --------------------------------------------------------------

it('lets through a folder when any of its documents passes', function (string $handle) {
    seedRqRows();
    bindRqRules([
        'rq_folders' => "can they view { if can(view for {$handle}) they can }",
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rqPermittedFolders())->toBe(['f-all-mine', 'f-mixed']);
})->with([
    'a bare its' => 'its documents',
    'any of its' => 'any of its documents',
    'any of a schema' => 'any of rq_docs(@column id)',
]);

it('lets through a folder only when all of its documents pass, and never an empty one', function (string $handle) {
    seedRqRows();
    bindRqRules([
        'rq_folders' => "can they view { if can(view for {$handle}) they can }",
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rqPermittedFolders())->toBe(['f-all-mine']);
})->with([
    'all of its' => 'all of its documents',
    'all of a schema' => 'all of rq_docs(@column id)',
]);

it('fails a row whose rule answers null, rather than passing it', function () {
    DB::table('rq_folders')->insert(['id' => 'f-null', 'owner' => 'role-1']);
    DB::table('rq_docs')->insert(['id' => 'd5', 'folder_id' => 'f-null', 'owner' => null]);
    bindRqRules([
        'rq_folders' => 'can they view { if can(view for all of its documents) they can }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rqPermittedFolders())->toBe([]);
});

it('denies the folders all of whose documents pass, and only those', function () {
    seedRqRows();
    bindRqRules([
        'rq_folders' => 'can they view { if is_owner they can if can(view for all of its documents) they cannot }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rqPermittedFolders())->toBe(['f-empty', 'f-mixed', 'f-null']);
});

it('answers a check(...) under all of with the predicate on every related row', function () {
    seedRqRows();
    bindRqRules(['rq_folders' => 'can they view { if check(is_owner for all of its documents) they can }']);

    expect(rqPermittedFolders())->toBe(['f-all-mine']);
});

it('answers a check on one model by the quantifier', function () {
    seedRqRows();
    bindRqRules([
        'rq_folders' => 'can they view { if can(view for all of its documents) they can } can they share { if can(view for any of its documents) they can }',
        'rq_docs' => 'can they view { if is_owner they can }',
    ]);
    $guard = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new RqFolderSchema);
    $mixed = RqFolder::query()->find('f-mixed');

    expect($guard->can('view', $mixed))->toBeFalse()
        ->and($guard->can('share', $mixed))->toBeTrue()
        ->and($guard->can('view', RqFolder::query()->find('f-all-mine')))->toBeTrue();
});

it('reaches all of as it reaches any of', function (string $target, Reachability $reachability) {
    bindRqRules([
        'rq_folders' => 'can they view { if can(view for all of its documents) they can }',
        'rq_docs' => $target,
    ]);

    expect(Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new RqFolderSchema)->reachabilityOf('view'))
        ->toBe($reachability);
})->with([
    'a target that may grant' => ['can they view { they can }', Reachability::MAYBE],
    'a target that never grants' => ['can they view { they cannot }', Reachability::NEVER],
]);

// -- validation ---------------------------------------------------------------

it('reports a quantifier on a reference that selects no rows, over the handle', function (string $source, string $handle) {
    expect(rqFolderDiagnostics($source))->toBe([[DiagnosticCode::QuantifierWithoutRow, $handle]])
        ->and(fn () => validateRegistered(WarrantSyntax::parse($source)->validateWithoutSchemas()->scopedTo('rq_folders')))
        ->toThrow(InvalidArgumentException::class, 'is quantified but selects no rows');
})->with([
    'a can(...)' => ['if can(view for all of rq_docs) they can view', 'all of rq_docs'],
    'a check(...)' => ['if check(is_owner for any of rq_docs) they can view', 'any of rq_docs'],
]);

it('accepts a quantifier on a relation and on a row-bound schema handle', function (string $source) {
    validateRegistered(WarrantSyntax::parse($source)->validateWithoutSchemas()->scopedTo('rq_folders'));

    expect(rqFolderDiagnostics($source))->toBe([]);
})->with([
    'any of its' => 'if can(view for any of its documents) they can view',
    'all of its' => 'if check(is_owner for all of its documents) they can view',
    'all of a schema' => 'if can(view for all of rq_docs(@column id)) they can view',
]);

// -- fixtures -----------------------------------------------------------------

class RqFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'rq_folders';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return RqFolderSchema::class;
    }
}

class RqDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'rq_docs';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return RqDocSchema::class;
    }
}

class RqFolderSchema extends ModelSchema
{
    public const model = RqFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const SHARE = 'share';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner'), $c->user->role_id);
    }

    #[RowRelation(RqDocSchema::class)]
    public function documents(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('folder_id'), $c->ownColumn('id'));
    }
}

class RqDocSchema extends ModelSchema
{
    public const model = RqDoc::class;

    #[Ability]
    public const VIEW = 'view';

    public function matchKey(RowConditionContext $c, mixed $folderId): ?BuilderContract
    {
        return $c->query->where($c->column('folder_id'), '=', $folderId);
    }

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner'), $c->user->role_id);
    }
}
