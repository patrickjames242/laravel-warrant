<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\Facades\Warrant;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Types\Classes\RowlessSchema;
use Warrant\WarrantManager;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| Rule sets read through the manager
|------------------------------------------------------------------------------
|
| Guards are built afresh and hold no rules, so a guard kept across a flush sees
| the rules resolved after it. The memo is keyed by (schema, user), holds a user
| memoized by object identity alive, and caps users rather than entries. A
| can(...) hop and reachability both resolve the hopped schema's rules for the
| guard's own user, never the logged-in one or none.
|
*/

class RscPanelSchema extends RowlessSchema
{
    #[Ability]
    public const VIEW = 'view';
}

class RscBillingSchema extends RowlessSchema
{
    #[Ability]
    public const EXPORT = 'export';
}

/**
 * Answers with rules per user, counted by schema, and records each user it was
 * asked about by schema.
 */
class RscRuleProvider implements IRuleProvider
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @var array<string, list<mixed>> */
    public array $usersSeen = [];

    /**
     * @param array<string, string> $rulesByRole The rules for a user, keyed by
     *   their identifier; '' for anyone else.
     */
    public function __construct(public array $rulesByRole) {}

    public function rules(RuleProviderContext $context): string
    {
        $this->calls[$context->schemaKey] = ($this->calls[$context->schemaKey] ?? 0) + 1;
        $this->usersSeen[$context->schemaKey][] = $context->user;

        $role = $context->user?->getAuthIdentifier();

        return $this->rulesByRole[is_scalar($role) ? (string) $role : ''] ?? $this->rulesByRole[''] ?? '';
    }
}

/**
 * @param array<string, string> $rulesByRole
 */
function bindRscRules(array $rulesByRole): RscRuleProvider
{
    app()->instance(IRuleProvider::class, $provider = new RscRuleProvider($rulesByRole));

    return $provider;
}

/**
 * A provider that keeps nothing it is handed, so the memo is the only thing
 * that could be holding a user.
 */
function bindRscForgetfulRules(): void
{
    app()->instance(IRuleProvider::class, new class implements IRuleProvider {
        public function rules(RuleProviderContext $context): string
        {
            return '';
        }
    });
}

beforeEach(function () {
    useWarrantSchemas([
        'rsc_panel' => RscPanelSchema::class,
        'rsc_billing' => RscBillingSchema::class,
    ]);
});

// -- guards are not memoized --------------------------------------------------

it('builds a new guard on every call', function () {
    $user = makeWarrantTestUser('alice');

    expect(Warrant::guard($user))->not->toBe(Warrant::guard($user))
        ->and(Warrant::forSchema(RscPanelSchema::class, $user))->not->toBe(Warrant::forSchema(RscPanelSchema::class, $user));
});

it('answers a kept guard from the rules memoized for its user, until a flush', function () {
    $provider = bindRscRules(['' => 'for rsc_panel { they can view }']);
    $guard = Warrant::forSchema(RscPanelSchema::class, makeWarrantTestUser('alice'));

    expect($guard->can('view'))->toBeTrue();

    $provider->rulesByRole = ['' => 'for rsc_panel { }'];
    expect($guard->can('view'))->toBeTrue();

    Warrant::flush();
    expect($guard->can('view'))->toBeFalse();
});

// -- the memo's keys ------------------------------------------------------------

it('resolves each schema apart for one user', function () {
    $provider = bindRscRules(['' => '']);
    $user = makeWarrantTestUser('alice');

    app(WarrantManager::class)->ruleSet('rsc_panel', $user);
    app(WarrantManager::class)->ruleSet('rsc_billing', $user);
    app(WarrantManager::class)->ruleSet('rsc_panel', $user);

    expect($provider->calls)->toBe(['rsc_panel' => 1, 'rsc_billing' => 1]);
});

it('does not share rule sets between user classes with the same identifier', function () {
    $provider = bindRscRules(['' => '']);

    app(WarrantManager::class)->ruleSet('rsc_panel', makeWarrantTestUser('1'));
    app(WarrantManager::class)->ruleSet('rsc_panel', new GenericUser(['id' => '1']));

    expect($provider->calls['rsc_panel'])->toBe(2);
});

// -- users memoized by object identity ---------------------------------------------

