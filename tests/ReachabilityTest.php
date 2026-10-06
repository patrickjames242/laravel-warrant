<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Compiling\ReachabilityAnalyzer;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Reachability;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\DerivedCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

beforeEach(function () {
    useWarrantSchemas([
        'course_sections' => WarrantScopedModelSchema::class,
        'rch_docs' => RchDocSchema::class,
        'rch_folders' => RchFolderSchema::class,
    ]);
});

/*
|--------------------------------------------------------------------------
| The pure analyzer — the decision table, straight over parsed rules.
|--------------------------------------------------------------------------
*/

function analyze(string $syntax, string $ability, ?WarrantSchema $schema = null): Reachability
{
    $schema ??= new WarrantScopedModelSchema;

    return (new ReachabilityAnalyzer)->analyze(
        (new RuleSetExpander)->expand(
            WarrantSyntax::parse($syntax)->scopedTo($schema::schemaKey()),
            $schema,
        ),
        $ability,
    );
}

it('is ALWAYS for an unconditional grant with no conditional deny', function () {
    expect(analyze('they can publish', 'publish'))->toBe(Reachability::ALWAYS);
});

it('is MAYBE for a conditional grant', function () {
    expect(analyze('if is_teacher they can view', 'view'))->toBe(Reachability::MAYBE);
});

it('is MAYBE when an unconditional grant meets a conditional deny', function () {
    expect(analyze("they can view\nif is_advisor they cannot view", 'view'))->toBe(Reachability::MAYBE);
});

it('is NEVER when there is no grant at all', function () {
    expect(analyze('if is_teacher they can view', 'publish'))->toBe(Reachability::NEVER);
});

it('is NEVER when an unconditional deny sits in an unconditional rule', function () {
    // One rule: unconditional, grants and denies publish → the hard deny wins.
    expect(analyze("they can publish\nthey cannot publish", 'publish'))->toBe(Reachability::NEVER);
});

it('is NEVER when a standalone unconditional deny precedes a conditional grant', function () {
    // Leading `they cannot view` is its own unconditional rule; `if …` starts a new one.
    expect(analyze("they cannot view\nif is_teacher they can view", 'view'))->toBe(Reachability::NEVER);
});

it('is MAYBE when can and cannot share one conditional rule (no unconditional clause)', function () {
    // `if is_teacher they can view` + `they cannot view` group into ONE conditional
    // rule; with nothing unconditional, the spec keeps us unsure.
    expect(analyze("if is_teacher they can view\nthey cannot view", 'view'))->toBe(Reachability::MAYBE);
});

it('applies wildcards on both sides', function () {
    expect(analyze('they can *', 'archive'))->toBe(Reachability::ALWAYS);
    expect(analyze('they cannot *', 'archive'))->toBe(Reachability::NEVER);
    expect(analyze("if is_teacher they can *", 'archive'))->toBe(Reachability::MAYBE);
});

/*
|--------------------------------------------------------------------------
| Schema entry points.
|--------------------------------------------------------------------------
*/

it('classifies a single ability through the schema', function () {
    bindWarrantRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWarrantTestUser();

    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->reachabilityOf('publish'))->toBe(Reachability::ALWAYS)
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->reachabilityOf('view'))->toBe(Reachability::MAYBE)
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->reachabilityOf('archive'))->toBe(Reachability::NEVER)
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->reachabilityOf('create'))->toBe(Reachability::NEVER);
});

it('answers the three boolean questions', function () {
    bindWarrantRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWarrantTestUser();

    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->couldEverHave('view'))->toBeTrue()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->couldEverHave('archive'))->toBeFalse()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->alwaysHas('publish'))->toBeTrue()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->alwaysHas('view'))->toBeFalse()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->neverHas('archive'))->toBeTrue()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->neverHas('publish'))->toBeFalse();
});

it('combines several abilities under the match mode', function () {
    bindWarrantRules("they can publish\nif is_teacher they can view");
    $user = makeWarrantTestUser();

    // create has no grant → NEVER
    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->couldEverHave(['publish', 'create']))->toBeFalse()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->couldEverHaveAny(['publish', 'create']))->toBeTrue()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->alwaysHas(['publish', 'view']))->toBeFalse()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->alwaysHasAny(['publish', 'view']))->toBeTrue();
});

