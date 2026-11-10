<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Builders\Build;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
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
| Row relations: compiling `for its <relation>`
|--------------------------------------------------------------------------
|
| An `its` hop is an exists over the target's rows, narrowed by the relation's
| method to the rows related to the one in scope, with the target's rules on
| top. It reaches any number of rows, so it asks whether any of them passes.
|
| Fixtures (at the foot of this file):
|   rc_docs (id, folder_id, parent_id, owner)  — RhDocSchema
|     folder():  rc_folders.id = rc_docs.folder_id
|     parent():  rc_docs.id = rc_docs.parent_id, a relation to its own schema
|   rc_folders (id, owner)                     — RhFolderSchema
|     documents(): rc_docs.folder_id = rc_folders.id
|   is_owner on either: owner = the user's role
|
*/

beforeEach(function () {
    Schema::create('rc_docs', function ($table) {
        $table->string('id');
        $table->string('folder_id')->nullable();
        $table->string('parent_id')->nullable();
        $table->string('owner');
    });
    Schema::create('rc_folders', function ($table) {
        $table->string('id');
        $table->string('owner');
    });

    useWarrantSchemas(['rc_docs' => RhDocSchema::class, 'rc_folders' => RhFolderSchema::class]);
});

/**
 * @param array<string, string|RuleSetNode> $syntaxByKey
 */
function bindRhRules(array $syntaxByKey): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = $syntax instanceof RuleSetNode ? $syntax : WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo($key);
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

function rhFilterSql(string $schemaKey, string $ability, ?string $roleId = 'role-1'): string
{
    $schema = $schemaKey === 'rc_docs' ? new RhDocSchema : new RhFolderSchema;

    return normalizeWarrantSql(Warrant::guard(makeWarrantTestUser($roleId))->forSchema($schema)->filterQuery(
        warrantTestQuery($schemaKey),
        $ability,
        AbilityMatchMode::ALL,
        [],
    )->toRawSql());
}

/**
 * The ids of $schemaKey's rows the user may $ability, by running the filter.
 *
 * @return list<string>
 */
function rhPermittedIds(string $schemaKey, string $ability, ?string $roleId = 'role-1'): array
{
    $schema = $schemaKey === 'rc_docs' ? new RhDocSchema : new RhFolderSchema;

    return Warrant::guard(makeWarrantTestUser($roleId))->forSchema($schema)
        ->filterQuery(warrantTestQuery($schemaKey), $ability, AbilityMatchMode::ALL, [])
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

function seedRhRows(): void
{
    DB::table('rc_folders')->insert([
        ['id' => 'f-mine', 'owner' => 'role-1'],
        ['id' => 'f-theirs', 'owner' => 'role-2'],
    ]);
    DB::table('rc_docs')->insert([
        ['id' => 'd-in-mine', 'folder_id' => 'f-mine', 'parent_id' => null, 'owner' => 'role-2'],
        ['id' => 'd-in-theirs', 'folder_id' => 'f-theirs', 'parent_id' => null, 'owner' => 'role-1'],
        ['id' => 'd-loose', 'folder_id' => null, 'parent_id' => 'd-in-theirs', 'owner' => 'role-2'],
    ]);
}

// -- the SQL ------------------------------------------------------------------

it('compiles a to-one relation to an exists correlated by the relation\'s method', function () {
    bindRhRules([
        'rc_docs' => 'can they view { if can(view for its folder) they can }',
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);

    expect(rhFilterSql('rc_docs', 'view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "rc_docs" where (
            exists (
                select * from "rc_folders"
                where "rc_folders"."id" = "rc_docs"."folder_id"
                    and ("rc_folders"."owner" = 'role-1')
            )
        )
    SQL));
});

it('compiles a to-many relation the same way, asking whether any related row passes', function () {
    bindRhRules([
        'rc_folders' => 'can they view { if can(view for its documents) they can }',
        'rc_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rhFilterSql('rc_folders', 'view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "rc_folders" where (
            exists (
                select * from "rc_docs"
                where "rc_docs"."folder_id" = "rc_folders"."id"
                    and ("rc_docs"."owner" = 'role-1')
            )
        )
    SQL));
});

it('renames the related rows when their table is already in scope, and still correlates to the row', function () {
    bindRhRules(['rc_docs' => 'can they view { if can(edit for its parent) they can } can they edit { if is_owner they can }']);

    expect(rhFilterSql('rc_docs', 'view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "rc_docs" where (
            exists (
                select * from "rc_docs" as "rc_docs_1"
                where "rc_docs_1"."id" = "rc_docs"."parent_id"
                    and ("rc_docs_1"."owner" = 'role-1')
            )
        )
    SQL));
});

