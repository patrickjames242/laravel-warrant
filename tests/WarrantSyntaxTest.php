<?php

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/*
|------------------------------------------------------------------------------
| WarrantSyntax: one parse for every form of rule text
|------------------------------------------------------------------------------
|
| The parser is never told what the source holds. It answers with a
| WarrantSyntax whose children say so, and a caller asks which shape it got.
|
*/

// -- what each form parses to -------------------------------------------------

it('parses an empty source to no children', function (string $source) {
    $syntax = WarrantSyntax::parse($source);

    expect($syntax->isEmpty())->toBeTrue();
    expect($syntax->children)->toBe([]);
})->with([
    'empty' => [''],
    'whitespace' => ["   \n  "],
    'a comment' => ["# just a comment\n"],
]);

it('parses a bare boolean expression to one expression child', function () {
    $syntax = WarrantSyntax::parse('some_condition or some_condition2');

    expect($syntax->isExpression())->toBeTrue();
    expect($syntax->children)->toHaveCount(1);
    expect($syntax->conditionExpression())->toBeInstanceOf(OrNode::class);
});

it('parses a rule with no header to one rule child', function () {
    $syntax = WarrantSyntax::parse('if something they can view');

    expect($syntax->isSingleRule())->toBeTrue();
    expect($syntax->isRuleEntries())->toBeTrue();
    expect($syntax->rule())->toBeInstanceOf(WarrantRuleNode::class);
    expect($syntax->rule()->canAbilities())->toBe(['view']);
});

it('parses several unscoped rules, blocks and includes to a list of entries', function () {
    $syntax = WarrantSyntax::parse(<<<'WARRANT'
        if a they can view
        if b they can edit
        can they delete { if c they can }
        @include approval for publish
        WARRANT);

    expect($syntax->isRuleEntries())->toBeTrue();
    expect($syntax->isSingleRule())->toBeFalse();
    expect(array_map(get_class(...), $syntax->ruleEntries()))->toBe([
        WarrantRuleNode::class,
        WarrantRuleNode::class,
        AbilityBlockNode::class,
        IncludeInvocationNode::class,
    ]);
});

it('parses a for header and a body with no braces to one rule set', function () {
    $syntax = WarrantSyntax::parse(<<<'WARRANT'
        for timesheets

        if something they can view
        WARRANT);

    expect($syntax->isSingleRuleSet())->toBeTrue();
    expect($syntax->ruleSet()->schemaKey)->toBe('timesheets');
    expect($syntax->ruleSet()->entries)->toHaveCount(1);
    expect($syntax->ruleSet()->entries[0]->canAbilities())->toBe(['view']);
});

it('parses one braced block to one rule set', function () {
    $syntax = WarrantSyntax::parse('for timesheets { they can view  if is_self they can edit }');

    expect($syntax->isSingleRuleSet())->toBeTrue();
    expect($syntax->ruleSet()->entries)->toHaveCount(2);
});

it('parses several braced blocks to a list of rule sets, unmerged', function () {
    $syntax = WarrantSyntax::parse(<<<'WARRANT'
        for timesheets { if something they can view }
        for time_offs { if something they can view }
        for timesheets { they can edit }
        WARRANT);

    expect($syntax->isRuleSets())->toBeTrue();
    expect($syntax->isSingleRuleSet())->toBeFalse();
    expect(array_map(fn (RuleSetNode $set) => $set->schemaKey, $syntax->ruleSets()))
        ->toBe(['timesheets', 'time_offs', 'timesheets']);
});

it('parses a for header and an expression to a schema condition', function () {
    $syntax = WarrantSyntax::parse('for timesheets is_approved or is_owned');

    expect($syntax->isSchemaCondition())->toBeTrue();
    expect($syntax->children[0])->toBeInstanceOf(SchemaConditionNode::class);
    expect($syntax->children[0]->schemaKey)->toBe('timesheets');
    expect($syntax->conditionExpression())->toBeInstanceOf(OrNode::class);
});

it('parses braced blocks mixing rule sets and conditions', function () {
    $syntax = WarrantSyntax::parse('for a { they can view } for b { is_owner }');

    expect($syntax->isSchemaScoped())->toBeTrue();
    expect($syntax->isRuleSets())->toBeFalse();
    expect(array_map(get_class(...), $syntax->scoped()))->toBe([RuleSetNode::class, SchemaConditionNode::class]);
    expect($syntax->schemaKeys())->toBe(['a', 'b']);
});

