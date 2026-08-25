<?php

use Warrant\RuleSyntaxTree\RuleSetGroup;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\RuleSyntaxTree\WarrantSyntaxException;

// -- WarrantRule::fromSyntax schema source ------------------------------------

it('parses a rule with no schema (neither header nor param)', function () {
    $rule = WarrantRule::fromSyntax('if is_self they can view');

    expect($rule->schemaKey)->toBeNull();
    expect($rule->canAbilities)->toBe(['view']);
});

it('takes a rule schema from the param', function () {
    $rule = WarrantRule::fromSyntax('if is_self they can view', 'timesheets');

    expect($rule->schemaKey)->toBe('timesheets');
});

it('takes a rule schema from a `for` header', function () {
    $rule = WarrantRule::fromSyntax('for timesheets if is_self they can view');

    expect($rule->schemaKey)->toBe('timesheets');
});

it('accepts a rule header and param that agree', function () {
    $rule = WarrantRule::fromSyntax('for timesheets if is_self they can view', 'timesheets');

    expect($rule->schemaKey)->toBe('timesheets');
});

it('rejects a rule header and param that disagree', function () {
    expect(fn () => WarrantRule::fromSyntax('for timesheets they can view', 'documents'))
        ->toThrow(InvalidArgumentException::class);
});

it('bans curly braces in a single rule', function () {
    expect(fn () => WarrantRule::fromSyntax('for timesheets { they can view }'))
        ->toThrow(WarrantSyntaxException::class);
});

// -- WarrantRuleSet::fromSyntax schema source ---------------------------------

it('takes a rule set schema from the param (braceless, no header)', function () {
    $set = WarrantRuleSet::fromSyntax('if is_self they can view', 'timesheets');

    expect($set->schemaKey)->toBe('timesheets');
    expect($set->rules)->toHaveCount(1);
});

it('takes a rule set schema from a braceless `for` header', function () {
    $set = WarrantRuleSet::fromSyntax('for timesheets if is_self they can view');

    expect($set->schemaKey)->toBe('timesheets');
});

it('takes a rule set schema from a braced `for` block', function () {
    $set = WarrantRuleSet::fromSyntax(<<<'DSL'
        for timesheets {
            they can view

            if is_self they can edit
        }
        DSL);

    expect($set->schemaKey)->toBe('timesheets');
    expect($set->rules)->toHaveCount(2);
});

