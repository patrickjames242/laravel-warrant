<?php


use Warrant\Facades\Warrant;
require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\RuleSyntaxTree\RuleSetValidator;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\WarrantAuthorizationException;

function createCourseSectionsTable(): void
{
    Schema::create('course_sections', function ($table) {
        $table->string('id');
    });
}

function seedCourseSections(): void
{
    createCourseSectionsTable();

    DB::table('course_sections')->insert([
        ['id' => 'teacher:teacher-role'],
        ['id' => 'other-section'],
    ]);
}

/**
 * @return array<int, string>
 */
function filteredSectionIds(string|array $abilities, AbilityMatchMode $matchMode = AbilityMatchMode::ALL): array
{
    return Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->filterQuery(warrantTestQuery(), 'course_sections.id', $abilities, $matchMode)
        ->orderBy('id')
        ->pluck('id')
        ->all();
}



// -- filterQuery (behavioral) -------------------------------------------------

it('matches every row for an unconditional grant', function () {
    seedCourseSections();
    bindWarrantRules('they can view');

    expect(filteredSectionIds('view'))->toBe(['other-section', 'teacher:teacher-role']);
});

it('matches every row for a wildcard grant', function () {
    seedCourseSections();
    bindWarrantRules('they can *');

    expect(filteredSectionIds('view'))->toBe(['other-section', 'teacher:teacher-role']);
});

it('matches only rows satisfying a row condition', function () {
    seedCourseSections();
    bindWarrantRules('if is_teacher they can view');

    expect(filteredSectionIds('view'))->toBe(['teacher:teacher-role']);
});

it('ORs a global and a row condition', function () {
    seedCourseSections();
    bindWarrantRules('if is_advisor or is_teacher they can view');

    // is_advisor is false for a teacher role, so only the teacher row matches.
    expect(filteredSectionIds('view'))->toBe(['teacher:teacher-role']);
});

it('denies all rows when no rule grants the ability', function () {
    seedCourseSections();
    bindWarrantRules('if is_teacher they can update');

    expect(filteredSectionIds('view'))->toBe([]);
});

it('applies deny-overrides against an unconditional grant', function () {
    seedCourseSections();
    bindWarrantRules('they can view if is_teacher they cannot view');

    expect(filteredSectionIds('view'))->toBe(['other-section']);
});

it('denies everything under an unconditional cannot', function () {
    seedCourseSections();
    bindWarrantRules('they can view they cannot view');

    expect(filteredSectionIds('view'))->toBe([]);
});

it('requires every ability under ALL match mode', function () {
    seedCourseSections();
    bindWarrantRules('if is_teacher they can view, update');

    expect(filteredSectionIds(['view', 'update'], AbilityMatchMode::ALL))->toBe(['teacher:teacher-role']);
});

it('keeps an unconditional grant winning in ANY match mode', function () {
    seedCourseSections();
    bindWarrantRules('they can view if is_teacher they can update');

    // view is granted unconditionally, so every row passes an ANY check.
    expect(filteredSectionIds(['view', 'update'], AbilityMatchMode::ANY))
        ->toBe(['other-section', 'teacher:teacher-role']);
});

it('leaves the query unchanged when abilities are empty', function () {
    $sql = Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->filterQuery(warrantTestQuery(), 'course_sections.id', [])
        ->toSql();

    expect($sql)->toBe('select * from "course_sections"');
});

it('throws when an ability is not defined on the schema', function () {
    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->filterQuery(warrantTestQuery(), 'course_sections.id', 'destroy'))
        ->toThrow(InvalidArgumentException::class, 'Ability [destroy] is not defined on schema');
});

it('throws when the rule set names an undeclared ability', function () {
    bindWarrantRules('they can teleport', schemaKey: 'course_sections');

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->filterQuery(warrantTestQuery(), 'course_sections.id', 'view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [teleport] is not declared by the schema');
});

