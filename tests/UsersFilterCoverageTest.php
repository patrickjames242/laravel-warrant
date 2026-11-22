<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\AuthorizesWithWarrant;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\Guard\UsersFilter;
use Warrant\HasWarrantSchema;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Relations\RowRelation;
use Warrant\Schema\Relations\RowRelationContext;
use Warrant\Schema\Types\Classes\GlobalSchema;
use Warrant\Schema\Types\Classes\ModelSchema;
use Warrant\Schema\Types\Classes\QuerySchema;
use Warrant\Schema\Types\Classes\RowlessSchema;
use Warrant\WarrantManager;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| Warrant::filterUsers(): the paths UsersFilterTest leaves out
|------------------------------------------------------------------------------
|
| Names inside hops and check(...) predicates that would collide with the users
| query, deny rules and `not`, derived conditions, row relations, required
| context, query and rowless schemas, when blocks, match modes over no-target
| abilities, and the users query's own where clauses.
|
*/

class UfxUser extends User
{
    use HasWarrantSchema;
    use AuthorizesWithWarrant;

    protected $table = 'ufx_users';

    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return UfxUserSchema::class;
    }
}

/** A model over the users table that is not Authenticatable. */
class UfxMember extends Model
{
    protected $table = 'ufx_users';

    public $timestamps = false;
}

class UfxDocument extends Model
{
    use HasWarrantSchema;

    protected $table = 'ufx_documents';

    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return UfxDocumentSchema::class;
    }
}

class UfxFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'ufx_folders';

    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return UfxFolderSchema::class;
    }
}

class UfxUserSchema extends ModelSchema
{
    public const model = UfxUser::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isSelf(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('id'), $c->userColumn('id'));
    }
}

class UfxDocumentSchema extends ModelSchema
{
    public const model = UfxDocument::class;

    /** @var list<mixed> The `$c->user` each row relation was handed. */
    public static array $relationUsersSeen = [];

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[Ability]
    public const CREATE = 'create';

    #[Ability]
    public const ARCHIVE = 'archive';

    #[Ability(requiredContext: ['as_of'])]
    public const PUBLISH = 'publish';

    #[RowCondition]
    public function inUsersTeam(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
    }

    #[RowCondition]
    public function ownedByUser(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner_id'), $c->userColumn('id'));
    }

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->where($c->userColumn('role'), 'admin');
    }

    #[GlobalCondition]
    public function asOfReached(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('? >= ?', [$c->context['as_of'], '2026-01-01']);
    }

    #[DerivedCondition]
    public function ownerOrTeammate(): IBooleanExpressionNode
    {
        return WarrantSyntax::parse('owned_by_user or in_users_team')->validateWithoutSchemas()->conditionExpression();
    }

    #[RowRelation(UfxUserSchema::class)]
    public function owner(RowRelationContext $c): BuilderContract
    {
        self::$relationUsersSeen[] = $c->user;

        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('owner_id'));
    }
}

class UfxFolderSchema extends ModelSchema
{
    public const model = UfxFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function inUsersTeam(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
    }
}

/** The documents as a query, found by a matchKey that refuses a null id. */
class UfxDocumentViewSchema extends QuerySchema
{
    #[Ability]
    public const VIEW = 'view';

    public function rows(): QueryBuilder
    {
        return DB::table('ufx_documents')
            ->where('ufx_documents.team_id', '!=', 999)
            ->select(['ufx_documents.id', 'ufx_documents.team_id']);
    }

    public function matchKey(RowConditionContext $c, mixed $id): ?BuilderContract
    {
        return $id === null ? null : $c->query->where($c->column('id'), $id);
    }

    #[RowCondition]
    public function inUsersTeam(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
    }
}

/** The documents as a query with no bindings of its own, keyed by id. */
class UfxDocumentRowsSchema extends QuerySchema
{
    #[Ability]
    public const VIEW = 'view';

    public function rows(): QueryBuilder
    {
        return DB::table('ufx_documents')->select(['ufx_documents.id', 'ufx_documents.team_id']);
    }

    protected function key(): string
    {
        return 'id';
    }

    #[RowCondition]
    public function inUsersTeam(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
    }
}

/** The documents as a query whose matchKey finds a row only in the user's team. */
class UfxTeamDocumentSchema extends QuerySchema
{
    #[Ability]
    public const VIEW = 'view';

    public function rows(): QueryBuilder
    {
        return DB::table('ufx_documents')->select(['ufx_documents.id', 'ufx_documents.team_id']);
    }

    public function matchKey(RowConditionContext $c, mixed $id): ?BuilderContract
    {
        return $c->query
            ->where($c->column('id'), $id)
            ->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
    }
}

