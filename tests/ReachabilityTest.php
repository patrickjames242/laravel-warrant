<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\AbilityMatchMode;
use Warrant\Facades\Warrant;
use Warrant\Reachability;
use Warrant\RuleSyntaxTree\ReachabilityAnalyzer;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

beforeEach(function () {
    useWarrantSchemas([WarrantScopedModelSchema::class]);
});

/*
|--------------------------------------------------------------------------
| The pure analyzer — the decision table, straight over parsed rules.
|--------------------------------------------------------------------------
*/

function analyze(string $syntax, string $ability): Reachability
{
    return (new ReachabilityAnalyzer)->analyze(
        WarrantRuleSet::fromSyntax('course_sections', $syntax),
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

    expect(WarrantScopedModelSchema::abilityReachability('publish', $user))->toBe(Reachability::ALWAYS)
        ->and(WarrantScopedModelSchema::abilityReachability('view', $user))->toBe(Reachability::MAYBE)
        ->and(WarrantScopedModelSchema::abilityReachability('archive', $user))->toBe(Reachability::NEVER)
        ->and(WarrantScopedModelSchema::abilityReachability('create', $user))->toBe(Reachability::NEVER);
});

it('answers the three boolean questions', function () {
    bindWarrantRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWarrantTestUser();

    expect(WarrantScopedModelSchema::userCouldEverHave('view', $user))->toBeTrue()
        ->and(WarrantScopedModelSchema::userCouldEverHave('archive', $user))->toBeFalse()
        ->and(WarrantScopedModelSchema::userAlwaysHas('publish', $user))->toBeTrue()
        ->and(WarrantScopedModelSchema::userAlwaysHas('view', $user))->toBeFalse()
        ->and(WarrantScopedModelSchema::userNeverHas('archive', $user))->toBeTrue()
        ->and(WarrantScopedModelSchema::userNeverHas('publish', $user))->toBeFalse();
});

it('combines several abilities under the match mode', function () {
    bindWarrantRules("they can publish\nif is_teacher they can view");
    $user = makeWarrantTestUser();

    // create has no grant → NEVER
    expect(WarrantScopedModelSchema::userCouldEverHave(['publish', 'create'], $user, AbilityMatchMode::ALL))->toBeFalse()
        ->and(WarrantScopedModelSchema::userCouldEverHave(['publish', 'create'], $user, AbilityMatchMode::ANY))->toBeTrue()
        ->and(WarrantScopedModelSchema::userAlwaysHas(['publish', 'view'], $user, AbilityMatchMode::ALL))->toBeFalse()
        ->and(WarrantScopedModelSchema::userAlwaysHas(['publish', 'view'], $user, AbilityMatchMode::ANY))->toBeTrue();
});

it('lists abilities by reachability bucket', function () {
    bindWarrantRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWarrantTestUser();

    $sort = function (array $abilities): array {
        sort($abilities);

        return $abilities;
    };

    expect($sort(WarrantScopedModelSchema::getUserPossibleAbilities($user)))->toBe(['publish', 'view'])
        ->and($sort(WarrantScopedModelSchema::getUserGuaranteedAbilities($user)))->toBe(['publish'])
        ->and($sort(WarrantScopedModelSchema::getUserImpossibleAbilities($user)))->toBe(['archive', 'create', 'update']);
});

it('honours implicit rules in the analysis', function () {
    useWarrantSchemas([WarrantImplicitRulesSchema::class]);
    // Resolver grants nothing; the schema's implicit rules grant publish, deny archive.
    bindWarrantRules('');
    $user = makeWarrantTestUser();

    expect(WarrantImplicitRulesSchema::abilityReachability('publish', $user))->toBe(Reachability::ALWAYS)
        ->and(WarrantImplicitRulesSchema::abilityReachability('archive', $user))->toBe(Reachability::NEVER);
});

it('requires a user, explicit or authenticated', function () {
    bindWarrantRules('they can publish');

    expect(fn () => WarrantScopedModelSchema::abilityReachability('publish'))
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

    expect(WarrantScopedModel::userCouldEverHave('publish', $user))->toBeTrue()
        ->and(WarrantScopedModel::userNeverHas('archive', $user))->toBeTrue()
        ->and(WarrantScopedModel::abilityReachability('publish', $user))->toBe(Reachability::ALWAYS);
});

it('proxies through the facade by schema key', function () {
    bindWarrantRules("they can publish\nthey cannot archive");
    $user = makeWarrantTestUser();

    expect(Warrant::userCouldEverHave('course_sections', 'publish', $user))->toBeTrue()
        ->and(Warrant::userAlwaysHas('course_sections', 'publish', $user))->toBeTrue()
        ->and(Warrant::abilityReachability('course_sections', 'archive', $user))->toBe(Reachability::NEVER);
});