it('throws when the rule set names an undeclared condition', function () {
    bindWarrantRules('if is_wizard they can view');

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->filterQuery(warrantTestQuery(), 'course_sections.id', 'view'))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_wizard] is not declared by the schema');
});

// -- implicit rules -----------------------------------------------------------

it('always applies implicit rules, even when the resolver returns nothing', function () {
    seedCourseSections();
    bindWarrantRules(''); // resolver contributes no rules

    $user = makeWarrantTestUser('teacher-role');

    // `publish` comes solely from the schema's implicitRules().
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->can('publish', 'teacher:teacher-role'))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->can('publish', 'other-section'))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->can('view', 'teacher:teacher-role'))->toBeFalse();
});

it('merges implicit rules with resolver rules', function () {
    seedCourseSections();
    bindWarrantRules('if is_teacher they can view');

    $user = makeWarrantTestUser('teacher-role');

    // view from the resolver (teacher row only), publish from implicit rules (every row).
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->can('view', 'teacher:teacher-role'))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->can('view', 'other-section'))->toBeFalse();
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->can('publish', 'other-section'))->toBeTrue();
});

it('accepts a WarrantRuleSet from implicitRules and merges it like a rule list', function () {
    seedCourseSections();
    bindWarrantRules('if is_teacher they can view');

    $user = makeWarrantTestUser('teacher-role');

    // Identical behaviour to the array-returning variant: view from the resolver,
    // publish from the implicit rule set, archive denied by the implicit set.
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRuleSetSchema::class)->can('view', 'teacher:teacher-role'))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRuleSetSchema::class)->can('publish', 'other-section'))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRuleSetSchema::class)->can('archive', 'teacher:teacher-role'))->toBeFalse();
});

it('lets an implicit unconditional cannot override a resolver grant (deny-overrides)', function () {
    seedCourseSections();
    bindWarrantRules('they can archive'); // resolver grants archive unconditionally

    $user = makeWarrantTestUser('teacher-role');

    // implicitRules() has `they cannot archive`, which wins.
    expect(Warrant::guard($user)->forSchema(WarrantImplicitRulesSchema::class)->can('archive', 'teacher:teacher-role'))->toBeFalse();
});

// -- reflection helpers -------------------------------------------------------

it('returns the full reflected list of abilities', function () {
    expect(WarrantTestSchema::abilityNames())->toBe(['create', 'publish', 'archive', 'view', 'update']);
});

it('returns row, global, and all condition keys', function () {
    expect(WarrantTestSchema::rowConditionKeys())->toBe(['is_teacher', 'via_join']);
    expect(WarrantTestSchema::globalConditionKeys())->toBe(['is_advisor']);
    expect(WarrantTestSchema::conditionKeys())->toBe(['is_advisor', 'is_teacher', 'via_join']);
});

it('derives condition keys by snake-casing the method name, no prefix', function () {
    // isTeacher -> is_teacher, isAdvisor -> is_advisor, viaJoin -> via_join
    // (no `condition` prefix).
    expect(WarrantTestSchema::conditionKeys())->toBe(['is_advisor', 'is_teacher', 'via_join']);
});

it('rejects a condition method typed with the wrong context', function () {
    expect(fn () => MistypedConditionSchema::conditionKeys())
        ->toThrow(InvalidArgumentException::class, 'must accept a');
});

it('binds DSL arguments to declared condition parameters positionally', function () {
    $filtered = (new ParameterizedConditionSchema)->applyConditionFilter(
        'is_specific_user',
        makeWarrantTestUser('teacher-role'),
        warrantTestQuery(),
        'course_sections.id',
        ['some-user-id'],
    );

    expect($filtered->getBindings())->toBe(['some-user-id']);
});

it('allows more arguments than declared parameters, leaving the extras on $c->arguments', function () {
    // hasExtraArgs declares one parameter ($first) but is called with two args;
    // $first binds arg[0] and the extra 'b' stays reachable via $c->arguments.
    $result = (new ParameterizedConditionSchema)->applyConditionFilter(
        'has_extra_args',
        makeWarrantTestUser('teacher-role'),
        warrantTestQuery(),
        null,
        ['a', 'b'],
    );

    expect($result)->toBeTrue();
});

