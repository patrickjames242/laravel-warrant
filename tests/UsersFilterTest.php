<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Facades\Warrant;
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
| Warrant::filterUsers(): the users who hold abilities on a row
|------------------------------------------------------------------------------
|
| A filter over users answers for every user at once. The target's rows go
| inside a correlated `exists`, the rules are resolved for no user, conditions
| are handed a null `$c->user`, and `$c->userColumn()` names a column of each row
| of the users query.
|
*/

class FuUser extends User
{
    use HasWarrantSchema;

    protected $table = 'fu_users';

    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return FuUserSchema::class;
    }
}

class FuDocument extends Model
{
    use HasWarrantSchema;

    protected $table = 'fu_documents';

    public $timestamps = false;

    public static function warrantSchema(): string
    {
        return FuDocumentSchema::class;
    }
}

class FuUserSchema extends ModelSchema
{
    public const model = FuUser::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isSelf(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('id'), $c->userColumn('id'));
    }
}

class FuDocumentSchema extends ModelSchema
{
    public const model = FuDocument::class;

    /** @var list<mixed> The `$c->user` each condition was handed. */
    public static array $usersSeen = [];

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[Ability]
    public const CREATE = 'create';

    #[RowCondition]
    public function inUsersTeam(RowConditionContext $c): BuilderContract
    {
        self::$usersSeen[] = $c->user;

        return $c->query->whereColumn($c->column('team_id'), $c->userColumn('team_id'));
    }

    #[RowCondition]
    public function ownedByUser(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner_id'), $c->userColumn('id'));
    }

    #[RowCondition]
    public function isPublic(RowConditionContext $c): BuilderContract|bool
    {
        if ($c->model !== null) {
            return (bool) $c->model->is_public;
        }

        return $c->query->where($c->column('is_public'), true);
    }

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): BuilderContract
    {
        self::$usersSeen[] = $c->user;

        return $c->query->where($c->userColumn('role'), 'admin');
    }
}

class FuRuleProvider implements IRuleProvider
{
    /** @var list<mixed> The user each rules() call was made for. */
    public array $usersSeen = [];

    public function __construct(private string $rules) {}

    public function rules(RuleProviderContext $context): string
    {
        $this->usersSeen[] = $context->user;

        return $this->rules;
    }
}

function bindFuRules(string $rules): FuRuleProvider
{
    app()->instance(IRuleProvider::class, $provider = new FuRuleProvider($rules));

    return $provider;
}

/**
 * @return list<int>
 */
function fuIds(EloquentBuilder $users, string $qualifier = 'fu_users'): array
{
    return $users->orderBy("{$qualifier}.id")->pluck("{$qualifier}.id")->all();
}

function fuSql(EloquentBuilder $users): string
{
    return normalizeWarrantSql($users->toRawSql());
}

function fuDocument(int $id = 100): FuDocument
{
    return FuDocument::query()->findOrFail($id);
}

beforeEach(function () {
    useWarrantSchemas([
        'fu_users' => FuUserSchema::class,
        'fu_documents' => FuDocumentSchema::class,
    ]);
    FuDocumentSchema::$usersSeen = [];

    Schema::create('fu_users', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
        $table->string('role');
    });

    Schema::create('fu_documents', function ($table) {
        $table->increments('id');
        $table->integer('team_id');
        $table->integer('owner_id');
        $table->boolean('is_public');
    });

    DB::table('fu_users')->insert([
        ['id' => 1, 'team_id' => 10, 'role' => 'member'],
        ['id' => 2, 'team_id' => 20, 'role' => 'admin'],
        ['id' => 3, 'team_id' => 30, 'role' => 'member'],
    ]);

    DB::table('fu_documents')->insert([
        ['id' => 100, 'team_id' => 10, 'owner_id' => 2, 'is_public' => false],
        ['id' => 200, 'team_id' => 20, 'owner_id' => 3, 'is_public' => true],
    ]);
});

it('keeps the users who hold the ability on the row', function () {
    bindFuRules('for fu_documents { if owned_by_user or in_users_team they can edit }');

    expect(fuIds(Warrant::filterUsers(FuUser::query(), 'edit', fuDocument())))->toBe([1, 2]);
});

it('correlates the target\'s rows with each row of the users query', function () {
    bindFuRules('for fu_documents { if in_users_team they can edit }');

    expect(fuSql(Warrant::filterUsers(FuUser::query(), 'edit', fuDocument())))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "fu_users"
        where exists (
            select * from "fu_documents"
            where "fu_documents"."id" = 100
            and ("fu_documents"."team_id" = "fu_users"."team_id")
        )
    SQL));
});

it('resolves the rules for no user and hands the conditions a null user', function () {
    $provider = bindFuRules('for fu_documents { if in_users_team or is_admin they can edit }');
    auth()->setUser(FuUser::query()->findOrFail(1));

    Warrant::filterUsers(FuUser::query(), 'edit', fuDocument())->get();

    expect($provider->usersSeen)->each->toBeNull()
        ->and(FuDocumentSchema::$usersSeen)->not->toBeEmpty()
        ->and(FuDocumentSchema::$usersSeen)->each->toBeNull();
});

