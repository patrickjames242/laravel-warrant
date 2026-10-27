<?php

use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\WarrantManager;

require_once __DIR__.'/Support/TestSupport.php';

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

/**
 * The rules a guard expands when the global provider returns $rules.
 *
 * @return list<RuleNode>
 */
function rulesProvidedAs(mixed $rules): array
{
    app()->instance(IRuleProvider::class, new class($rules) implements IRuleProvider
    {
        public function __construct(private RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable $rules) {}

        public function rules(RuleProviderContext $context): RuleSetNode|IRuleEntryNode|WarrantSyntax|string|iterable
        {
            return $this->rules;
        }
    });

    app(WarrantManager::class)->flush();

    return Warrant::guard(makeWarrantTestUser())->forSchema(WarrantTestSchema::class)->expandedRuleSet()->ruleNodes();
}

it('reads every form a provider may return into one rule set', function (mixed $rules) {
    expect(rulesProvidedAs($rules))
        ->toEqualSyntax(WarrantSyntax::parse("if is_teacher they can view\nif via_join they cannot archive")->validateWithoutSchemas()->ruleEntries());
})->with([
    'unscoped rule text' => fn () => "if is_teacher they can view\nif via_join they cannot archive",
    'scoped rule text' => fn () => "for course_sections {\n if is_teacher they can view\n}\nfor course_sections {\n if via_join they cannot archive\n}",
    'warrant syntax' => fn () => WarrantSyntax::parse("if is_teacher they can view\nif via_join they cannot archive"),
    'a rule set' => fn () => WarrantSyntax::parse("if is_teacher they can view\nif via_join they cannot archive")->validateWithoutSchemas()->scopedTo('course_sections'),
    'an array of rules' => fn () => [
        WarrantSyntax::parse('if is_teacher they can view')->validateWithoutSchemas()->rule(),
        WarrantSyntax::parse('if via_join they cannot archive')->validateWithoutSchemas()->rule(),
    ],
    'a collection of rule sets' => fn () => collect([
        WarrantSyntax::parse('if is_teacher they can view')->validateWithoutSchemas()->scopedTo('course_sections'),
        WarrantSyntax::parse('if via_join they cannot archive')->validateWithoutSchemas()->scopedTo('course_sections'),
    ]),
    'a mixed, nested array' => fn () => [
        'if is_teacher they can view',
        [WarrantSyntax::parse('if via_join they cannot archive')->validateWithoutSchemas()->rule()],
    ],
]);

it('reads any rule entry, alone or in an iterable', function () {
    $entries = WarrantSyntax::parse(<<<'WARRANT'
        can they view, update {
            if is_teacher they can
        }
        if via_join they cannot archive
        WARRANT)->validateWithoutSchemas()->ruleEntries();

    $expanded = WarrantSyntax::parse("if is_teacher they can view, update\nif via_join they cannot archive")->validateWithoutSchemas()->ruleEntries();

    expect(rulesProvidedAs($entries[0]))->toEqualSyntax([$expanded[0]])
        ->and(rulesProvidedAs($entries[1]))->toEqualSyntax([$expanded[1]])
        ->and(rulesProvidedAs($entries))->toEqualSyntax($expanded)
        ->and(rulesProvidedAs(collect($entries)))->toEqualSyntax($expanded);
});

it('reads nothing as an empty rule set', function (mixed $rules) {
    expect(rulesProvidedAs($rules))->toBe([]);
})->with([
    'an empty string' => '',
    'an empty array' => fn () => [],
    'an empty collection' => fn () => collect(),
]);

it('leaves out a rule set for another schema, wherever it sits', function (mixed $rules, int $kept) {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class, 'posts' => WarrantJoinConditionSchema::class]);

    expect(rulesProvidedAs($rules instanceof Closure ? $rules() : $rules))->toHaveCount($kept);
})->with([
    'a rule set' => [fn () => WarrantSyntax::parse('they can view')->validateWithoutSchemas()->scopedTo('posts'), 0],
    'rule text' => ['for posts they can view', 0],
    'inside an array' => [fn () => ['they can view', WarrantSyntax::parse('they can view')->validateWithoutSchemas()->scopedTo('posts')], 1],
]);

it('rejects an iterable element that holds no rules', function () {
    expect(fn () => rulesProvidedAs(['they can view', 42]))->toThrow(
        InvalidArgumentException::class,
        'The rule provider returned int; expected a rule set, a rule entry, a rule builder, rule text, or an iterable of them.',
    );
});