it('requires a rule set schema from somewhere', function () {
    expect(fn () => WarrantRuleSet::fromSyntax('they can view'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a rule set header and param that disagree', function () {
    expect(fn () => WarrantRuleSet::fromSyntax('for timesheets { they can view }', 'documents'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a second block through WarrantRuleSet::fromSyntax', function () {
    expect(fn () => WarrantRuleSet::fromSyntax('for a { they can view } for b { they can edit }'))
        ->toThrow(WarrantSyntaxException::class);
});

// -- fromRules rule/set schema agreement --------------------------------------

it('accepts rules whose schema is null or matches the set', function () {
    $null = WarrantRule::fromSyntax('they can view');                 // null schema
    $matching = WarrantRule::fromSyntax('they can edit', 'timesheets');

    $set = WarrantRuleSet::fromRules('timesheets', $null, $matching);

    expect($set->rules)->toHaveCount(2);
});

it('rejects a rule whose schema conflicts with the set', function () {
    $wrong = WarrantRule::fromSyntax('they can view', 'documents');

    expect(fn () => WarrantRuleSet::fromRules('timesheets', $wrong))
        ->toThrow(InvalidArgumentException::class);
});

// -- mergeWith ----------------------------------------------------------------

it('merges two rule sets for the same schema', function () {
    $a = WarrantRuleSet::fromSyntax('for timesheets { they can view }');
    $b = WarrantRuleSet::fromSyntax('for timesheets { if is_self they can edit }');

    $merged = $a->mergeWith($b);

    expect($merged->schemaKey)->toBe('timesheets');
    expect($merged->rules)->toHaveCount(2);
    expect($merged->rules[0]->canAbilities)->toBe(['view']);
    expect($merged->rules[1]->canAbilities)->toBe(['edit']);
});

it('refuses to merge rule sets for different schemas', function () {
    $a = WarrantRuleSet::fromSyntax('for timesheets { they can view }');
    $b = WarrantRuleSet::fromSyntax('for documents { they can view }');

    expect(fn () => $a->mergeWith($b))->toThrow(InvalidArgumentException::class);
});

// -- RuleSetGroup::fromSyntax -------------------------------------------------

it('parses a group of multiple for-blocks', function () {
    $group = RuleSetGroup::fromSyntax(<<<'DSL'
        for some_schema {
            they can view

            if some_condition they can edit
        }

        for some_other_schema {
            they can view
        }
        DSL);

    expect($group)->toHaveCount(2);
    expect($group->schemaKeys())->toBe(['some_schema', 'some_other_schema']);
    expect($group->forSchema('some_schema')->rules)->toHaveCount(2);
    expect($group->forSchema('some_other_schema')->rules)->toHaveCount(1);
    expect($group->forSchema('nope'))->toBeNull();
});

it('merges same-schema blocks in a group, preserving order', function () {
    $group = RuleSetGroup::fromSyntax(<<<'DSL'
        for timesheets { they can view }
        for documents { they can view }
        for timesheets { if is_self they can edit }
        DSL);

    expect($group)->toHaveCount(2);                       // one set per distinct schema
    expect($group->schemaKeys())->toBe(['timesheets', 'documents']);

    $timesheets = $group->forSchema('timesheets');
    expect($timesheets->rules)->toHaveCount(2);
    expect($timesheets->rules[0]->canAbilities)->toBe(['view']);
    expect($timesheets->rules[1]->canAbilities)->toBe(['edit']);
});

it('requires braces on every block in a group', function () {
    expect(fn () => RuleSetGroup::fromSyntax('for timesheets they can view'))
        ->toThrow(WarrantSyntaxException::class);
});

it('requires a `for` header on every block in a group', function () {
    expect(fn () => RuleSetGroup::fromSyntax('they can view'))
        ->toThrow(WarrantSyntaxException::class);
});

it('parses an empty group', function () {
    $group = RuleSetGroup::fromSyntax("   \n  # just a comment\n");

    expect($group)->toHaveCount(0);
    expect($group->schemaKeys())->toBe([]);
});

it('resolves bindings across a group', function () {
    $group = RuleSetGroup::fromSyntax(<<<'DSL'
        for a { if owns(:id) they can view }
        for b { if owns(:id) they can edit }
        DSL, ['id' => 'x-1']);

    expect($group->forSchema('a')->rules[0]->conditions->parameters)->toBe(['x-1']);
    expect($group->forSchema('b')->rules[0]->conditions->parameters)->toBe(['x-1']);
});

it('is iterable over its rule sets', function () {
    $group = RuleSetGroup::fromSyntax('for a { they can view } for b { they can edit }');

    $keys = [];
    foreach ($group as $set) {
        $keys[] = $set->schemaKey;
    }

    expect($keys)->toBe(['a', 'b']);
});

// -- RuleSetGroup::fromRuleSets -----------------------------------------------

it('builds a group from rule sets, merging same-schema ones', function () {
    $group = RuleSetGroup::fromRuleSets(
        WarrantRuleSet::fromRules('timesheets', WarrantRule::fromSyntax('they can view')),
        WarrantRuleSet::fromRules('documents', WarrantRule::fromSyntax('they can view')),
        WarrantRuleSet::fromRules('timesheets', WarrantRule::fromSyntax('they can edit')),
    );

    expect($group->schemaKeys())->toBe(['timesheets', 'documents']);
    expect($group->forSchema('timesheets')->rules)->toHaveCount(2);
});

// -- RuleSetGroup::fromFile ---------------------------------------------------

it('reads a group from a .warrant file', function () {
    $path = tempnam(sys_get_temp_dir(), 'warrant') . '.warrant';
    file_put_contents($path, <<<'DSL'
        for timesheets {
            if is_self they can view, edit
        }

        for documents {
            they can view
        }
        DSL);

    try {
        $group = RuleSetGroup::fromFile($path);

        expect($group->schemaKeys())->toBe(['timesheets', 'documents']);
        expect($group->forSchema('timesheets')->rules[0]->canAbilities)->toBe(['view', 'edit']);
    } finally {
        @unlink($path);
    }
});

it('throws when the .warrant file is missing', function () {
    expect(fn () => RuleSetGroup::fromFile('/no/such/file.warrant'))
        ->toThrow(RuntimeException::class);
});

// -- Round-trip ---------------------------------------------------------------

it('round-trips a group through toSyntax', function () {
    $group = RuleSetGroup::fromSyntax(<<<'DSL'
        for timesheets {
            they can view

            if is_self they can edit
            they cannot delete because 'Locked.'
        }

        for documents {
            they can view
        }
        DSL);

    $reparsed = RuleSetGroup::fromSyntax($group->toSyntax());

    // timesheets: rule 0 = unconditional `they can view`; rule 1 = `if is_self`
    // with `they can edit` + `they cannot delete because 'Locked.'`.
    expect($reparsed->schemaKeys())->toBe($group->schemaKeys());
    expect($reparsed->forSchema('timesheets')->rules)->toHaveCount(2);
    expect($reparsed->forSchema('timesheets')->rules[0]->canAbilities)->toBe(['view']);
    expect($reparsed->forSchema('timesheets')->rules[1]->canAbilities)->toBe(['edit']);
    expect($reparsed->forSchema('timesheets')->rules[1]->cannotAbilities())->toBe(['delete']);
    expect($reparsed->forSchema('timesheets')->rules[1]->messageFor('delete'))->toBe('Locked.');
});

it('round-trips a single rule set with its schema header', function () {
    $set = WarrantRuleSet::fromSyntax('for timesheets if is_self they can view, edit');

    $reparsed = WarrantRuleSet::fromSyntax($set->toSyntax());

    expect($reparsed->schemaKey)->toBe('timesheets');
    expect($reparsed->rules[0]->canAbilities)->toBe(['view', 'edit']);
});

it('round-trips a single rule with its schema header', function () {
    $rule = WarrantRule::fromSyntax('if is_self they can view', 'timesheets');

    $reparsed = WarrantRule::fromSyntax($rule->toSyntax());

    expect($reparsed->schemaKey)->toBe('timesheets');
    expect($reparsed->canAbilities)->toBe(['view']);
});

it('round-trips a group losslessly through bound syntax', function () {
    $group = RuleSetGroup::fromSyntax(<<<'DSL'
        for a { if owns(:id) they can view }
        for b { if in(:list) they can edit }
        DSL, ['id' => 'x-1', 'list' => [1, 2, 3]]);

    $bound = $group->toBoundSyntax();
    $reparsed = RuleSetGroup::fromSyntax($bound->syntax, $bound->bindings);

    expect($reparsed->forSchema('a')->rules[0]->conditions->parameters)->toBe(['x-1']);
    expect($reparsed->forSchema('b')->rules[0]->conditions->parameters)->toBe([[1, 2, 3]]);
});
