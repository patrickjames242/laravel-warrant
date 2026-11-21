<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\WarrantManager;

/**
 * Counts resolutions, and records the user each was for, so a test can assert
 * the rule set was resolved once rather than once per check.
 */
class CountingWarrantRuleProvider implements IRuleProvider
{
    public int $calls = 0;

    /** @var list<mixed> */
    public array $usersSeen = [];

    public function __construct(private RuleSetNode $ruleSet) {}

    public function rules(RuleProviderContext $context): RuleSetNode
    {
        $this->calls++;
        $this->usersSeen[] = $context->user;

        return $this->ruleSet;
    }
}

function bindCountingWarrantResolver(string $syntax = 'if is_teacher they can publish'): CountingWarrantRuleProvider
{
    $resolver = new CountingWarrantRuleProvider(
        WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('course_sections')
    );

    app()->instance(IRuleProvider::class, $resolver);

    return $resolver;
}

function resolveTestRules(?Illuminate\Contracts\Auth\Authenticatable $user): void
{
    app(WarrantManager::class)->ruleSet('course_sections', $user);
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

it('resolves the rule set once across repeated lookups for one user', function () {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser();

    Warrant::forSchema(WarrantTestModel::class, $user)->expandedRuleSet();
    Warrant::forSchema(WarrantTestModel::class, $user)->expandedRuleSet();
    Warrant::forSchema(WarrantTestModel::class, $user)->expandedRuleSet();

    expect($resolver->calls)->toBe(1);
});

it('shares the rule set between two instances of the same user', function () {
    // Rules follow the auth identifier, not the object.
    $resolver = bindCountingWarrantResolver();

    resolveTestRules(makeWarrantTestUser('role-1'));
    resolveTestRules(makeWarrantTestUser('role-1'));

    expect($resolver->calls)->toBe(1);
});

it('resolves the rule set once per user', function () {
    $resolver = bindCountingWarrantResolver();

    resolveTestRules(makeWarrantTestUser('role-1'));
    resolveTestRules(makeWarrantTestUser('role-2'));
    resolveTestRules(makeWarrantTestUser('role-1'));

    expect($resolver->calls)->toBe(2);
});

it('does not share a rule set between two users with no identifier', function () {
    // Falls back to object identity: two identifier-less users are distinct.
    $resolver = bindCountingWarrantResolver();

    resolveTestRules(makeWarrantTestUser(null));
    resolveTestRules(makeWarrantTestUser(null));

    expect($resolver->calls)->toBe(2);
});

it('resolves rules for no user apart from the logged-in user, handing the provider null', function () {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser('role-1');
    auth()->setUser($user);

    resolveTestRules($user);
    resolveTestRules(null);
    resolveTestRules(null);

    expect($resolver->calls)->toBe(2)
        ->and($resolver->usersSeen)->toBe([$user, null]);
});

it('re-resolves after a flush', function () {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser();

    resolveTestRules($user);
    Warrant::flush();
    resolveTestRules($user);

    expect($resolver->calls)->toBe(2);
});

it('drops the memo once the user cap is exceeded', function () {
    $resolver = bindCountingWarrantResolver();

    for ($i = 0; $i <= 1000; $i++) {
        resolveTestRules(makeWarrantTestUser("role-{$i}"));
    }

    // The 1000-user cap was hit, so the memo reset and the first user's rules are
    // resolved again.
    resolveTestRules(makeWarrantTestUser('role-0'));

    expect($resolver->calls)->toBe(1002);
});

it('re-resolves only the flushed user', function () {
    $resolver = bindCountingWarrantResolver();
    $alice = makeWarrantTestUser('role-1');
    $bob = makeWarrantTestUser('role-2');

    resolveTestRules($alice);
    resolveTestRules($bob);
    expect($resolver->calls)->toBe(2);

    Warrant::flush($alice);

    resolveTestRules($bob);
    expect($resolver->calls)->toBe(2);

    resolveTestRules($alice);
    expect($resolver->calls)->toBe(3);
});

it('flushes a user given a different instance of the same identity', function () {
    $resolver = bindCountingWarrantResolver();

    resolveTestRules(makeWarrantTestUser('role-1'));
    Warrant::flush(makeWarrantTestUser('role-1'));
    resolveTestRules(makeWarrantTestUser('role-1'));

    expect($resolver->calls)->toBe(2);
});

it('ignores a flush for a user that was never memoized', function () {
    $resolver = bindCountingWarrantResolver();
    $alice = makeWarrantTestUser('role-1');

    resolveTestRules($alice);
    Warrant::flush(makeWarrantTestUser('role-99'));
    resolveTestRules($alice);

    expect($resolver->calls)->toBe(1);
});

it('flushes the rules for no user only when flushing everything', function () {
    $resolver = bindCountingWarrantResolver();

    resolveTestRules(null);
    Warrant::flush(makeWarrantTestUser('role-1'));
    resolveTestRules(null);
    expect($resolver->calls)->toBe(1);

    Warrant::flush();
    resolveTestRules(null);
    expect($resolver->calls)->toBe(2);
});

it('flushes everything when given no user', function () {
    $resolver = bindCountingWarrantResolver();
    $alice = makeWarrantTestUser('role-1');
    $bob = makeWarrantTestUser('role-2');

    resolveTestRules($alice);
    resolveTestRules($bob);
    Warrant::flush();
    resolveTestRules($alice);
    resolveTestRules($bob);

    expect($resolver->calls)->toBe(4);
});

/**
 * The provider registers these by class constant, and the Octane classes are not
 * installed — dispatching by name proves the listener is wired to the same string
 * the dispatcher would use if Octane were present.
 */
it('flushes memoized rule sets at a long-lived-process boundary', function (string $event) {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser();

    resolveTestRules($user);
    app('events')->dispatch($event);
    resolveTestRules($user);

    expect($resolver->calls)->toBe(2);
})->with([
    'octane request terminated' => \Laravel\Octane\Events\RequestTerminated::class,
    'octane task terminated' => \Laravel\Octane\Events\TaskTerminated::class,
    'queue job processed' => \Illuminate\Queue\Events\JobProcessed::class,
    'queue job failed' => \Illuminate\Queue\Events\JobFailed::class,
]);

it('leaves rule sets alone on an unrelated event', function () {
    $resolver = bindCountingWarrantResolver();
    $user = makeWarrantTestUser();

    resolveTestRules($user);
    app('events')->dispatch('some.unrelated.event');
    resolveTestRules($user);

    expect($resolver->calls)->toBe(1);
});
