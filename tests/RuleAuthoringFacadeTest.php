<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\Builders\WarrantConditionBuilder;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;

/*
|------------------------------------------------------------------------------
| The rule-authoring facade
|------------------------------------------------------------------------------
|
| One parse for rule text of every form, answering with the WarrantSyntax tree
| the parser builds, and a fluent builder each for a condition and a rule.
|
*/

beforeEach(function () {
    useWarrantSchemas(['course_sections' => WarrantTestSchema::class]);
});

// -- the builders -------------------------------------------------------------

it('returns a condition builder from condition() and a rule builder from rule()', function () {
    expect(Warrant::condition())->toBeInstanceOf(WarrantConditionBuilder::class);

    $rule = Warrant::rule();

    // A rule builder specifically: it carries the `they can` half a bare
    // condition builder has no place for.
    expect($rule)->toBeInstanceOf(WarrantRuleBuilder::class);
    expect($rule->if('is_self')->theyCan('view')->toRule())->toBeInstanceOf(WarrantRuleNode::class);
});

// -- parse ---------------------------------------------------------------------

it('parses every form exactly as WarrantSyntax::parse does', function (string $source) {
    expect(Warrant::parse($source))->toEqual(WarrantSyntax::parse($source));
})->with([
    'a condition' => ['is_owner or is_admin'],
    'a rule' => ['if is_self they can view'],
    'unscoped rules' => ['if is_self they can view  if is_admin they can edit'],
    'a rule set' => ['for timesheets { they can view }'],
    'several rule sets' => ['for timesheets { they can view } for documents { they can edit }'],
]);

it('resolves bindings through parse', function () {
    expect(Warrant::parse('if is_owner(:id) they can view', ['id' => 'x-1'])->rule()
        ->conditions->parameters)->toBe(['x-1']);
});

it('parses a file through parseFile', function () {
    $path = tempnam(sys_get_temp_dir(), 'warrant');
    file_put_contents($path, 'for timesheets { they can view }');

    try {
        expect(Warrant::parseFile($path)->ruleSet()->schemaKey)->toBe('timesheets');
    } finally {
        unlink($path);
    }
});

// -- the language-server case: the schema lives in the string ------------------

it('takes the schema from the string\'s own for header', function () {
    expect(Warrant::parse('for timesheets if is_self they can view')->ruleSet()->schemaKey)->toBe('timesheets');
    expect(Warrant::parse('for timesheets { they can view }')->schemaKeys())->toBe(['timesheets']);
});

it('rejects a header that disagrees with the schema it is scoped to', function () {
    expect(fn () => Warrant::parse('for timesheets { they can view }')->scopedTo('documents'))
        ->toThrow(InvalidArgumentException::class, 'targets schema [timesheets] in its `for` header but was scoped to [documents]');
});

// -- validate -------------------------------------------------------------------

it('validates each rule set against the schema registered for its key', function () {
    expect(fn () => Warrant::validate(Warrant::parse('if is_teacher they can view')->scopedTo('course_sections')))
        ->not->toThrow(Exception::class);

    expect(fn () => Warrant::validate(Warrant::parse('if no_such_condition they can view')->scopedTo('course_sections')))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects anything but a rule set', function () {
    expect(fn () => Warrant::validate(['for course_sections { they can view }']))
        ->toThrow(InvalidArgumentException::class, 'validate expects RuleSetNode instances, got string.');
});

// -- a builder still needs no terminal where a value is expected ---------------

it('feeds a facade-built rule straight into fromRules, unfinished', function () {
    $set = RuleSetNode::fromRules(
        'timesheets',
        Warrant::rule()->if('is_self')->theyCan('view'),
        Warrant::parse('they cannot delete')->rule(),
    );

    expect($set->entries)->toHaveCount(2);
    expect($set->entries[0]->canAbilities())->toBe(['view']);
    expect($set->entries[1]->cannotAbilities())->toBe(['delete']);
});
