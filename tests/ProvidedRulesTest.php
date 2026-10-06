<?php

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\WarrantManager;

require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

/**
 * The rule set a guard resolves when the global provider returns $rules.
 */
function ruleSetProvidedAs(mixed $rules): RuleSetNode
{
    app()->instance(RuleProvider::class, new class($rules) implements RuleProvider
    {
        public function __construct(private RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable $rules) {}

        public function rules(RuleProviderContext $context): RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable
        {
            return $this->rules;
        }
    });

    app(WarrantManager::class)->flush();

    return Warrant::guard(makeWarrantTestUser())->forSchema(WarrantTestSchema::class)->resolvedRuleSet();
}

it('reads every form a provider may return into one rule set', function (mixed $rules) {
    expect(ruleSetProvidedAs($rules))
        ->toEqual(WarrantSyntax::parse("if is_teacher they can view\nif via_join they cannot archive")->scopedTo('course_sections'));
})->with([
    'headless rule text' => fn () => "if is_teacher they can view\nif via_join they cannot archive",
    'scoped rule text' => fn () => "for course_sections {\n if is_teacher they can view\n}\nfor course_sections {\n if via_join they cannot archive\n}",
    'warrant syntax' => fn () => WarrantSyntax::parse("if is_teacher they can view\nif via_join they cannot archive"),
    'a rule set' => fn () => WarrantSyntax::parse("if is_teacher they can view\nif via_join they cannot archive")->scopedTo('course_sections'),
    'an array of rules' => fn () => [
        WarrantSyntax::parse('if is_teacher they can view')->rule(),
        WarrantSyntax::parse('if via_join they cannot archive')->rule(),
    ],
    'a collection of rule sets' => fn () => collect([
        WarrantSyntax::parse('if is_teacher they can view')->scopedTo('course_sections'),
        WarrantSyntax::parse('if via_join they cannot archive')->scopedTo('course_sections'),
    ]),
    'a mixed, nested array' => fn () => [
        'if is_teacher they can view',
        [WarrantSyntax::parse('if via_join they cannot archive')->rule()],
    ],
]);

it('reads any rule entry, alone or in an iterable', function () {
    $entries = WarrantSyntax::parse(<<<'WARRANT'
        can they view, update {
            if is_teacher they can
        }
        if via_join they cannot archive
        WARRANT)->ruleEntries();

    expect(ruleSetProvidedAs($entries[0]))->toEqual(new RuleSetNode('course_sections', [$entries[0]]))
        ->and(ruleSetProvidedAs($entries[1]))->toEqual(new RuleSetNode('course_sections', [$entries[1]]))
        ->and(ruleSetProvidedAs($entries))->toEqual(new RuleSetNode('course_sections', $entries))
        ->and(ruleSetProvidedAs(collect($entries)))->toEqual(new RuleSetNode('course_sections', $entries));
});

it('reads nothing as an empty rule set', function (mixed $rules) {
    expect(ruleSetProvidedAs($rules))->toEqual(new RuleSetNode('course_sections', []));
})->with([
    'an empty string' => '',
    'an empty array' => fn () => [],
    'an empty collection' => fn () => collect(),
]);

it('rejects a rule set for another schema, wherever it sits', function (mixed $rules) {
    expect(fn () => ruleSetProvidedAs($rules))->toThrow(
        InvalidArgumentException::class,
        'The rule provider was asked for schema [course_sections] but returned a rule set targeting [posts].',
    );
})->with([
    'a rule set' => fn () => WarrantSyntax::parse('they can view')->scopedTo('posts'),
    'rule text' => 'for posts they can view',
    'inside an array' => fn () => ['they can view', WarrantSyntax::parse('they can view')->scopedTo('posts')],
]);

it('rejects an iterable element that holds no rules', function () {
    expect(fn () => ruleSetProvidedAs(['they can view', 42]))->toThrow(
        InvalidArgumentException::class,
        'The rule provider returned int; expected a rule set, a rule entry, rule text, or an iterable of them.',
    );
});