it('holds a user memoized by object identity alive until it is flushed', function () {
    bindRscForgetfulRules();
    $user = makeWarrantTestUser(null);
    $reference = WeakReference::create($user);

    app(WarrantManager::class)->ruleSet('rsc_panel', $user);
    unset($user);
    gc_collect_cycles();

    expect($reference->get())->not->toBeNull();

    Warrant::flush();
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

it('does not hold a user memoized by identifier', function () {
    bindRscForgetfulRules();
    $user = makeWarrantTestUser('alice');
    $reference = WeakReference::create($user);

    app(WarrantManager::class)->ruleSet('rsc_panel', $user);
    unset($user);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

it('flushes a user memoized by object identity only through that instance', function () {
    $provider = bindRscRules(['' => '']);
    $user = makeWarrantTestUser(null);

    app(WarrantManager::class)->ruleSet('rsc_panel', $user);
    Warrant::flush(makeWarrantTestUser(null));
    app(WarrantManager::class)->ruleSet('rsc_panel', $user);
    expect($provider->calls['rsc_panel'])->toBe(1);

    Warrant::flush($user);
    app(WarrantManager::class)->ruleSet('rsc_panel', $user);
    expect($provider->calls['rsc_panel'])->toBe(2);
});

// -- the cap --------------------------------------------------------------------

it('counts users, not schemas, toward the cap', function () {
    $provider = bindRscRules(['' => '']);
    $manager = app(WarrantManager::class);

    for ($i = 0; $i < 1000; $i++) {
        $manager->ruleSet('rsc_panel', makeWarrantTestUser("role-{$i}"));
    }

    // A user already memoized asks for another schema: still 1000 users, no reset.
    $manager->ruleSet('rsc_billing', makeWarrantTestUser('role-0'));
    $manager->ruleSet('rsc_panel', makeWarrantTestUser('role-999'));

    expect($provider->calls)->toBe(['rsc_panel' => 1000, 'rsc_billing' => 1]);
});

it('counts the rules for no user toward the cap, and drops them with the rest', function () {
    $provider = bindRscRules(['' => '']);
    $manager = app(WarrantManager::class);

    $manager->ruleSet('rsc_panel', null);

    for ($i = 0; $i < 999; $i++) {
        $manager->ruleSet('rsc_panel', makeWarrantTestUser("role-{$i}"));
    }

    // 1000 keys held: one more user drops the memo whole, the null user's too.
    $manager->ruleSet('rsc_panel', makeWarrantTestUser('role-new'));
    $manager->ruleSet('rsc_panel', null);

    expect($provider->calls['rsc_panel'])->toBe(1002);
});

it('lets go of users memoized by object identity when the cap drops the memo', function () {
    bindRscForgetfulRules();
    $manager = app(WarrantManager::class);
    $user = makeWarrantTestUser(null);
    $reference = WeakReference::create($user);

    $manager->ruleSet('rsc_panel', $user);
    unset($user);

    for ($i = 0; $i < 1000; $i++) {
        $manager->ruleSet('rsc_panel', makeWarrantTestUser("role-{$i}"));
    }
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

// -- the user a hop and reachability resolve for -------------------------------------

/**
 * Alice may export, and so may view the panel through the hop; nobody else may.
 * The logged-in user is set to the other one each time, so falling back to
 * them, or to no user, answers wrongly.
 */
function bindRscHopRules(): RscRuleProvider
{
    return bindRscRules([
        'alice' => <<<'RULES'
            for rsc_panel { if can(export for rsc_billing) they can view }
            for rsc_billing { they can export }
        RULES,
        '' => 'for rsc_panel { if can(export for rsc_billing) they can view }',
    ]);
}

it('resolves a can(...) hop\'s rules for the guard\'s user', function () {
    $alice = makeWarrantTestUser('alice');
    $bob = makeWarrantTestUser('bob');

    $provider = bindRscHopRules();
    auth()->setUser($bob);

    expect(Warrant::forSchema(RscPanelSchema::class, $alice)->can('view'))->toBeTrue()
        ->and($provider->usersSeen['rsc_billing'])->toBe([$alice]);

    Warrant::flush();
    $provider = bindRscHopRules();
    auth()->setUser($alice);

    expect(Warrant::forSchema(RscPanelSchema::class, $bob)->can('view'))->toBeFalse()
        ->and($provider->usersSeen['rsc_billing'])->toBe([$bob]);
});

it('resolves the rules reachability follows a hop into for the guard\'s user', function () {
    $alice = makeWarrantTestUser('alice');
    $bob = makeWarrantTestUser('bob');

    bindRscHopRules();
    auth()->setUser($bob);
    expect(Warrant::couldEverHave(RscPanelSchema::class, 'view', $alice))->toBeTrue();

    Warrant::flush();
    bindRscHopRules();
    auth()->setUser($alice);
    expect(Warrant::couldEverHave(RscPanelSchema::class, 'view', $bob))->toBeFalse();
});