it('falls back to a parameter default when the argument is omitted', function () {
    $result = (new ParameterizedConditionSchema)->applyConditionFilter(
        'role_is',
        makeWarrantTestUser('guest'),
        warrantTestQuery(),
    );

    expect($result)->toBeTrue();
});

it('rejects a condition invoked with fewer arguments than its required parameters', function () {
    expect(fn () => (new ParameterizedConditionSchema)->applyConditionFilter(
        'is_specific_user',
        makeWarrantTestUser('teacher-role'),
        warrantTestQuery(),
        'course_sections.id',
        [],
    ))->toThrow(InvalidArgumentException::class, 'requires at least 1 argument');
});

it('rejects a rule that supplies fewer condition arguments than required, during validation', function () {
    $schema = new ParameterizedConditionSchema;
    $ruleSet = WarrantRuleSet::fromSyntax('if is_specific_user they can view', $schema);

    expect(fn () => (new RuleSetValidator($schema, $ruleSet->schemaKey))->validate($ruleSet))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_specific_user] requires at least 1 argument');
});

it('accepts a rule that supplies the required condition arguments, during validation', function () {
    $schema = new ParameterizedConditionSchema;
    $ruleSet = WarrantRuleSet::fromSyntax("if is_specific_user('u-1') they can view", $schema);

    (new RuleSetValidator($schema, $ruleSet->schemaKey))->validate($ruleSet);

    expect(true)->toBeTrue();
});

it('requires a target sql id for row conditions', function () {
    expect(fn () => (new WarrantTestSchema)->applyConditionFilter(
        'is_teacher',
        makeWarrantTestUser('teacher-role'),
        warrantTestQuery()
    ))->toThrow(InvalidArgumentException::class, 'requires a target SQL id');
});

// -- selectUserAbilitiesInQuery (behavioral) --------------------------------------

it('computes per-row abilities as a json column', function () {
    seedCourseSections();
    bindWarrantRules('they can publish if is_teacher they can view');

    $rows = Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema((new WarrantTestSchema))->selectAbilitiesInQuery(warrantTestQuery(), 'course_sections.id')
        ->orderBy('id')
        ->get();

    $abilitiesById = $rows->mapWithKeys(fn ($row) => [$row->id => json_decode($row->abilities, true)])->all();

    expect($abilitiesById['teacher:teacher-role'])->toBe(['publish', 'view']);
    expect($abilitiesById['other-section'])->toBe(['publish']);
});

// -- no-target ability lists --------------------------------------------------

it('returns abilities the user can perform without a target in one query', function () {
    bindWarrantRules('they can publish, view if is_advisor they can create');

    $schema = new WarrantTestSchema;
    $user = makeWarrantTestUser('advisor');

    expect(Warrant::guard($user)->forSchema($schema)->getAbilitiesWithoutTarget())->toBe(['create', 'publish', 'view']);
    expect(Warrant::guard($user)->forSchema($schema)->getAbilitiesWithoutTarget(['create', 'publish'], AbilityMatchMode::ALL))
        ->toBe(['create', 'publish']);
    expect(Warrant::guard($user)->forSchema($schema)->getAbilitiesWithoutTarget(['create', 'publish', 'archive'], AbilityMatchMode::ALL))
        ->toBe([]);
});

it('grants an ability when a global boolean condition evaluates true, denies when false', function () {
    bindWarrantRules('if is_super_user they can view');

    $schema = new WarrantBooleanConditionSchema;

    expect(Warrant::guard(makeWarrantTestUser('super-role'))->forSchema($schema)->getAbilitiesWithoutTarget())->toBe(['view']);
    expect(Warrant::guard(makeWarrantTestUser('other-role'))->forSchema($schema)->getAbilitiesWithoutTarget())->toBe([]);
});