class UfxBillingSchema extends RowlessSchema
{
    #[Ability]
    public const EXPORT = 'export';
}

class UfxTenancySchema extends GlobalSchema
{
    #[GlobalCondition]
    public function isMember(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->where($c->userColumn('role'), 'member');
    }
}

class UfxRuleProvider implements IRuleProvider
{
    public int $calls = 0;

    /**
     * @param string $rules The rules for no particular user.
     * @param string|null $rulesForAUser The rules for any one user; null for the
     *   same as $rules.
     */
    public function __construct(private string $rules, private ?string $rulesForAUser = null) {}

    public function rules(RuleProviderContext $context): string
    {
        $this->calls++;

        return $context->user === null ? $this->rules : ($this->rulesForAUser ?? $this->rules);
    }
}

function bindUfxRules(string $rules, ?string $rulesForAUser = null): UfxRuleProvider
{
    app()->instance(IRuleProvider::class, $provider = new UfxRuleProvider($rules, $rulesForAUser));

    return $provider;
}

/**
 * @return list<int>
 */
function ufxIds(EloquentBuilder $users, string $qualifier = 'ufx_users'): array
{
    return array_map('intval', $users->orderBy("{$qualifier}.id")->pluck("{$qualifier}.id")->all());
}

function ufxSql(EloquentBuilder $users): string
{
    return normalizeWarrantSql($users->toRawSql());
}

function ufxDocument(int $id = 100): UfxDocument
{
    return UfxDocument::query()->findOrFail($id);
}

beforeEach(function () {
    config()->set('warrant.global', [UfxTenancySchema::class]);
    useWarrantSchemas([
        'ufx_users' => UfxUserSchema::class,
        'ufx_documents' => UfxDocumentSchema::class,
        'ufx_folders' => UfxFolderSchema::class,
        'ufx_document_views' => UfxDocumentViewSchema::class,
        'ufx_document_rows' => UfxDocumentRowsSchema::class,
        'ufx_team_documents' => UfxTeamDocumentSchema::class,
        'ufx_billing' => UfxBillingSchema::class,
    ]);
    UfxDocumentSchema::$relationUsersSeen = [];

    Schema::create('ufx_users', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
        $table->string('role');
    });

    Schema::create('ufx_documents', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
        $table->integer('owner_id');
        $table->integer('folder_id');
    });

    Schema::create('ufx_folders', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
    });

    DB::table('ufx_users')->insert([
        ['id' => 1, 'team_id' => 10, 'role' => 'member'],
        ['id' => 2, 'team_id' => 20, 'role' => 'admin'],
        ['id' => 3, 'team_id' => 30, 'role' => 'member'],
        ['id' => 4, 'team_id' => 10, 'role' => 'member'],
    ]);

    DB::table('ufx_documents')->insert([
        ['id' => 100, 'team_id' => 10, 'owner_id' => 2, 'folder_id' => 7],
        ['id' => 200, 'team_id' => 20, 'owner_id' => 3, 'folder_id' => 8],
    ]);

    DB::table('ufx_folders')->insert([
        ['id' => 7, 'team_id' => 30],
        ['id' => 8, 'team_id' => 20],
    ]);
});

// -- check(...) predicates ------------------------------------------------------

it('reads the users query from a check(...) predicate', function () {
    bindUfxRules('for ufx_documents { if check(in_users_team for ufx_folders(@column ufx_documents.folder_id)) they can view }');

    $users = Warrant::filterUsers(UfxUser::query(), 'view', ufxDocument());

    expect(ufxSql($users))->toContain('"ufx_folders"."team_id" = "ufx_users"."team_id"')
        ->and(ufxIds($users))->toBe([3]);
});

it('renames a check(...) frame over the users table so it does not hide the users query', function () {
    bindUfxRules('for ufx_documents { if check(is_self for ufx_users(@column ufx_documents.owner_id)) they can view }');

    $users = Warrant::filterUsers(UfxUser::query(), 'view', ufxDocument());

    expect(ufxSql($users))->toContain('"ufx_users" as "ufx_users_1"')
        ->and(ufxSql($users))->toContain('"ufx_users_1"."id" = "ufx_users"."id"')
        ->and(ufxIds($users))->toBe([2]);
});

it('renames a check(...) frame whose alias is the users query\'s own name', function () {
    bindUfxRules('for ufx_documents { if check(is_self for ufx_users(@column ufx_documents.owner_id) as u) they can view }');

    $users = Warrant::filterUsers(UfxUser::query()->from('ufx_users as u'), 'view', ufxDocument());

    expect(ufxSql($users))->toContain('"ufx_users" as "u_1"')
        ->and(ufxSql($users))->toContain('"u_1"."id" = "u"."id"')
        ->and(ufxIds($users, 'u'))->toBe([2]);
});

