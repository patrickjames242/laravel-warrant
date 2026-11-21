<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\Facades\Warrant;
use Warrant\Guard\WarrantGuardForSchema;
use Warrant\HasWarrantSchema;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Types\Classes\ModelSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| userColumn(): a condition comparing the row with the user's own row
|------------------------------------------------------------------------------
|
| A condition reaches a column of the user's row through `userColumn()`. For a
| check about one user that column is a subquery over the users table that
| mentions nothing from the query it lands in, and the user's key is the value
| itself.
|
*/

class UserColumnUser extends User
{
    protected $table = 'uc_users';

    public $timestamps = false;
}

/** A model over another table, for the config to name in place of the user's own. */
class UserColumnOtherUser extends User
{
    protected $table = 'uc_other_users';

    public $timestamps = false;
}

class UserColumnDocument extends Model
{
    use HasWarrantSchema;

    protected $table = 'uc_documents';

    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return UserColumnDocumentSchema::class;
    }
}

class UserColumnFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'uc_folders';

    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return UserColumnFolderSchema::class;
    }
}

class UserColumnDocumentSchema extends ModelSchema
{
    public const model = UserColumnDocument::class;

    #[Ability]
    public const VIEW = 'view';

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

    #[RowCondition]
    public function inUsersTeamRaw(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->column('team_id')} = {$c->userColumn('team_id')}");
    }

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->where($c->userColumn('role'), 'admin');
    }
}

class UserColumnFolderSchema extends ModelSchema
{
    public const model = UserColumnFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function inUsersTeam(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
    }
}

class UserColumnRuleProvider implements IRuleProvider
{
    public function __construct(private string $rules) {}

    public function rules(RuleProviderContext $context): string
    {
        return $this->rules;
    }
}

function bindUserColumnRules(string $rules): void
{
    app()->instance(IRuleProvider::class, new UserColumnRuleProvider($rules));
}

function userColumnGuard(Illuminate\Contracts\Auth\Authenticatable $user): WarrantGuardForSchema
{
    return Warrant::guard($user)->forSchema(UserColumnDocumentSchema::class);
}

/**
 * @return list<int>
 */
function documentsUserCanView(Illuminate\Contracts\Auth\Authenticatable $user): array
{
    return userColumnGuard($user)
        ->filterQuery(DB::table('uc_documents'), 'view')
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

function userColumnUser(int $id): UserColumnUser
{
    return UserColumnUser::query()->findOrFail($id);
}

beforeEach(function () {
    useWarrantSchemas([
        'uc_documents' => UserColumnDocumentSchema::class,
        'uc_folders' => UserColumnFolderSchema::class,
    ]);

    Schema::create('uc_users', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
        $table->string('role');
    });

    Schema::create('uc_other_users', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
        $table->string('role');
    });

    Schema::create('uc_folders', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
    });

    Schema::create('uc_documents', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
        $table->integer('owner_id');
        $table->integer('folder_id');
    });

    DB::table('uc_users')->insert([
        ['id' => 1, 'team_id' => 10, 'role' => 'member'],
        ['id' => 2, 'team_id' => 20, 'role' => 'admin'],
    ]);

    DB::table('uc_other_users')->insert([
        ['id' => 1, 'team_id' => 20, 'role' => 'member'],
    ]);

    DB::table('uc_folders')->insert([
        ['id' => 7, 'team_id' => 10],
        ['id' => 8, 'team_id' => 20],
    ]);

    DB::table('uc_documents')->insert([
        ['id' => 100, 'team_id' => 10, 'owner_id' => 2, 'folder_id' => 8],
        ['id' => 200, 'team_id' => 20, 'owner_id' => 1, 'folder_id' => 7],
        ['id' => 300, 'team_id' => 20, 'owner_id' => 2, 'folder_id' => 8],
    ]);
});

it('compares the row with a column of the user\'s row, read by a subquery', function () {
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    $sql = userColumnGuard(userColumnUser(1))->filterQuery(DB::table('uc_documents'), 'view')->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "uc_documents"
        where ("uc_documents"."team_id" = (select "uc_users"."team_id" from "uc_users" where "uc_users"."id" = 1))
    SQL))
        ->and(documentsUserCanView(userColumnUser(1)))->toBe([100])
        ->and(documentsUserCanView(userColumnUser(2)))->toBe([200, 300]);
});