it('lists abilities by reachability bucket', function () {
    bindWarrantRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWarrantTestUser();

    $sort = function (array $abilities): array {
        sort($abilities);

        return $abilities;
    };

    expect($sort(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->possibleAbilities()))->toBe(['publish', 'view'])
        ->and($sort(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->guaranteedAbilities()))->toBe(['publish'])
        ->and($sort(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->impossibleAbilities()))->toBe(['archive', 'create', 'update']);
});

it('honours implicit rules in the analysis', function () {
    useWarrantSchemas(['course_sections' => WarrantImplicitRulesSchema::class]);
    // Resolver grants nothing; the schema's implicit rules grant publish, deny archive.
    bindWarrantRules('');
    $user = makeWarrantTestUser();

    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->reachabilityOf('publish'))->toBe(Reachability::ALWAYS)
        ->and(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->reachabilityOf('archive'))->toBe(Reachability::NEVER);
});

it('requires a user, explicit or authenticated', function () {
    bindWarrantRules('they can publish');

    expect(fn () => Warrant::guard()->forSchema(WarrantScopedModelSchema::class)->reachabilityOf('publish'))
        ->toThrow(InvalidArgumentException::class, 'requires an authenticated user');
});

/*
|--------------------------------------------------------------------------
| Model trait + facade proxies.
|--------------------------------------------------------------------------
*/

it('proxies through the model', function () {
    bindWarrantRules("they can publish\nthey cannot archive");
    $user = makeWarrantTestUser();

    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->couldEverHave('publish'))->toBeTrue()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->neverHas('archive'))->toBeTrue()
        ->and(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->reachabilityOf('publish'))->toBe(Reachability::ALWAYS);
});

it('proxies through the facade by schema key', function () {
    bindWarrantRules("they can publish\nthey cannot archive");
    $user = makeWarrantTestUser();

    expect(Warrant::couldEverHave('course_sections', 'publish', $user))->toBeTrue()
        ->and(Warrant::alwaysHas('course_sections', 'publish', $user))->toBeTrue()
        ->and(Warrant::reachabilityOf('course_sections', 'archive', $user))->toBe(Reachability::NEVER);
});

/*
|--------------------------------------------------------------------------
| Ability references — a grant that leans on another ability.
|--------------------------------------------------------------------------
|
| `can(x)` may answer whatever ability x may come out as, so a grant ANDed with
| one nothing grants is never made, while one ORed with it still might be.
|
*/

it('is NEVER when a grant requires an ability nothing grants', function () {
    expect(analyze('if can(publish) and is_teacher they can view', 'view'))->toBe(Reachability::NEVER)
        ->and(analyze('if can(publish) they can view', 'view'))->toBe(Reachability::NEVER);
});

it('is MAYBE when an ability nothing grants is only one way in', function () {
    expect(analyze('if can(publish) or is_teacher they can view', 'view'))->toBe(Reachability::MAYBE);
});

it('is MAYBE when the required ability is itself only conditionally granted', function () {
    expect(analyze("if is_teacher they can publish\nif can(publish) and is_advisor they can view", 'view'))
        ->toBe(Reachability::MAYBE);
});

it('is ALWAYS when a grant requires an ability that is always held', function () {
    expect(analyze("they can publish\nif can(publish) they can view", 'view'))->toBe(Reachability::ALWAYS)
        ->and(analyze("they can publish\nif can(publish) or is_teacher they can view", 'view'))->toBe(Reachability::ALWAYS);
});

it('follows a chain of ability references', function () {
    expect(analyze("if can(publish) they can update\nif can(update) they can view", 'view'))->toBe(Reachability::NEVER)
        ->and(analyze("they can publish\nif can(publish) they can update\nif can(update) they can view", 'view'))
        ->toBe(Reachability::ALWAYS);
});