it('keeps a null user through a can(...) hop', function () {
    $provider = bindFuRules(<<<'RULES'
        for fu_documents { if can(view for fu_users(@column fu_documents.owner_id)) they can view }
        for fu_users { if is_self they can view }
    RULES);
    auth()->setUser(FuUser::query()->findOrFail(1));

    expect(fuIds(Warrant::filterUsers(FuUser::query(), 'view', fuDocument())))->toBe([2])
        ->and($provider->usersSeen)->each->toBeNull();
});

it('names a hop into the users table so it does not hide the users query', function () {
    bindFuRules(<<<'RULES'
        for fu_documents { if can(view for fu_users(@column fu_documents.owner_id)) they can view }
        for fu_users { if is_self they can view }
    RULES);

    $sql = fuSql(Warrant::filterUsers(FuUser::query(), 'view', fuDocument()));

    expect($sql)->toContain('"fu_users" as "fu_users_1"')
        ->and($sql)->toContain('"fu_users_1"."id" = "fu_users"."id"');
});

it('filters users by what they may do to a user', function () {
    bindFuRules('for fu_users { if is_self they can view }');

    $users = Warrant::filterUsers(FuUser::query(), 'view', FuUser::query()->findOrFail(2));

    expect(fuSql($users))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "fu_users"
        where exists (
            select * from "fu_users" as "fu_users_1"
            where "fu_users_1"."id" = 2
            and ("fu_users_1"."id" = "fu_users"."id")
        )
    SQL))
        ->and(fuIds($users))->toBe([2]);
});

it('compiles an ability with no target straight onto the users query', function () {
    bindFuRules('for fu_documents { if is_admin they can create }');

    $users = Warrant::filterUsers(FuUser::query(), 'create', FuDocument::class);

    expect(fuSql($users))->toBe(normalizeWarrantSql(<<<'SQL'
        select * from "fu_users" where ("fu_users"."role" = 'admin')
    SQL))
        ->and(fuIds($users))->toBe([2]);
});

it('lets no user through when a no-target ability rests on a row condition', function () {
    bindFuRules('for fu_documents { if in_users_team they can create }');

    expect(fuIds(Warrant::filterUsers(FuUser::query(), 'create', FuDocument::class)))->toBe([]);
});

it('follows an alias on the users query', function () {
    bindFuRules('for fu_documents { if in_users_team they can edit }');

    $users = Warrant::filterUsers(FuUser::query()->from('fu_users as u'), 'edit', fuDocument());

    expect(fuSql($users))->toContain('"fu_documents"."team_id" = "u"."team_id"')
        ->and(fuIds($users, 'u'))->toBe([1]);
});

it('takes the users query\'s name from rowsAs when its from does not say', function () {
    bindFuRules('for fu_documents { if in_users_team they can edit }');

    $users = FuUser::query()->fromSub(DB::table('fu_users'), 'everyone');

    expect(fuIds(Warrant::filterUsers($users, 'edit', fuDocument(), rowsAs: 'everyone'), 'everyone'))->toBe([1]);
});

it('takes a row by key, named as a check names one', function () {
    bindFuRules('for fu_documents { if in_users_team they can edit }');

    expect(fuIds(Warrant::filterUsers(FuUser::query(), 'edit', [FuDocumentSchema::class, 200])))->toBe([2]);
});

it('lets no user through for a key that names no row', function () {
    bindFuRules('for fu_documents { they can edit }');

    expect(fuIds(Warrant::filterUsers(FuUser::query(), 'edit', [FuDocumentSchema::class, 999])))->toBe([]);
});

it('lets no user through for a target with no key', function () {
    bindFuRules('for fu_documents { they can edit }');

    $users = Warrant::filterUsers(FuUser::query(), 'edit', new FuDocument);

    expect(fuSql($users))->toBe(normalizeWarrantSql('select * from "fu_users" where 1 = 0'))
        ->and(fuIds($users))->toBe([]);
});

it('lets a condition answer from the loaded target alone', function () {
    bindFuRules('for fu_documents { if is_public they can view }');

    expect(fuIds(Warrant::filterUsers(FuUser::query(), 'view', fuDocument(200))))->toBe([1, 2, 3])
        ->and(fuIds(Warrant::filterUsers(FuUser::query(), 'view', fuDocument(100))))->toBe([]);
});

it('combines abilities under the match mode', function () {
    bindFuRules(<<<'RULES'
        for fu_documents {
            if in_users_team they can view
            if owned_by_user they can edit
        }
    RULES);

    expect(fuIds(Warrant::filterUsers(FuUser::query(), ['view', 'edit'], fuDocument())))->toBe([])
        ->and(fuIds(Warrant::filterUsers(FuUser::query(), ['view', 'edit'], fuDocument(), AbilityMatchMode::ANY)))->toBe([1, 2]);
});

it('refuses a users query on another connection than the schema', function () {
    config()->set('database.connections.fu_other', config('database.connections.'.config('database.default')));
    bindFuRules('for fu_documents { they can edit }');

    expect(fn () => Warrant::filterUsers(FuUser::on('fu_other'), 'edit', fuDocument()))
        ->toThrow(InvalidArgumentException::class, 'Cannot filter users by schema [fu_documents]');
});
