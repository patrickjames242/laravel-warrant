<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\Builders\WarrantConditionBuilder;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
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
    expect(Warrant::parse($source)->children)->toEqualSyntax(WarrantSyntax::parse($source)->children)
        ->and(Warrant::parse($source)->source->bound->syntax)->toBe($source);
})->with([
    'a condition' => ['is_owner or is_admin'],
    'a rule' => ['if is_self they can view'],
    'unscoped rules' => ['if is_self they can view  if is_admin they can edit'],
    'a rule set' => ['for timesheets { they can view }'],
    'several rule sets' => ['for timesheets { they can view } for documents { they can edit }'],
]);

it('resolves bindings through parse', function () {
    expect(argumentValues(Warrant::parse(new BoundSyntax('if is_owner(:id) they can view', ['id' => 'x-1']))->validateWithoutSchemas()->rule()
        ->conditions->parameters))->toBe(['x-1']);
});

it('parses a file through parseFile', function () {
    $path = tempnam(sys_get_temp_dir(), 'warrant');
    file_put_contents($path, 'for timesheets { they can view }');

    try {
        expect(Warrant::parseFile($path)->validateWithoutSchemas()->ruleSet()->schemaKey?->name)->toBe('timesheets');
    } finally {
        unlink($path);
    }
});

it('parses a BoundSyntax with the bindings it holds', function () {
    expect(argumentValues(Warrant::parse(new BoundSyntax('if is_owner(:id) they can view', ['id' => 'x-1']))->validateWithoutSchemas()->rule()->conditions->parameters))
        ->toBe(['x-1']);
});

// -- the global helpers ---------------------------------------------------------

it('parses through warrant() exactly as Warrant::parse does', function () {
    expect(warrant('if is_owner(:id) they can view', ['id' => 'x-1'])->children)
        ->toEqualSyntax(Warrant::parse(new BoundSyntax('if is_owner(:id) they can view', ['id' => 'x-1']))->children);
});

it('parses a file through warrant_file(), with the extension left off', function () {
    $base = sys_get_temp_dir().'/'.uniqid('warrant_', true);
    file_put_contents($base.'.warrant', 'for timesheets { if is_owner(:id) they can view }');

    try {
        expect(warrant_file($base, ['id' => 'x-1'])->children)
            ->toEqualSyntax(Warrant::parseFile($base.'.warrant', ['id' => 'x-1'])->children);
    } finally {
        unlink($base.'.warrant');
    }
});

// -- the language-server case: the schema lives in the string ------------------

it('takes the schema from the string\'s own for header', function () {
    expect(Warrant::parse('for timesheets if is_self they can view')->validateWithoutSchemas()->ruleSet()->schemaKey?->name)->toBe('timesheets');
    expect(Warrant::parse('for timesheets { they can view }')->schemaKeys())->toBe(['timesheets']);
});

it('rejects a header that disagrees with the schema it is scoped to', function () {
    expect(fn () => Warrant::parse('for timesheets { they can view }')->validateWithoutSchemas()->scopedTo('documents'))
        ->toThrow(InvalidArgumentException::class, 'targets schema [timesheets] in its `for` header but was scoped to [documents]');
});