it('compiles a check(...) over a relation, with the predicate on the related rows', function () {
    bindRhRules(['rc_docs' => 'can they view { if check(is_owner for its folder) they can }']);

    expect(rhFilterSql('rc_docs', 'view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "rc_docs" where (
            exists (
                select * from "rc_folders"
                where "rc_folders"."id" = "rc_docs"."folder_id"
                    and ("rc_folders"."owner" = 'role-1')
            )
        )
    SQL));
});

it('negates a relation hop into a not exists', function () {
    bindRhRules([
        'rc_docs' => 'can they view { if is_owner they can if can(view for its folder) they cannot }',
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);

    expect(rhFilterSql('rc_docs', 'view'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "rc_docs" where (
            "rc_docs"."owner" = 'role-1'
            and not exists (
                select * from "rc_folders"
                where "rc_folders"."id" = "rc_docs"."folder_id"
                    and ("rc_folders"."owner" = 'role-1')
            )
        )
    SQL));
});

it('follows a relation from inside another hop, correlating to that hop\'s rows', function () {
    bindRhRules([
        'rc_folders' => 'can they share { if can(view for its documents) they can }',
        'rc_docs' => 'can they view { if is_owner they can } can they edit { if can(share for its folder) they can }',
    ]);

    expect(rhFilterSql('rc_docs', 'edit'))->toBe(normalizeWarrantSql(<<<SQL
        select * from "rc_docs" where (
            exists (
                select * from "rc_folders"
                where "rc_folders"."id" = "rc_docs"."folder_id"
                    and (exists (
                        select * from "rc_docs" as "rc_docs_1"
                        where "rc_docs_1"."folder_id" = "rc_folders"."id"
                            and ("rc_docs_1"."owner" = 'role-1')
                    ))
            )
        )
    SQL));
});

it('builds the same hop from Build::can()->its() as from rule text', function () {
    bindRhRules([
        'rc_docs' => new RuleSetNode(nameNode('rc_docs'), [Build::rule()->ifCan(Build::can('view')->its('folder'))->theyCan('view')->toRule()], SyntaxLocation::noProducer()),
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);
    $built = rhFilterSql('rc_docs', 'view');

    bindRhRules([
        'rc_docs' => 'if can(view for its folder) they can view',
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);

    expect($built)->toBe(rhFilterSql('rc_docs', 'view'));
});

// -- the answers --------------------------------------------------------------

it('lets through the rows whose related row passes, and no other', function () {
    seedRhRows();
    bindRhRules([
        'rc_docs' => 'can they view { if can(view for its folder) they can }',
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);

    expect(rhPermittedIds('rc_docs', 'view'))->toBe(['d-in-mine'])
        ->and(rhPermittedIds('rc_docs', 'view', 'role-2'))->toBe(['d-in-theirs']);
});

it('lets through a row when any of its many related rows passes', function () {
    seedRhRows();
    bindRhRules([
        'rc_folders' => 'can they view { if can(view for its documents) they can }',
        'rc_docs' => 'can they view { if is_owner they can }',
    ]);

    expect(rhPermittedIds('rc_folders', 'view'))->toBe(['f-theirs'])
        ->and(rhPermittedIds('rc_folders', 'view', 'role-2'))->toBe(['f-mine']);
});

it('answers a check on one model by querying, not by reading a loaded relation', function () {
    seedRhRows();
    bindRhRules([
        'rc_docs' => 'can they view { if can(view for its folder) they can }',
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);
    $guard = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new RhDocSchema);

    expect($guard->can('view', RhDoc::query()->find('d-in-mine')))->toBeTrue()
        ->and($guard->can('view', RhDoc::query()->find('d-in-theirs')))->toBeFalse()
        ->and($guard->can('view', RhDoc::query()->find('d-loose')))->toBeFalse();
});

it('answers a relation hop with no row in scope as unknown, which grants nothing', function () {
    bindRhRules([
        'rc_docs' => 'can they view { if can(view for its folder) they can }',
        'rc_folders' => 'can they view { they can }',
    ]);
    $guard = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new RhDocSchema);

    expect($guard->getAbilitiesWithoutTarget())->not->toContain('view')
        ->and($guard->reachabilityOf('view'))->toBe(Reachability::MAYBE);
});

it('finds a relation hop never reachable when its target can never grant', function () {
    bindRhRules([
        'rc_docs' => 'can they view { if can(view for its folder) they can }',
        'rc_folders' => 'can they view { they cannot }',
    ]);

    expect(Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new RhDocSchema)->reachabilityOf('view'))
        ->toBe(Reachability::NEVER);
});

// -- relations declared wrongly -----------------------------------------------

it('refuses a relation that adds no where clause', function () {
    useWarrantSchemas(['rc_docs' => RhLooseDocSchema::class, 'rc_folders' => RhFolderSchema::class]);
    bindRhRules([
        'rc_docs' => 'can they view { if can(view for its folder) they can }',
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser())->forSchema(new RhLooseDocSchema)->filterQuery(warrantTestQuery('rc_docs'), 'view'))
        ->toThrow(InvalidArgumentException::class, 'Row relation [folder] on schema [RhLooseDocSchema] added no where clause');
});

it('refuses a relation that joins', function () {
    useWarrantSchemas(['rc_docs' => RhJoiningDocSchema::class, 'rc_folders' => RhFolderSchema::class]);
    bindRhRules([
        'rc_docs' => 'can they view { if can(view for its folder) they can }',
        'rc_folders' => 'can they view { if is_owner they can }',
    ]);

    expect(fn () => Warrant::guard(makeWarrantTestUser())->forSchema(new RhJoiningDocSchema)->filterQuery(warrantTestQuery('rc_docs'), 'view'))
        ->toThrow(InvalidArgumentException::class, 'Row relation [folder] on schema [RhJoiningDocSchema] may only add where clauses, but it emitted a [join]');
});

// -- fixtures -----------------------------------------------------------------

class RhDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'rc_docs';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return RhDocSchema::class;
    }
}

class RhFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'rc_folders';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return RhFolderSchema::class;
    }
}

class RhFolderSchema extends ModelSchema
{
    public const model = RhFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const SHARE = 'share';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner'), $c->user->role_id);
    }

    #[RowRelation(RhDocSchema::class)]
    public function documents(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('folder_id'), $c->ownColumn('id'));
    }
}

class RhDocSchema extends ModelSchema
{
    public const model = RhDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner'), $c->user->role_id);
    }

    #[RowRelation(RhFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
    }

    #[RowRelation(RhDocSchema::class)]
    public function parent(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('parent_id'));
    }
}

class RhLooseDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'rc_docs';

    public static function warrantSchema(): string
    {
        return RhLooseDocSchema::class;
    }
}

class RhJoiningDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'rc_docs';

    public static function warrantSchema(): string
    {
        return RhJoiningDocSchema::class;
    }
}

class RhLooseDocSchema extends ModelSchema
{
    public const model = RhLooseDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowRelation(RhFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery;
    }
}

class RhJoiningDocSchema extends ModelSchema
{
    public const model = RhJoiningDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowRelation(RhFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->join('rc_docs as joined', 'joined.folder_id', '=', $c->relatedColumn('id'));
    }
}