// -- row relations --------------------------------------------------------------

it('renames a relation hop into the users table and hands the relation a null user', function () {
    bindUfxRules(<<<'RULES'
        for ufx_documents { if can(view for its owner) they can view }
        for ufx_users { if is_self they can view }
    RULES);

    $users = Warrant::filterUsers(UfxUser::query(), 'view', ufxDocument());

    expect(ufxSql($users))->toContain('"ufx_users" as "ufx_users_1"')
        ->and(ufxSql($users))->toContain('"ufx_users_1"."id" = "ufx_users"."id"')
        ->and(ufxIds($users))->toBe([2])
        ->and(UfxDocumentSchema::$relationUsersSeen)->not->toBeEmpty()
        ->and(UfxDocumentSchema::$relationUsersSeen)->each->toBeNull();
});

it('reaches a check(...) predicate over a related row in the users table', function () {
    bindUfxRules('for ufx_documents { if check(is_self for its owner) they can view }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'view', ufxDocument(200))))->toBe([3]);
});

// -- the users query is named after the target's table ----------------------------

it('renames the target\'s rows when the users query goes by the target\'s table name', function () {
    bindUfxRules('for ufx_documents { if in_users_team they can edit }');

    $users = Warrant::filterUsers(UfxUser::query()->from('ufx_users as ufx_documents'), 'edit', ufxDocument());

    expect(ufxSql($users))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "ufx_users" as "ufx_documents"
        where exists (
            select * from "ufx_documents" as "ufx_documents_1"
            where "ufx_documents_1"."id" = 100
            and ("ufx_documents_1"."team_id" = "ufx_documents"."team_id")
        )
    SQL))
        ->and(ufxIds($users, 'ufx_documents'))->toBe([1, 4]);
});

it('resolves a @column of the target to its renamed rows', function () {
    bindUfxRules(<<<'RULES'
        for ufx_documents { if can(view for ufx_users(@column ufx_documents.owner_id)) they can view }
        for ufx_users { if is_self they can view }
    RULES);

    $users = Warrant::filterUsers(UfxUser::query()->from('ufx_users as ufx_documents'), 'view', ufxDocument());

    expect(ufxSql($users))->toContain('"ufx_documents_1"."owner_id"')
        ->and(ufxIds($users, 'ufx_documents'))->toBe([2]);
});

// -- deny rules and `not` ---------------------------------------------------------