it('parses an empty braced block to an empty rule set', function () {
    expect(WarrantSyntax::parse('for timesheets { }')->ruleSet()->entries)->toBe([]);
});

// -- what the parser rejects --------------------------------------------------

it('rejects several rule sets when the first is not braced', function () {
    expect(fn () => WarrantSyntax::parse(<<<'WARRANT'
        for timesheets

        if something they can view

        for time_offs

        if something they can view
        WARRANT))->toThrow(
        WarrantSyntaxException::class,
        'Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.',
    );
});

it('rejects a bare header after a braced block', function () {
    expect(fn () => WarrantSyntax::parse('for a { they can view } for b they can edit'))
        ->toThrow(WarrantSyntaxException::class, "Expected '{' to open the rule set body.");
});

it('rejects a braced block after a bare one', function () {
    expect(fn () => WarrantSyntax::parse('for a they can view for b { they can edit }'))
        ->toThrow(WarrantSyntaxException::class, 'Multiple rule sets in one source must each be braced');
});

it('rejects anything but another block after a braced one', function () {
    expect(fn () => WarrantSyntax::parse('for a { they can view } they can edit'))
        ->toThrow(WarrantSyntaxException::class, 'every rule set beside a braced one needs a `for` header and braces');
});

it('rejects braces with no for header', function () {
    expect(fn () => WarrantSyntax::parse('{ they can view }'))
        ->toThrow(WarrantSyntaxException::class, 'A `{ ... }` block needs a `for <schema>` header before it.');
});

it('rejects unscoped rules followed by a for block', function () {
    expect(fn () => WarrantSyntax::parse('they can view for a { they can edit }'))
        ->toThrow(WarrantSyntaxException::class, 'Rules without a `for` header cannot be followed by a `for` block');
});

it('rejects an expression mixed with rules', function (string $source) {
    expect(fn () => WarrantSyntax::parse($source))->toThrow(WarrantSyntaxException::class);
})->with([
    'rules then an expression' => ['they can view  is_owner'],
    'an expression then a rule' => ['is_owner if is_admin they can view'],
    'in a block' => ['for a { they can view  is_owner }'],
]);

it('rejects an ability list written without its can they', function () {
    expect(fn () => WarrantSyntax::parse('view, edit { if is_owner they can }'))
        ->toThrow(WarrantSyntaxException::class, 'An ability block header is written `can they <ability>, ... { ... }`.');
});

// -- the accessors refuse the wrong shape -------------------------------------

it('refuses an accessor that does not match what the source holds', function (string $source, Closure $ask, string $message) {
    expect(fn () => $ask(WarrantSyntax::parse($source)))->toThrow(LogicException::class, $message);
})->with([
    'a rule from a rule set' => [
        'for timesheets { they can view }',
        fn (WarrantSyntax $s) => $s->rule(),
        'Expected a single rule, but the source holds a rule set for [timesheets].',
    ],
    'a rule set from a rule' => [
        'they can view',
        fn (WarrantSyntax $s) => $s->ruleSet(),
        'Expected a single `for <schema>` rule set, but the source holds a single rule.',
    ],
    'an expression from rules' => [
        'they can view  they cannot edit',
        fn (WarrantSyntax $s) => $s->conditionExpression(),
        'Expected a condition expression, but the source holds a single rule.',
    ],
    'entries from a rule set' => [
        'for a { they can view }',
        fn (WarrantSyntax $s) => $s->ruleEntries(),
        'Expected unscoped rules, but the source holds a rule set for [a].',
    ],
    'rule sets from an expression' => [
        'is_owner',
        fn (WarrantSyntax $s) => $s->ruleSets(),
        'Expected `for <schema>` rule sets, but the source holds a condition expression.',
    ],
    'rule sets beside a condition' => [
        'for a { they can view } for b { is_owner }',
        fn (WarrantSyntax $s) => $s->ruleSets(),
        'Expected `for <schema>` rule sets, but the source holds 2 `for <schema>` bodies.',
    ],
    'an expression from a rule set' => [
        'for a { they can view }',
        fn (WarrantSyntax $s) => $s->conditionExpression(),
        'Expected a condition expression, but the source holds a rule set for [a].',
    ],
]);

it('answers empty lists for an empty source', function () {
    $syntax = WarrantSyntax::parse('');

    expect($syntax->ruleEntries())->toBe([]);
    expect($syntax->ruleSets())->toBe([]);
    expect($syntax->scoped())->toBe([]);
    expect($syntax->schemaKeys())->toBe([]);
});

// -- scopedTo -----------------------------------------------------------------

