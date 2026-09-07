<?php

use Warrant\Builders\WarrantConditionBuilder;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\Facades\Warrant;
use Warrant\Rules\RuleSetGroup;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;

/*
|------------------------------------------------------------------------------
| The rule-authoring facade
|------------------------------------------------------------------------------
|
| Four entry points, each parsing Warrant syntax and each taking exactly the
| parameters its parsing constructor takes. The parameter symmetry is the point:
| a schema named in the string's own `for` header travels with the string, where
| tooling reading the source can see it, and a schema passed as a separate PHP
| argument does not.
|
| Two of them answer with a builder when handed nothing, because a condition and
| a rule are their fluent chain. A rule set and a group are collections, so their
| syntax is required.
|
*/

// -- the builder branch -------------------------------------------------------

it('returns a condition builder from condition() and a rule builder from rule()', function () {
    expect(Warrant::condition())->toBeInstanceOf(WarrantConditionBuilder::class);

    $rule = Warrant::rule();

    // A rule builder specifically: it carries the `they can` half a bare
    // condition builder has no place for.
    expect($rule)->toBeInstanceOf(WarrantRuleBuilder::class);
    expect($rule->if('is_self')->theyCan('view')->toRule())->toBeInstanceOf(WarrantRule::class);
});

// -- the parsing branch, against the constructors it delegates to -------------

it('parses a condition expression exactly as the parser does', function () {
    expect(Warrant::condition('is_owner or is_admin'))
        ->toBeInstanceOf(IBooleanExpressionNode::class)
        ->toEqual(WarrantParser::parseConditionExpression('is_owner or is_admin'));
});

it('parses a rule, a rule set and a group exactly as their constructors do', function () {
    expect(Warrant::rule('if is_self they can view', 'timesheets')->toSyntax())
        ->toBe(WarrantRule::fromSyntax('if is_self they can view', 'timesheets')->toSyntax());

    expect(Warrant::ruleSet('if is_self they can view', 'timesheets')->toSyntax())
        ->toBe(WarrantRuleSet::fromSyntax('if is_self they can view', 'timesheets')->toSyntax());

    $syntax = 'for timesheets { they can view } for documents { they can edit }';

    expect(Warrant::group($syntax)->toSyntax())->toBe(RuleSetGroup::fromSyntax($syntax)->toSyntax());
});

// -- the language-server case: the schema lives in the string ------------------

it('takes the schema from the string\'s own for header, with no PHP argument', function () {
    expect(Warrant::rule('for timesheets if is_self they can view')->schemaKey)->toBe('timesheets');
    expect(Warrant::ruleSet('for timesheets { they can view }')->schemaKey)->toBe('timesheets');
    expect(Warrant::group('for timesheets { they can view }')->schemaKeys())->toBe(['timesheets']);
});

it('accepts a for header on a condition expression and discards it', function () {
    // The header exists so tooling knows which schema's conditions the names
    // belong to; an expression has no schema field to carry it.
    expect(Warrant::condition('for timesheets is_owner or is_admin'))
        ->toEqual(Warrant::condition('is_owner or is_admin'));
});

it('still rejects a header that disagrees with the schema argument', function () {
    expect(fn () => Warrant::rule('for timesheets if is_self they can view', 'documents'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => Warrant::ruleSet('for timesheets { they can view }', 'documents'))
        ->toThrow(InvalidArgumentException::class);
});

// -- bindings ------------------------------------------------------------------

it('resolves bindings identically through every entry point', function () {
    expect(Warrant::condition('is_owner(:id)', ['id' => 'x-1'])->parameters)->toBe(['x-1']);

    expect(Warrant::rule('if is_owner(:id) they can view', 'timesheets', ['id' => 'x-1'])
        ->conditions->parameters)->toBe(['x-1']);

    expect(Warrant::ruleSet('if is_owner(:id) they can view', 'timesheets', ['id' => 'x-1'])
        ->rules[0]->conditions->parameters)->toBe(['x-1']);

    expect(Warrant::group('for timesheets { if is_owner(:id) they can view }', ['id' => 'x-1'])
        ->forSchema('timesheets')->rules[0]->conditions->parameters)->toBe(['x-1']);
});

// -- a builder still needs no terminal where a value is expected ---------------

it('feeds a facade-built rule straight into fromRules, unfinished', function () {
    $set = WarrantRuleSet::fromRules(
        'timesheets',
        Warrant::rule()->if('is_self')->theyCan('view'),
        Warrant::rule('they cannot delete'),
    );

    expect($set->rules)->toHaveCount(2);
    expect($set->rules[0]->canAbilities)->toBe(['view']);
    expect($set->rules[1]->cannotAbilities())->toBe(['delete']);
});