it('writes the user\'s key as the value itself', function () {
    bindUserColumnRules('for uc_documents { if owned_by_user they can view }');

    $sql = userColumnGuard(userColumnUser(2))->filterQuery(DB::table('uc_documents'), 'view')->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "uc_documents" where ("uc_documents"."owner_id" = 2)
    SQL))
        ->and(documentsUserCanView(userColumnUser(2)))->toBe([100, 300]);
});

it('reads as a string in raw SQL', function () {
    bindUserColumnRules('for uc_documents { if in_users_team_raw they can view }');

    expect(documentsUserCanView(userColumnUser(1)))->toBe([100]);
});

it('follows the row under an alias', function () {
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    $ids = userColumnGuard(userColumnUser(1))
        ->filterQuery(DB::table('uc_documents as d'), 'view')
        ->pluck('d.id')
        ->all();

    expect($ids)->toBe([100]);
});

it('negates a condition that reads the user\'s row', function () {
    bindUserColumnRules('for uc_documents { if not in_users_team they can view }');

    expect(documentsUserCanView(userColumnUser(1)))->toBe([200, 300]);
});

it('reads the user\'s row from a global condition', function () {
    bindUserColumnRules('for uc_documents { if is_admin they can view }');

    expect(documentsUserCanView(userColumnUser(2)))->toBe([100, 200, 300])
        ->and(documentsUserCanView(userColumnUser(1)))->toBe([]);
});

it('answers a check about one row', function () {
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    $guard = userColumnGuard(userColumnUser(1));

    expect($guard->can('view', UserColumnDocument::query()->findOrFail(100)))->toBeTrue()
        ->and($guard->can('view', UserColumnDocument::query()->findOrFail(200)))->toBeFalse();
});

it('reads the user\'s row inside a can(...) hop', function () {
    bindUserColumnRules(<<<'RULES'
        for uc_documents { if can(view for uc_folders(@column uc_documents.folder_id)) they can view }
        for uc_folders { if in_users_team they can view }
    RULES);

    expect(documentsUserCanView(userColumnUser(1)))->toBe([200])
        ->and(documentsUserCanView(userColumnUser(2)))->toBe([100, 300]);
});

it('reads the user\'s row inside a check(...) predicate', function () {
    bindUserColumnRules(<<<'RULES'
        for uc_documents { if check(in_users_team for uc_folders(@column uc_documents.folder_id)) they can view }
    RULES);

    expect(documentsUserCanView(userColumnUser(1)))->toBe([200])
        ->and(documentsUserCanView(userColumnUser(2)))->toBe([100, 300]);
});

it('reads an Eloquent user from its own table, whatever the config names', function () {
    config()->set('warrant.user_model', UserColumnOtherUser::class);
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    expect(documentsUserCanView(userColumnUser(1)))->toBe([100]);
});

it('reads a user that is not a model from the table of the configured model', function () {
    config()->set('warrant.user_model', UserColumnOtherUser::class);
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    $sql = userColumnGuard(new GenericUser(['id' => 1]))->filterQuery(DB::table('uc_documents'), 'view')->toRawSql();

    expect(normalizeWarrantSql($sql))->toContain('(select "uc_other_users"."team_id" from "uc_other_users" where "uc_other_users"."id" = 1)')
        ->and(documentsUserCanView(new GenericUser(['id' => 1])))->toBe([200, 300]);
});

it('needs a model for a user that is not one', function () {
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    expect(fn () => documentsUserCanView(new GenericUser(['id' => 1])))
        ->toThrow(BadMethodCallException::class, 'Name your user model in the warrant.user_model config.');
});

it('still answers for a user that is not a model when no condition reads its row', function () {
    bindUserColumnRules('for uc_documents { they can view }');

    expect(documentsUserCanView(new GenericUser(['id' => 1])))->toBe([100, 200, 300]);
});

it('needs a saved user', function () {
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    expect(fn () => documentsUserCanView(new UserColumnUser))
        ->toThrow(BadMethodCallException::class, 'it is not saved');
});

it('needs the user\'s table on the query\'s connection', function () {
    config()->set('database.connections.uc_other', config('database.connections.'.config('database.default')));
    bindUserColumnRules('for uc_documents { if in_users_team they can view }');

    $user = userColumnUser(1)->setConnection('uc_other');

    expect(fn () => documentsUserCanView($user))
        ->toThrow(InvalidArgumentException::class, 'is on database connection [uc_other]');
});