it('scopes unscoped rules to a schema', function () {
    $set = WarrantSyntax::parse('if is_self they can view  can they edit { they can }')->scopedTo('timesheets');

    expect($set->schemaKey)->toBe('timesheets');
    expect($set->entries)->toHaveCount(2);
});

it('scopes an empty source to an empty rule set', function () {
    expect(WarrantSyntax::parse('')->scopedTo('timesheets')->entries)->toBe([]);
});

it('scopes a rule set whose header names the same schema', function () {
    $set = WarrantSyntax::parse('for timesheets { they can view }')->scopedTo('timesheets');

    expect($set->schemaKey)->toBe('timesheets');
});

it('rejects scoping a rule set to a schema its header does not name', function () {
    expect(fn () => WarrantSyntax::parse('for timesheets { they can view }')->scopedTo('documents'))
        ->toThrow(InvalidArgumentException::class, 'The rule text targets schema [timesheets] in its `for` header but was scoped to [documents].');
});

it('rejects scoping several rule sets', function () {
    expect(fn () => WarrantSyntax::parse('for a { they can view } for b { they can edit }')->scopedTo('a'))
        ->toThrow(LogicException::class, 'Expected a single `for <schema>` rule set');
});

// -- forSchema ----------------------------------------------------------------

it('folds every rule set for a schema into one, in source order', function () {
    $syntax = WarrantSyntax::parse(<<<'WARRANT'
        for timesheets { they can view }
        for documents { they can view }
        for timesheets { if is_self they can edit }
        WARRANT);

    expect($syntax->schemaKeys())->toBe(['timesheets', 'documents']);

    $timesheets = $syntax->forSchema('timesheets');

    expect($timesheets->entries)->toHaveCount(2);
    expect($timesheets->entries[0]->canAbilities())->toBe(['view']);
    expect($timesheets->entries[1]->canAbilities())->toBe(['edit']);
    expect($syntax->forSchema('nope'))->toBeNull();
});

it('resolves bindings across every block', function () {
    $syntax = WarrantSyntax::parse(<<<'WARRANT'
        for a { if owns(:id) they can view }
        for b { if owns(:id) they can edit }
        WARRANT, ['id' => 'x-1']);

    expect($syntax->forSchema('a')->entries[0]->conditions->parameters)->toBe(['x-1']);
    expect($syntax->forSchema('b')->entries[0]->conditions->parameters)->toBe(['x-1']);
});

// -- parseFile ----------------------------------------------------------------

it('reads rule text from a file', function () {
    $path = tempnam(sys_get_temp_dir(), 'warrant') . '.warrant';
    file_put_contents($path, 'for timesheets { if is_self they can view, edit }  for documents { they can view }');

    try {
        $syntax = WarrantSyntax::parseFile($path);

        expect($syntax->schemaKeys())->toBe(['timesheets', 'documents']);
        expect($syntax->forSchema('timesheets')->entries[0]->canAbilities())->toBe(['view', 'edit']);
    } finally {
        @unlink($path);
    }
});

it('throws when the file is missing', function () {
    expect(fn () => WarrantSyntax::parseFile('/no/such/file.warrant'))
        ->toThrow(InvalidArgumentException::class, 'Unable to read Warrant rule file [/no/such/file.warrant].');
});

// -- the tree keeps ability blocks and includes --------------------------------

it('keeps an ability block as a node over a generic body', function () {
    $set = WarrantSyntax::parse(<<<'WARRANT'
        for docs {
            can they view, edit {
                if is_public they can
                if is_locked they cannot because 'Locked.'
                @include requires_approval
            }
        }
        WARRANT)->ruleSet();

    $block = $set->entries[0];

    expect($block)->toBeInstanceOf(AbilityBlockNode::class);
    expect($block->abilities)->toBe(['view', 'edit']);

    // The body names no abilities, as the source writes it.
    expect($block->entries[0]->canClauses)->toEqual([new CanClauseNode([])]);
    expect($block->entries[1]->cannotClauses)->toEqual([new CannotClauseNode([], 'Locked.')]);
    expect($block->entries[2]->abilities)->toBe([]);
});