// -- static entry points ------------------------------------------------------

it('checks abilities statically for a target instance, id, or none', function () {
    seedCourseSections();
    bindWarrantRules('they can publish if is_teacher they can view, update');

    $user = makeWarrantTestUser('teacher-role');
    $target = new WarrantTestModel;
    $target->id = 'teacher:teacher-role';
    $target->exists = true;

    expect(Warrant::guard($user)->forSchema(WarrantTestSchema::class)->can('view', $target))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantTestSchema::class)->can(['view', 'update'], 'teacher:teacher-role'))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantTestSchema::class)->can('view', 'other-section'))->toBeFalse();
    expect(Warrant::guard($user)->forSchema(WarrantTestSchema::class)->canAny('publish', null))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantTestSchema::class)->can(['create', 'publish'], null))->toBeFalse();
});

it('forwards static ability helpers through the model trait', function () {
    seedCourseSections();
    bindWarrantRules('they can publish if is_teacher they can view');

    $user = makeWarrantTestUser('teacher-role');

    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->can('view', 'teacher:teacher-role'))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->abilities(null))->toBe(['publish']);
});

it('checks abilities against a model instance through the trait', function () {
    seedCourseSections();
    bindWarrantRules('they can publish if is_teacher they can view');

    $user = makeWarrantTestUser('teacher-role');

    $granted = WarrantScopedModel::query()->find('teacher:teacher-role');
    $denied = WarrantScopedModel::query()->find('other-section');

    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->can('view', $granted))->toBeTrue();
    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->can('view', $denied))->toBeFalse();
    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->can(['view', 'create'], $granted))->toBeFalse();
    expect(Warrant::guard($user)->forSchema(WarrantScopedModelSchema::class)->canAny(['view', 'create'], $granted))->toBeTrue();
});

it('throws when the model returns a schema for a different host model', function () {
    expect(fn () => WarrantMismatchedScopedModel::query()->userHasAbility('view', makeWarrantTestUser('teacher-role'))->toRawSql())
        ->toThrow(LogicException::class, 'must manage model');
});

// -- schema-less facade + tuple targets ---------------------------------------

it('resolves schema-less checks through the registry via model class, instance, and tuple', function () {
    useWarrantSchemas([WarrantTestSchema::class]);
    seedCourseSections();
    bindWarrantRules('they can publish if is_teacher they can view');

    $user = makeWarrantTestUser('teacher-role');

    $target = new WarrantTestModel;
    $target->id = 'teacher:teacher-role';
    $target->exists = true;

    // model instance names the schema and the row
    expect(Warrant::can('view', $target, user: $user))->toBeTrue();

    // [ModelClass, id] and [SchemaClass, id] tuples select a row by key
    expect(Warrant::can('view', [WarrantTestModel::class, 'teacher:teacher-role'], user: $user))->toBeTrue();
    expect(Warrant::can('view', [WarrantTestSchema::class, 'other-section'], user: $user))->toBeFalse();

    // model/schema class-string = no-target check
    expect(Warrant::can('publish', WarrantTestModel::class, user: $user))->toBeTrue();
    expect(Warrant::canAny(['create', 'publish'], WarrantTestSchema::class, user: $user))->toBeTrue();
});

it('authorize and authorizeAny throw on denial through the facade', function () {
    useWarrantSchemas([WarrantTestSchema::class]);
    seedCourseSections();
    bindWarrantRules('if is_teacher they can view');

    $user = makeWarrantTestUser('teacher-role');

    // granted — no throw
    Warrant::authorize('view', [WarrantTestModel::class, 'teacher:teacher-role'], user: $user);

    expect(fn () => Warrant::authorize('view', [WarrantTestModel::class, 'other-section'], user: $user))
        ->toThrow(WarrantAuthorizationException::class);

    expect(fn () => Warrant::authorizeAny(['view'], [WarrantTestModel::class, 'other-section'], user: $user))
        ->toThrow(WarrantAuthorizationException::class);
});