it('lets a deny take users out of a grant', function () {
    bindUfxRules('for ufx_documents { if in_users_team or is_admin they can edit  if is_admin they cannot edit }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument())))->toBe([1, 4]);
});

it('keeps the users a negated row condition holds for', function () {
    bindUfxRules('for ufx_documents { if not in_users_team they can view }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'view', ufxDocument())))->toBe([2, 3]);
});

it('lets every user through an unconditional grant on a row that exists', function () {
    bindUfxRules('for ufx_documents { they can edit }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument())))->toBe([1, 2, 3, 4]);
});

it('lets no user through an unconditional deny', function () {
    bindUfxRules('for ufx_documents { they can edit  they cannot edit }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument())))->toBe([]);
});

it('lets no user through a no-target ability resting on a negated row condition', function () {
    bindUfxRules('for ufx_documents { if not in_users_team they can create }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'create', UfxDocument::class)))->toBe([]);
});

it('lets a no-target deny on a row condition block every user', function () {
    bindUfxRules('for ufx_documents { they can create  if in_users_team they cannot create }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'create', UfxDocument::class)))->toBe([]);
});

it('lets a no-target deny on a global condition take users out', function () {
    bindUfxRules('for ufx_documents { they can create  if is_admin they cannot create }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'create', UfxDocument::class)))->toBe([1, 3, 4]);
});

// -- conditions built from rule text ------------------------------------------------

it('expands a derived condition inside the filter', function () {
    bindUfxRules('for ufx_documents { if owner_or_teammate they can edit }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument())))->toBe([1, 2, 4]);
});

it('branches on the user in rule text through a when block', function () {
    bindUfxRules('for ufx_documents { when is_admin { they can edit } otherwise { if in_users_team they can edit } }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument())))->toBe([1, 2, 4])
        ->and(ufxIds(Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument(200))))->toBe([2]);
});

it('reads the users query from a global schema\'s condition', function () {
    bindUfxRules('for ufx_documents { if is_member they can create }');

    $users = Warrant::filterUsers(UfxUser::query(), 'create', UfxDocument::class);

    expect(ufxSql($users))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "ufx_users" where ("ufx_users"."role" = 'member')
    SQL))
        ->and(ufxIds($users))->toBe([1, 3, 4]);
});

it('sees only the rules a provider returns for no user', function () {
    // Every user is granted when asked one at a time; the filter asks for no one.
    bindUfxRules('for ufx_documents { }', 'for ufx_documents { they can edit }');

    expect(Warrant::forSchema(UfxDocument::class, UfxUser::query()->findOrFail(1))->can('edit', ufxDocument()))->toBeTrue()
        ->and(ufxIds(Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument())))->toBe([]);
});

it('resolves the rules for no user once across filters', function () {
    $provider = bindUfxRules('for ufx_documents { if in_users_team they can edit }');

    Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument())->get();
    Warrant::filterUsers(UfxUser::query(), 'edit', ufxDocument(200))->get();

    expect($provider->calls)->toBe(1);
});

// -- context ----------------------------------------------------------------------

it('refuses an ability whose required context is missing', function () {
    bindUfxRules('for ufx_documents { if as_of_reached they can publish }');

    expect(fn () => Warrant::filterUsers(UfxUser::query(), 'publish', ufxDocument()))
        ->toThrow(InvalidArgumentException::class, 'Ability [publish] requires context key(s) [as_of]');
});

it('hands the check context to the conditions', function () {
    bindUfxRules('for ufx_documents { if as_of_reached they can publish }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'publish', ufxDocument(), context: ['as_of' => '2026-06-01'])))->toBe([1, 2, 3, 4])
        ->and(ufxIds(Warrant::filterUsers(UfxUser::query(), 'publish', ufxDocument(), context: ['as_of' => '2025-06-01'])))->toBe([])
        ->and(ufxIds(UfxUser::query()->hasAbilityOn('publish', ufxDocument(), context: ['as_of' => '2026-06-01'])))->toBe([1, 2, 3, 4]);
});

// -- match modes over no-target abilities -------------------------------------------

it('combines no-target abilities under the match mode', function () {
    bindUfxRules('for ufx_documents { if is_admin they can create  if is_member they can archive }');

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), ['create', 'archive'], UfxDocument::class)))->toBe([])
        ->and(ufxIds(Warrant::filterUsers(UfxUser::query(), ['create', 'archive'], UfxDocument::class, AbilityMatchMode::ANY)))->toBe([1, 2, 3, 4]);
});

// -- abilities asked for ----------------------------------------------------------------

it('leaves the users query alone when no ability is asked for', function () {
    bindUfxRules('for ufx_documents { they can edit }');

    expect(ufxSql(Warrant::filterUsers(UfxUser::query(), [], ufxDocument())))
        ->toBe(normalizeWarrantSql('select * from "ufx_users"'));
});

it('refuses an ability the schema does not declare', function () {
    bindUfxRules('for ufx_documents { they can edit }');

    expect(fn () => Warrant::filterUsers(UfxUser::query(), 'nope', ufxDocument()))
        ->toThrow(InvalidArgumentException::class, 'Ability [nope] is not defined');
});

it('refuses a schema that is not registered under a key', function () {
    expect(fn () => Warrant::filterUsers(UfxUser::query(), 'view', UfxTenancySchema::class))
        ->toThrow(OutOfBoundsException::class, 'No Warrant schema registered for reference [UfxTenancySchema]');
});

// -- schemas that are not models ------------------------------------------------------

it('filters users by a row of a query schema', function () {
    bindUfxRules('for ufx_document_rows { if in_users_team they can view }');

    $users = Warrant::filterUsers(UfxUser::query(), 'view', [UfxDocumentRowsSchema::class, 100]);

    expect(ufxSql($users))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "ufx_users"
        where exists (
            select * from (select "ufx_documents"."id", "ufx_documents"."team_id" from "ufx_documents") as "warrant_rows"
            where "warrant_rows"."id" = 100
            and ("warrant_rows"."team_id" = "ufx_users"."team_id")
        )
    SQL))
        ->and(ufxIds($users))->toBe([1, 4])
        ->and(ufxIds(Warrant::filterUsers(UfxUser::query(), 'view', [UfxDocumentRowsSchema::class, 200])))->toBe([2]);
});

/* UsersFilter selects the rows twice over (rowsQuery() then fromRows()), and a
   query schema's fromRows() is a fromSub(), which appends the rows' bindings a
   second time: the 999 lands where the target's key belongs. */