it('negates an ability reference', function () {
    expect(analyze('if not can(publish) they can view', 'view'))->toBe(Reachability::ALWAYS)
        ->and(analyze("they can publish\nif not can(publish) they can view", 'view'))->toBe(Reachability::NEVER);
});

it('lets a deny that can never fire leave a grant ALWAYS', function () {
    expect(analyze("they can view\nif can(publish) they cannot view", 'view'))->toBe(Reachability::ALWAYS)
        ->and(analyze("they can view\nif can(publish) and is_teacher they cannot view", 'view'))->toBe(Reachability::ALWAYS);
});

it('makes a deny on an ability that is always held unconditional', function () {
    expect(analyze("they can publish\nthey can view\nif can(publish) they cannot view", 'view'))->toBe(Reachability::NEVER)
        ->and(analyze("they can publish\nthey can view\nif can(publish) and is_teacher they cannot view", 'view'))
        ->toBe(Reachability::MAYBE);
});

it('is MAYBE through a cycle, rather than looping', function () {
    expect(analyze('if can(view) they can view', 'view'))->toBe(Reachability::MAYBE)
        ->and(analyze("if can(update) they can view\nif can(view) they can update", 'view'))->toBe(Reachability::MAYBE);
});

it('is MAYBE for an ability on another schema when no rule set can be had', function () {
    expect(analyze('if can(manage for rch_folders) they can view', 'view'))->toBe(Reachability::MAYBE);
});

/*
|--------------------------------------------------------------------------
| Derived conditions — read through, not assumed.
|--------------------------------------------------------------------------
*/

it('reads a derived condition that answers a constant', function () {
    $schema = new RchDocSchema;

    expect(analyze('if always_open they can view', 'view', $schema))->toBe(Reachability::ALWAYS)
        ->and(analyze('if never_open they can view', 'view', $schema))->toBe(Reachability::NEVER)
        ->and(analyze('if never_open or is_owner they can view', 'view', $schema))->toBe(Reachability::MAYBE)
        ->and(analyze("they can view\nif never_open they cannot view", 'view', $schema))->toBe(Reachability::ALWAYS);
});

it('reads a derived condition that answers unknown as never granting', function () {
    $schema = new RchDocSchema;

    expect(analyze('if undecided they can view', 'view', $schema))->toBe(Reachability::NEVER)
        ->and(analyze('if not undecided they can view', 'view', $schema))->toBe(Reachability::NEVER)
        ->and(analyze('if undecided or always_open they can view', 'view', $schema))->toBe(Reachability::ALWAYS);
});

it('reads through a derived condition to the expression it answers with', function () {
    $schema = new RchDocSchema;

    expect(analyze('if owned_or_open they can view', 'view', $schema))->toBe(Reachability::ALWAYS)
        ->and(analyze('if editable they can view', 'view', $schema))->toBe(Reachability::NEVER)
        ->and(analyze("they can edit\nif editable they can view", 'view', $schema))->toBe(Reachability::ALWAYS);
});

/*
|--------------------------------------------------------------------------
| Through the guard — references to other schemas, resolved for the user.
|--------------------------------------------------------------------------
*/

/** @param array<string, string> $syntaxByKey */
function bindRchRules(array $syntaxByKey): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantSyntax::parse($syntax)->scopedTo($key);
    }

    app()->instance(RuleResolver::class, new class($sets) implements RuleResolver {
        /** @param array<string, RuleSetNode> $sets */
        public function __construct(private array $sets) {}

        public function resolve(RuleResolutionContext $context): RuleSetNode
        {
            return $this->sets[$context->schemaKey] ?? new RuleSetNode($context->schemaKey, []);
        }
    });
}

function rchReachability(string $ability = 'edit'): Reachability
{
    return Warrant::guard(makeWarrantTestUser())->forSchema(RchDocSchema::class)->reachabilityOf($ability);
}

it('follows an unbound can(... for <schema>) into that schema\'s rules for the user', function (string $folders, Reachability $expected) {
    bindRchRules([
        'rch_docs' => 'if can(manage for rch_folders) they can edit',
        'rch_folders' => $folders,
    ]);

    expect(rchReachability())->toBe($expected);
})->with([
    'nothing grants it' => ['', Reachability::NEVER],
    'granted on a condition' => ['if is_owner they can manage', Reachability::MAYBE],
    'always granted' => ['they can manage', Reachability::ALWAYS],
]);