it('rejects a block entry that names abilities of its own', function (WarrantRuleNode|IncludeInvocationNode $entry) {
    expect(fn () => new AbilityBlockNode(['view'], [$entry]))->toThrow(
        InvalidArgumentException::class,
        'A clause or @include inside a `can they view` block may not name abilities; the block header already names them.',
    );
})->with([
    'a can clause' => fn () => new WarrantRuleNode(null, [new CanClauseNode(['view'])], []),
    'a cannot clause' => fn () => new WarrantRuleNode(null, [], [new CannotClauseNode(['edit'])]),
    'one of two clauses' => fn () => new WarrantRuleNode(null, [new CanClauseNode([])], [new CannotClauseNode(['edit'])]),
    'an include' => fn () => new IncludeInvocationNode('t', [], ['view']),
]);

it('rejects a generic clause or include outside an ability block', function (WarrantRuleNode|IncludeInvocationNode $entry) {
    expect(fn () => new RuleSetNode('docs', [$entry]))->toThrow(
        InvalidArgumentException::class,
        'Every clause and @include outside an ability block names the abilities it applies to; the rule set for [docs] holds one that names none.',
    );
})->with([
    'a can clause' => fn () => new WarrantRuleNode(null, [new CanClauseNode([])], []),
    'a cannot clause' => fn () => new WarrantRuleNode(null, [new CanClauseNode(['view'])], [new CannotClauseNode([])]),
    'an include' => fn () => new IncludeInvocationNode('t'),
]);

it('refuses to give abilities to a rule or include that names its own', function () {
    expect(fn () => (new WarrantRuleNode(null, [new CanClauseNode(['view'])], []))->withAbilities(['edit']))
        ->toThrow(InvalidArgumentException::class, 'Only a generic rule takes abilities from outside; this rule names its own.');

    expect(fn () => (new IncludeInvocationNode('t', [], ['view']))->withAbilities(['edit']))
        ->toThrow(InvalidArgumentException::class, 'Only a generic @include takes abilities from outside; @include t names its own.');
});

it('rejects a block inside a block, and a block with no abilities', function () {
    expect(fn () => new AbilityBlockNode(['view'], [new AbilityBlockNode(['view'])]))
        ->toThrow(InvalidArgumentException::class, 'ability blocks do not nest');

    expect(fn () => new AbilityBlockNode([]))
        ->toThrow(InvalidArgumentException::class, 'An ability block names at least one ability.');
});

// -- the tree holds one shape --------------------------------------------------

it('rejects a tree mixing shapes', function () {
    $rule = new WarrantRuleNode(null, [new CanClauseNode(['view'])], []);
    $expression = new ConditionNode('is_owner', []);

    expect(fn () => new WarrantSyntax([$rule, new RuleSetNode('a')]))
        ->toThrow(InvalidArgumentException::class, 'A Warrant syntax tree holds one kind of child');

    expect(fn () => new WarrantSyntax([$expression, $expression]))
        ->toThrow(InvalidArgumentException::class, 'A Warrant syntax tree holds at most one condition expression.');

    expect(fn () => new WarrantSyntax(['nope']))
        ->toThrow(InvalidArgumentException::class, 'A Warrant syntax tree cannot hold string.');
});

// -- round-trips ----------------------------------------------------------------

it('round-trips every form through toSyntax', function (string $source) {
    $syntax = WarrantSyntax::parse($source);

    expect(WarrantSyntax::parse($syntax->toSyntax()))->toEqual($syntax);
})->with([
    'a condition' => ['is_owner or not is_admin'],
    'a rule' => ["if is_self they can edit they cannot delete because 'Locked.'"],
    'unscoped entries' => ['if a they can view  can they edit { if b they can }  @include t for publish'],
    'a rule set' => ['for timesheets if is_self they can view'],
    'rule sets' => ['for a { they can view } for b { can they edit { they cannot } }'],
    'a schema condition' => ['for timesheets is_owner and is_approved'],
    'mixed blocks' => ['for a { they can view } for b { is_owner }'],
    'empty' => [''],
]);

it('round-trips every block losslessly through bound syntax', function () {
    $syntax = WarrantSyntax::parse(<<<'WARRANT'
        for a { if owns(:id) they can view }
        for b { if in(:list) they can edit }
        WARRANT, ['id' => 'x-1', 'list' => [1, 2, 3]]);

    $bound = $syntax->toBoundSyntax();

    expect($bound->bindings)->toBe(['x-1', [1, 2, 3]]);
    expect(WarrantSyntax::parse($bound->syntax, $bound->bindings))->toEqual($syntax);
});

it('renders a lone rule set as one braced block', function () {
    expect(WarrantSyntax::parse('for timesheets if is_self they can view, edit')->toSyntax())->toBe(<<<'TXT'
        for timesheets {
            if is_self
            they can view, edit
        }
        TXT);
});
