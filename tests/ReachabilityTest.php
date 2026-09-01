<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\AbilityMatchMode;
use Warrant\Facades\Warrant;
use Warrant\Reachability;
use Warrant\RuleSyntaxTree\ReachabilityAnalyzer;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantScopedModelSchema::class]);
});

/*
|--------------------------------------------------------------------------
| The pure analyzer — the decision table, straight over parsed rules.
|--------------------------------------------------------------------------
*/

function analyze(string $syntax, string $ability): Reachability
{
    return (new ReachabilityAnalyzer)->analyze(
        WarrantRuleSet::fromSyntax($syntax, 'course_sections'),
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