it('binds a query schema\'s own values once', function () {
    bindUfxRules('for ufx_document_views { if in_users_team they can view }');

    $users = Warrant::filterUsers(UfxUser::query(), 'view', [UfxDocumentViewSchema::class, 100]);

    expect(ufxSql($users))->toContain('"ufx_documents"."team_id" != 999')
        ->and(ufxSql($users))->toContain('"warrant_rows"."id" = 100')
        ->and(ufxIds($users))->toBe([1, 4]);
});

it('hands the row matcher the users query\'s row', function () {
    bindUfxRules('for ufx_team_documents { they can view }');

    $users = Warrant::filterUsers(UfxUser::query(), 'view', [UfxTeamDocumentSchema::class, 100]);

    expect(ufxSql($users))->toContain('"warrant_rows"."team_id" = "ufx_users"."team_id"')
        ->and(ufxIds($users))->toBe([1, 4]);
});

it('hands the row matcher the user\'s row in a check about one user', function () {
    bindUfxRules('for ufx_team_documents { they can view }');

    expect(Warrant::can('view', [UfxTeamDocumentSchema::class, 100], user: UfxUser::query()->findOrFail(1)))->toBeTrue()
        ->and(Warrant::can('view', [UfxTeamDocumentSchema::class, 100], user: UfxUser::query()->findOrFail(2)))->toBeFalse();
});

it('lets no user through when the row matcher refuses the key', function () {
    bindUfxRules('for ufx_document_views { they can view }');

    $users = Warrant::filterUsers(UfxUser::query(), 'view', [UfxDocumentViewSchema::class, [null]]);

    expect(ufxSql($users))->toBe(normalizeWarrantSql('select * from "ufx_users" where 1 = 0'));
});

it('filters users by a rowless schema\'s ability', function () {
    bindUfxRules('for ufx_billing { if is_member they can export }');

    $users = Warrant::filterUsers(UfxUser::query(), 'export', UfxBillingSchema::class);

    expect(ufxSql($users))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "ufx_users" where ("ufx_users"."role" = 'member')
    SQL))
        ->and(ufxIds($users))->toBe([1, 3, 4]);
});

it('refuses a row of a rowless schema', function () {
    bindUfxRules('for ufx_billing { they can export }');

    expect(fn () => Warrant::filterUsers(UfxUser::query(), 'export', [UfxBillingSchema::class, 1]))
        ->toThrow(InvalidArgumentException::class, 'has no rows and does not support targeted checks');
});

it('follows a hop into a rowless schema from a no-target ability', function () {
    bindUfxRules(<<<'RULES'
        for ufx_documents { if can(export for ufx_billing) they can create }
        for ufx_billing { if is_member they can export }
    RULES);

    expect(ufxIds(Warrant::filterUsers(UfxUser::query(), 'create', UfxDocument::class)))->toBe([1, 3, 4]);
});

it('renames a hop into the users table from a no-target ability', function () {
    bindUfxRules(<<<'RULES'
        for ufx_documents { if can(view for ufx_users(@context person)) they can create }
        for ufx_users { if is_self they can view }
    RULES);

    $users = Warrant::filterUsers(UfxUser::query(), 'create', UfxDocument::class, context: ['person' => 3]);

    expect(ufxSql($users))->toContain('"ufx_users" as "ufx_users_1"')
        ->and(ufxSql($users))->toContain('"ufx_users_1"."id" = "ufx_users"."id"')
        ->and(ufxIds($users))->toBe([3]);
});

// -- the users query ---------------------------------------------------------------------

it('filters a users query over a model that is not Authenticatable', function () {
    bindUfxRules('for ufx_documents { if in_users_team they can edit }');

    $ids = Warrant::filterUsers(UfxMember::query(), 'edit', ufxDocument())->orderBy('id')->pluck('id')->all();

    expect(array_map('intval', $ids))->toBe([1, 4]);
});

it('keeps the users query\'s own or-clauses apart from the filter through the scope', function () {
    bindUfxRules('for ufx_documents { if in_users_team they can edit }');

    $users = UfxUser::query()
        ->where('role', 'admin')
        ->orWhere('team_id', 10)
        ->hasAbilityOn('edit', ufxDocument());

    // User 2 is an admin outside the document's team: the or must not let them in.
    expect(ufxIds($users))->toBe([1, 4]);
});

it('takes a bare key through the filter itself', function () {
    bindUfxRules('for ufx_documents { if in_users_team they can edit }');

    $users = UsersFilter::forSchema(app(WarrantManager::class), UfxDocumentSchema::class)
        ->apply(UfxUser::query(), 'edit', 100);

    expect(ufxIds($users))->toBe([1, 4]);
});

