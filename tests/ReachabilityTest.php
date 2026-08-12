<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warden\AbilityMatchMode;
use Warden\Facades\Warden;
use Warden\Reachability;
use Warden\RuleSyntaxTree\ReachabilityAnalyzer;
use Warden\RuleSyntaxTree\WardenRuleSet;

beforeEach(function () {
    useWardenSchemas([WardenScopedModelSchema::class]);
});

/*
|--------------------------------------------------------------------------
| The pure analyzer — the decision table, straight over parsed rules.
|--------------------------------------------------------------------------
*/

function analyze(string $syntax, string $ability): Reachability
{
    return (new ReachabilityAnalyzer)->analyze(
        WardenRuleSet::fromSyntax('course_sections', $syntax),
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
    bindWardenRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWardenTestUser();

    expect(WardenScopedModelSchema::abilityReachability('publish', $user))->toBe(Reachability::ALWAYS)
        ->and(WardenScopedModelSchema::abilityReachability('view', $user))->toBe(Reachability::MAYBE)
        ->and(WardenScopedModelSchema::abilityReachability('archive', $user))->toBe(Reachability::NEVER)
        ->and(WardenScopedModelSchema::abilityReachability('create', $user))->toBe(Reachability::NEVER);
});

it('answers the three boolean questions', function () {
    bindWardenRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWardenTestUser();

    expect(WardenScopedModelSchema::userCouldEverHave('view', $user))->toBeTrue()
        ->and(WardenScopedModelSchema::userCouldEverHave('archive', $user))->toBeFalse()
        ->and(WardenScopedModelSchema::userAlwaysHas('publish', $user))->toBeTrue()
        ->and(WardenScopedModelSchema::userAlwaysHas('view', $user))->toBeFalse()
        ->and(WardenScopedModelSchema::userNeverHas('archive', $user))->toBeTrue()
        ->and(WardenScopedModelSchema::userNeverHas('publish', $user))->toBeFalse();
});

it('combines several abilities under the match mode', function () {
    bindWardenRules("they can publish\nif is_teacher they can view");
    $user = makeWardenTestUser();

    // create has no grant → NEVER
    expect(WardenScopedModelSchema::userCouldEverHave(['publish', 'create'], $user, AbilityMatchMode::ALL))->toBeFalse()
        ->and(WardenScopedModelSchema::userCouldEverHave(['publish', 'create'], $user, AbilityMatchMode::ANY))->toBeTrue()
        ->and(WardenScopedModelSchema::userAlwaysHas(['publish', 'view'], $user, AbilityMatchMode::ALL))->toBeFalse()
        ->and(WardenScopedModelSchema::userAlwaysHas(['publish', 'view'], $user, AbilityMatchMode::ANY))->toBeTrue();
});

it('lists abilities by reachability bucket', function () {
    bindWardenRules("they can publish\nif is_teacher they can view\nthey cannot archive");
    $user = makeWardenTestUser();

    $sort = function (array $abilities): array {
        sort($abilities);

        return $abilities;
    };

    expect($sort(WardenScopedModelSchema::getUserPossibleAbilities($user)))->toBe(['publish', 'view'])
        ->and($sort(WardenScopedModelSchema::getUserGuaranteedAbilities($user)))->toBe(['publish'])
        ->and($sort(WardenScopedModelSchema::getUserImpossibleAbilities($user)))->toBe(['archive', 'create', 'update']);
});

it('honours implicit rules in the analysis', function () {
    useWardenSchemas([WardenImplicitRulesSchema::class]);
    // Resolver grants nothing; the schema's implicit rules grant publish, deny archive.
    bindWardenRules('');
    $user = makeWardenTestUser();

    expect(WardenImplicitRulesSchema::abilityReachability('publish', $user))->toBe(Reachability::ALWAYS)
        ->and(WardenImplicitRulesSchema::abilityReachability('archive', $user))->toBe(Reachability::NEVER);
});

it('requires a user, explicit or authenticated', function () {
    bindWardenRules('they can publish');

    expect(fn () => WardenScopedModelSchema::abilityReachability('publish'))
        ->toThrow(InvalidArgumentException::class, 'requires an authenticated user');
});

/*
|--------------------------------------------------------------------------
| Model trait + facade proxies.
|--------------------------------------------------------------------------
*/

it('proxies through the model', function () {
    bindWardenRules("they can publish\nthey cannot archive");
    $user = makeWardenTestUser();

    expect(WardenScopedModel::userCouldEverHave('publish', $user))->toBeTrue()
        ->and(WardenScopedModel::userNeverHas('archive', $user))->toBeTrue()
        ->and(WardenScopedModel::abilityReachability('publish', $user))->toBe(Reachability::ALWAYS);
});

it('proxies through the facade by schema key', function () {
    bindWardenRules("they can publish\nthey cannot archive");
    $user = makeWardenTestUser();

    expect(Warden::userCouldEverHave('course_sections', 'publish', $user))->toBeTrue()
        ->and(Warden::userAlwaysHas('course_sections', 'publish', $user))->toBeTrue()
        ->and(Warden::abilityReachability('course_sections', 'archive', $user))->toBe(Reachability::NEVER);
});