it('could ever grant on an ability nothing grants when it is only one way in', function () {
    bindRchRules(['rch_docs' => 'if can(manage for rch_folders) or is_owner they can edit']);
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(RchDocSchema::class);

    expect($guard->couldEverHave('edit'))->toBeTrue()
        ->and($guard->alwaysHas('edit'))->toBeFalse();
});

it('never grants on an ability nothing grants when it is required', function () {
    bindRchRules(['rch_docs' => 'if can(manage for rch_folders) and is_owner they can edit']);
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(RchDocSchema::class);

    expect($guard->couldEverHave('edit'))->toBeFalse()
        ->and($guard->neverHas('edit'))->toBeTrue()
        ->and($guard->impossibleAbilities())->toContain('edit');
});

it('can rule a row-bound reference out, but never guarantee it', function (string $folders, Reachability $expected) {
    bindRchRules([
        'rch_docs' => 'if can(manage for rch_folders(@column folder_id)) they can edit',
        'rch_folders' => $folders,
    ]);

    expect(rchReachability())->toBe($expected);
})->with([
    'nothing grants it' => ['', Reachability::NEVER],
    'always granted, but the row may be missing' => ['they can manage', Reachability::MAYBE],
]);

it('reads a can(...) inside a check(...) predicate as an ability of the schema checked', function (string $docs, string $folders, Reachability $expected) {
    bindRchRules(['rch_docs' => $docs, 'rch_folders' => $folders]);

    expect(rchReachability())->toBe($expected);
})->with([
    'unbound, nothing grants it' => ['if check(can(manage) for rch_folders) they can edit', '', Reachability::NEVER],
    'unbound, always granted' => ['if check(can(manage) for rch_folders) they can edit', 'they can manage', Reachability::ALWAYS],
    'unbound, ANDed with a condition' => ['if check(is_owner and can(manage) for rch_folders) they can edit', '', Reachability::NEVER],
    'row-bound, nothing grants it' => ['if check(can(manage) for rch_folders(@column folder_id)) they can edit', '', Reachability::NEVER],
    'row-bound, always granted' => ['if check(can(manage) for rch_folders(@column folder_id)) they can edit', 'they can manage', Reachability::MAYBE],
]);

it('is MAYBE through a cycle that crosses schemas', function () {
    bindRchRules([
        'rch_docs' => 'if can(view for rch_folders) they can view',
        'rch_folders' => 'if can(view for rch_docs) they can view',
    ]);

    expect(rchReachability('view'))->toBe(Reachability::MAYBE);
});

it('reads derived conditions through the guard', function () {
    bindRchRules(['rch_docs' => "if always_open they can view\nif never_open they can edit\nif editable they can publish"]);
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(RchDocSchema::class);

    expect($guard->guaranteedAbilities())->toBe(['view'])
        ->and($guard->possibleAbilities())->toBe(['view']);
});

// -- fixtures -----------------------------------------------------------------

class RchDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'rch_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return RchDocSchema::class;
    }
}

class RchDocSchema extends WarrantSchema
{
    public const model = RchDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[Ability]
    public const PUBLISH = 'publish';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }

    #[DerivedCondition]
    public function alwaysOpen(): bool
    {
        return true;
    }

    #[DerivedCondition]
    public function neverOpen(): bool
    {
        return false;
    }

    #[DerivedCondition]
    public function undecided(): ?bool
    {
        return null;
    }

    #[DerivedCondition]
    public function ownedOrOpen(): string
    {
        return 'is_owner or always_open';
    }

    #[DerivedCondition]
    public function editable(): string
    {
        return 'can(edit)';
    }
}

class RchFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'rch_folders';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return RchFolderSchema::class;
    }
}

class RchFolderSchema extends WarrantSchema
{
    public const model = RchFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const MANAGE = 'manage';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }
}
