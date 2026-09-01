<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\Facades\Warrant;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\WarrantManager;

/**
 * Counts resolutions so a test can assert the rule set was resolved once rather
 * than once per check.
 */
class CountingWarrantRuleResolver implements RuleResolver
{
    public int $calls = 0;

    public function __construct(private WarrantRuleSet $ruleSet) {}

    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        $this->calls++;

        return $this->ruleSet;
    }
}

function bindCountingWarrantResolver(string $syntax = 'if is_teacher they can publish'): CountingWarrantRuleResolver
{
    $resolver = new CountingWarrantRuleResolver(
        WarrantRuleSet::fromSyntax($syntax, 'course_sections')
    );

    app()->instance(RuleResolver::class, $resolver);

    return $resolver;
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

it('returns the same guard for the same user', function () {
    $user = makeWarrantTestUser();

    expect(Warrant::guard($user))->toBe(Warrant::guard($user));
});

it('returns the same guard for two instances of the same user', function () {
    // Rules follow the auth identifier, not the object, so a re-fetched user
    // shares the memoized guard.
    expect(Warrant::guard(makeWarrantTestUser('role-1')))
        ->toBe(Warrant::guard(makeWarrantTestUser('role-1')));
});

it('returns different guards for different users', function () {
    expect(Warrant::guard(makeWarrantTestUser('role-1')))
        ->not->toBe(Warrant::guard(makeWarrantTestUser('role-2')));
});

it('does not share a guard between two users with no identifier', function () {
    // Falls back to object identity: two identifier-less users are distinct.
    expect(Warrant::guard(makeWarrantTestUser(null)))
        ->not->toBe(Warrant::guard(makeWarrantTestUser(null)));
});

it('returns the same schema guard across repeated forSchema calls', function () {
    $user = makeWarrantTestUser();

    expect(Warrant::forSchema(WarrantTestModel::class, $user))
        ->toBe(Warrant::forSchema(WarrantTestModel::class, $user));
});

it('resolves the rule set once across repeated lookups for one user', function () {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser();

    Warrant::forSchema(WarrantTestModel::class, $user)->resolvedRuleSet();
    Warrant::forSchema(WarrantTestModel::class, $user)->resolvedRuleSet();
    Warrant::forSchema(WarrantTestModel::class, $user)->resolvedRuleSet();

    expect($resolver->calls)->toBe(1);
});

it('resolves the rule set once per user', function () {
    $resolver = bindCountingWarrantResolver();

    Warrant::forSchema(WarrantTestModel::class, makeWarrantTestUser('role-1'))->resolvedRuleSet();
    Warrant::forSchema(WarrantTestModel::class, makeWarrantTestUser('role-2'))->resolvedRuleSet();
    Warrant::forSchema(WarrantTestModel::class, makeWarrantTestUser('role-1'))->resolvedRuleSet();

    expect($resolver->calls)->toBe(2);
});

it('re-resolves after a flush', function () {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser();

    Warrant::forSchema(WarrantTestModel::class, $user)->resolvedRuleSet();
    Warrant::flush();
    Warrant::forSchema(WarrantTestModel::class, $user)->resolvedRuleSet();

    expect($resolver->calls)->toBe(2);
});

it('hands out a fresh guard after a flush', function () {
    $user = makeWarrantTestUser();
    $before = Warrant::guard($user);

    Warrant::flush();

    expect(Warrant::guard($user))->not->toBe($before);
});

it('drops the memo once the guard cap is exceeded', function () {
    $manager = app(WarrantManager::class);
    $first = $manager->guard(makeWarrantTestUser('role-0'));

    for ($i = 1; $i <= 1000; $i++) {
        $manager->guard(makeWarrantTestUser("role-{$i}"));
    }

    // The 1000-entry cap was hit, so the map reset and the first user's guard is
    // no longer memoized.
    expect($manager->guard(makeWarrantTestUser('role-0')))->not->toBe($first);
});

it('flushes only the given user', function () {
    $alice = makeWarrantTestUser('role-1');
    $bob = makeWarrantTestUser('role-2');

    $aliceGuard = Warrant::guard($alice);
    $bobGuard = Warrant::guard($bob);

    Warrant::flush($alice);

    expect(Warrant::guard($alice))->not->toBe($aliceGuard)
        ->and(Warrant::guard($bob))->toBe($bobGuard);
});

it('re-resolves only the flushed user', function () {
    $resolver = bindCountingWarrantResolver();
    $alice = makeWarrantTestUser('role-1');
    $bob = makeWarrantTestUser('role-2');

    Warrant::forSchema(WarrantTestModel::class, $alice)->resolvedRuleSet();
    Warrant::forSchema(WarrantTestModel::class, $bob)->resolvedRuleSet();
    expect($resolver->calls)->toBe(2);

    Warrant::flush($alice);

    Warrant::forSchema(WarrantTestModel::class, $bob)->resolvedRuleSet();
    expect($resolver->calls)->toBe(2);

    Warrant::forSchema(WarrantTestModel::class, $alice)->resolvedRuleSet();
    expect($resolver->calls)->toBe(3);
});

it('flushes a user given a different instance of the same identity', function () {
    $guard = Warrant::guard(makeWarrantTestUser('role-1'));

    Warrant::flush(makeWarrantTestUser('role-1'));

    expect(Warrant::guard(makeWarrantTestUser('role-1')))->not->toBe($guard);
});

it('ignores a flush for a user that was never memoized', function () {
    $alice = makeWarrantTestUser('role-1');
    $aliceGuard = Warrant::guard($alice);

    Warrant::flush(makeWarrantTestUser('role-99'));

    expect(Warrant::guard($alice))->toBe($aliceGuard);
});

it('flushes everything when given no user', function () {
    $alice = makeWarrantTestUser('role-1');
    $bob = makeWarrantTestUser('role-2');

    $aliceGuard = Warrant::guard($alice);
    $bobGuard = Warrant::guard($bob);

    Warrant::flush();

    expect(Warrant::guard($alice))->not->toBe($aliceGuard)
        ->and(Warrant::guard($bob))->not->toBe($bobGuard);
});

/**
 * The provider registers these by class constant, and the Octane classes are not
 * installed — dispatching by name proves the listener is wired to the same string
 * the dispatcher would use if Octane were present.
 */
it('flushes memoized guards at a long-lived-process boundary', function (string $event) {
    $user = makeWarrantTestUser();
    $guard = Warrant::guard($user);

    app('events')->dispatch($event);

    expect(Warrant::guard($user))->not->toBe($guard);
})->with([
    'octane request terminated' => \Laravel\Octane\Events\RequestTerminated::class,
    'octane task terminated' => \Laravel\Octane\Events\TaskTerminated::class,
    'queue job processed' => \Illuminate\Queue\Events\JobProcessed::class,
    'queue job failed' => \Illuminate\Queue\Events\JobFailed::class,
]);

it('re-resolves rules after a queue worker boundary', function () {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser();

    Warrant::forSchema(WarrantTestModel::class, $user)->resolvedRuleSet();
    app('events')->dispatch(\Illuminate\Queue\Events\JobProcessed::class);
    Warrant::forSchema(WarrantTestModel::class, $user)->resolvedRuleSet();

    expect($resolver->calls)->toBe(2);
});

it('leaves guards alone on an unrelated event', function () {
    $user = makeWarrantTestUser();
    $guard = Warrant::guard($user);

    app('events')->dispatch('some.unrelated.event');

    expect(Warrant::guard($user))->toBe($guard);
});
