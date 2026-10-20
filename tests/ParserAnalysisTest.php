<?php

use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode;
use Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\DiagnosticSeverity;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;

require_once __DIR__.'/Support/TestSupport.php';

/**
 * The nodes of $syntax written over $offset.
 *
 * @return list<array{0: class-string, 1: int, 2: int}>
 */
function spansAt(WarrantSyntax $syntax, int $offset): array
{
    return array_values(array_filter(
        spansOf($syntax),
        static fn (array $span): bool => $span[1] !== null && $span[1] <= $offset && $offset <= $span[2],
    ));
}

/**
 * Every node of $syntax with where it was written, parent before child.
 *
 * @return list<array{0: class-string, 1: int, 2: int}>
 */
function spansOf(WarrantSyntax $syntax): array
{
    $spans = [];
    $walk = function (INode $node) use (&$walk, &$spans): void {
        $spans[] = [$node::class, $node->location->span?->offset, $node->location->span?->endOffset];

        foreach ($node->children() as $child) {
            $walk($child);
        }
    };

    foreach ($syntax->children as $child) {
        $walk($child);
    }

    return $spans;
}

/**
 * The diagnostics of an analysis, as [message, the text each covers].
 *
 * @return list<array{0: string, 1: string}>
 */
function analysisDiagnostics(string $source): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): array => [
            $diagnostic->message,
            substr($source, $diagnostic->offset, $diagnostic->endOffset - $diagnostic->offset),
        ],
        analyzedDiagnostics($source, null),
    );
}

it('reads sound text to the same tree and positions as a strict read, with no diagnostics', function (string $source) {
    $analysed = WarrantParser::analyze($source);
    $strict = WarrantParser::parse($source);

    expect($analysed->syntax->children)->toEqualSyntax($strict->children)
        ->and($analysed->diagnostics)->toBe([])
        ->and(spansOf($analysed->syntax))->toBe(spansOf($strict));
})->with([
    'a rule set' => "for docs {\n  if is_owner(@context org) they can view # mine\n  can they share { they can }\n}",
    'unscoped rules' => 'they can view if is_owner they can edit @include shared for view',
    'a bare expression' => 'is_owner or (is_admin and check(is_open for folders(@column folder_id)))',
    'nothing' => '',
]);

it('keeps each placeholder as a binding node, since there are no values to bind', function () {
    $syntax = WarrantParser::analyze('if in_period(:year, @sql :query) they cannot view because :why')->syntax;
    $rule = $syntax->children[0];

    expect($rule->conditions)->toEqualSyntax(new ConditionNode(nameNode('in_period'), [argumentNode(new BindingNode('year', null, SyntaxLocation::noProducer())), argumentNode(new SqlRef(argumentNode(new BindingNode('query', null, SyntaxLocation::noProducer())), SyntaxLocation::noProducer()))], SyntaxLocation::noProducer()))
        ->and($rule->cannotClauses[0]->message)->toEqualSyntax(new BindingNode('why', null, SyntaxLocation::noProducer()))
        ->and(WarrantParser::analyze('if in_period(?, ?) they can view')->syntax->children[0]->conditions)
        ->toEqualSyntax(new ConditionNode(nameNode('in_period'), [new BindingNode(null, 0, SyntaxLocation::noProducer()), new BindingNode(null, 1, SyntaxLocation::noProducer())], SyntaxLocation::noProducer()));
});

it('still reports named and positional placeholders mixed, and keeps the tree', function () {
    $source = 'if a(:x, ?) they can view';

    expect(analysisDiagnostics($source))->toBe([
        ['Cannot mix named and positional bindings.', '?'],
    ])->and(WarrantParser::analyze($source)->syntax->children)->toHaveCount(1);
});

it('reports a lexical error and reads on past it', function () {
    $source = 'if a("x\q") they can view';

    expect(analysisDiagnostics($source))->toBe([
        ['Invalid escape sequence "\q"; only \\\', \", and \\\\ are allowed.', '\q'],
    ])->and(WarrantParser::analyze($source)->syntax->children)->toHaveCount(1);
});

it('reports the error a strict read throws, over the token it is at, and keeps what was read before it', function () {
    $source = 'if a they can view )';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([['Unexpected token; expected end of input.', ')']])
        ->and($analysed->syntax->children)->toEqualSyntax(WarrantParser::parse('if a they can view')->children)
        ->and(spansOf($analysed->syntax))->not->toBe([]);
});

it('reports text the lexer could not read once, and reads the text around it as though it were not there', function (string $source, string $unreadable, string $without) {
    $analysed = WarrantParser::analyze($source);

    expect(array_map(static fn (array $diagnostic): string => $diagnostic[1], analysisDiagnostics($source)))->toBe([$unreadable])
        ->and($analysed->syntax->children)->toEqualSyntax(WarrantParser::parse($without)->children);
})->with([
    'after a rule' => ['if a they can view ~', '~', 'if a they can view'],
    'inside a condition' => ['if a ~ they can view', '~', 'if a they can view'],
    'before an ability' => ['if a they can ~ view', '~', 'if a they can view'],
    'between entries in a block' => ['for docs { if a they can view ~ if b they can edit }', '~', 'for docs { if a they can view if b they can edit }'],
    'an unknown @ word as an argument' => ['if a(@) they can view', '@', 'if a() they can view'],
]);

it('does not report again a mistake met right after text the lexer could not read', function (string $source) {
    expect(analysisDiagnostics($source))->toBe([["Unexpected character '~'.", '~']]);
})->with([
    'in place of a condition' => 'if ~ they can view',
    'in place of an argument' => 'if a(1, ~) they can view',
    'in place of a denial message' => 'if a they can view because ~',
    'in place of an operator' => 'a ~ b',
    'with a comment after it' => "if ~ # unreadable\nthey can view",
]);

it('still reports a mistake that is not right after text the lexer could not read', function () {
    expect(analysisDiagnostics('if a ~ they view'))->toBe([
        ["Unexpected character '~'.", '~'],
        ["Expected 'can' or 'cannot' after 'they'.", 'view'],
    ]);
});

it('gives the error a strict read throws as its first diagnostic', function (string $source) {
    try {
        WarrantParser::parse($source);
        $strict = null;
    } catch (WarrantSyntaxException $error) {
        $strict = [$error->reason, $error->offset];
    }

    $first = WarrantParser::analyze($source)->diagnostics[0] ?? null;

    expect($strict)->not->toBeNull()
        ->and([$first?->message, $first?->offset])->toBe($strict);
})->with([
    'a stray token after rules' => 'if a they can view ) if b they can edit',
    'a clause with no can' => "if a they view\nif b they can edit",
    'a reserved word as a condition' => 'if ( they can view',
    'a can with no parenthesis' => 'if can view they can edit',
    'a stray token in a block' => 'for docs { if a they can view ) if b they can edit }',
    'an unclosed block' => 'for docs { if a they can view',
    'a broken block before a sound one' => 'for docs { a and } for files { they can view }',
    'an unclosed ability block' => 'can they view { if a they can',
    'a for block after headerless rules' => 'they can view for docs { }',
    'an include with no template' => 'if a they can view @include if b they can edit',
    'an ability list with no can they' => 'for docs { view, edit { they can } }',
    'a block with no header' => '{ they can view }',
    'a block with no header after a braced one' => 'for docs { they can view } { they can edit }',
    'a block after an unbraced one' => 'for docs they can view for files { they can edit }',
]);

it('reports each mistake in a text, and keeps every rule, repaired where it can be', function () {
    $source = "if a they view\nif b they can edit\nif ( they can x\nif d they can delete";
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([
        ["Expected 'can' or 'cannot' after 'they'.", 'view'],
        ["Reserved word 'they' cannot be used as a name; expected a condition, 'can(', 'check(', or '('.", 'they'],
    ])->and($analysed->syntax->children)->toEqualSyntax([
        new WarrantRuleNode(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), [], [], SyntaxLocation::noProducer()),
        ...WarrantParser::parse('if b they can edit')->children,
        new WarrantRuleNode(new MissingExpressionNode(SyntaxLocation::noProducer()), [new CanClauseNode([nameNode('x')], null, SyntaxLocation::noProducer())], [], SyntaxLocation::noProducer()),
        ...WarrantParser::parse('if d they can delete')->children,
    ]);
});

it('records where the rules it keeps were written, and nothing of the text it skips', function () {
    $source = "if a they can view ) b c\nif d they can edit";
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([['Unexpected token; expected end of input.', ')']])
        ->and($analysed->syntax->children)->toEqualSyntax(WarrantParser::parse('if a they can view if d they can edit')->children)
        ->and(spansAt($analysed->syntax, strpos($source, 'b ')))->toBe([])
        ->and(spansAt($analysed->syntax, strpos($source, 'd ')))->not->toBe([]);
});

it('reports a stray token in a block with the error a strict read throws there, and reads the entries after it', function () {
    $source = 'for docs { if a they can view ) if b they can edit }';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected '}' to close the rule set body.", ')']])
        ->and($analysed->syntax->children)
        ->toEqualSyntax(WarrantParser::parse('for docs { if a they can view if b they can edit }')->children);
});

it('keeps a block whose closing brace is missing', function () {
    $source = 'for docs { if a they can view';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected '}' to close the rule set body.", '']])
        ->and($analysed->syntax->children)->toEqualSyntax(WarrantParser::parse('for docs { if a they can view }')->children);
});

it('keeps a mistake inside an ability block within the block', function () {
    $source = 'can they view { if a they } if b they can edit';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected 'can' or 'cannot' after 'they'.", '}']])
        ->and($analysed->syntax->children)->toEqualSyntax([
            new AbilityBlockNode([nameNode('view')], [new WarrantRuleNode(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), [], [], SyntaxLocation::noProducer())], SyntaxLocation::noProducer()),
            ...WarrantParser::parse('if b they can edit')->children,
        ]);
});

it('puts a placeholder where a condition is missing, and keeps what is around it', function (string $source, array $missing, IBooleanExpressionNode $conditions) {
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([$missing])
        ->and($analysed->syntax->children)->toEqualSyntax([new WarrantRuleNode($conditions, [new CanClauseNode([nameNode('view')], null, SyntaxLocation::noProducer())], [], SyntaxLocation::noProducer())]);
})->with(function () {
    $beforeThey = ["Reserved word 'they' cannot be used as a name; expected a condition, 'can(', 'check(', or '('.", 'they'];

    return [
        'after if' => ['if they can view', $beforeThey, new MissingExpressionNode(SyntaxLocation::noProducer())],
        'after and' => ['if a and they can view', $beforeThey, new AndNode(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), new MissingExpressionNode(SyntaxLocation::noProducer()), SyntaxLocation::noProducer())],
        'after not' => ['if not they can view', $beforeThey, new NotNode(new MissingExpressionNode(SyntaxLocation::noProducer()), SyntaxLocation::noProducer())],
        'inside a group' => ['if () they can view', ["Expected a condition, 'can(', 'check(', or '('.", ')'], new MissingExpressionNode(SyntaxLocation::noProducer())],
    ];
});

it('keeps a body whose expression is missing an operand', function () {
    $source = 'for docs { a and } for files { they can view }';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected a condition, 'can(', 'check(', or '('.", '}']])
        ->and($analysed->syntax->children)->toEqualSyntax([
            new SchemaConditionNode(nameNode('docs'), new AndNode(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), new MissingExpressionNode(SyntaxLocation::noProducer()), SyntaxLocation::noProducer()), SyntaxLocation::noProducer()),
            ...WarrantParser::parse('for files { they can view }')->children,
        ]);
});

it('reads on as though a missing bracket or keyword were there', function (string $source, string $missing, string $at, string $repaired) {
    expect(analysisDiagnostics($source))->toBe([[$missing, $at]])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqualSyntax(WarrantParser::parse($repaired)->children);
})->with([
    'a closing parenthesis' => ['if (a or b they can view', "Expected ')' to close the group.", 'they', 'if (a or b) they can view'],
    'the end of an argument list' => ['if a(1 they can view', "Expected ')' to close the condition arguments.", 'they', 'if a(1) they can view'],
    'the parenthesis after can' => ['if can view they can edit', "Expected '(' after 'can'.", 'view', 'if can(view) they can edit'],
    'the end of a check' => ['if check(a for docs they can view', "Expected ')' to close 'check(...)'.", 'they', 'if check(a for docs) they can view'],
    'the brace after a block header' => ['can they view if a they can }', "Expected '{' to open the ability block body.", 'if', 'can they view { if a they can }'],
]);

it('keeps a rule whose clauses are missing', function (string $source, string $mistake) {
    expect(array_column(analysisDiagnostics($source), 0))->toBe([$mistake])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqualSyntax([new WarrantRuleNode(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), [], [], SyntaxLocation::noProducer())]);
})->with([
    'no they' => ['if a', "Expected at least one 'they can ...' or 'they cannot ...' clause."],
    'a they with no can' => ['if a they', "Expected 'can' or 'cannot' after 'they'."],
]);

it('keeps the clauses around a they that heads none', function () {
    expect(WarrantParser::analyze('if a they can view they')->syntax->children)
        ->toEqualSyntax(WarrantParser::parse('if a they can view')->children);
});

it('notes no other mistake until three tokens are read past the last one', function () {
    // The missing `(` is repaired, which leaves `they` where `)` should be.
    expect(analysisDiagnostics('if can view they can edit'))->toBe([["Expected '(' after 'can'.", 'view']])
        ->and(analysisDiagnostics('if a they view if b they can edit if c they'))->toBe([
            ["Expected 'can' or 'cannot' after 'they'.", 'view'],
            ["Expected 'can' or 'cannot' after 'they'.", ''],
        ]);
});

it('never throws, however the text is broken', function () {
    $words = ['if', 'they', 'can', 'cannot', 'because', 'check', 'and', 'or', 'not', '!', 'for', 'with', 'as',
        '(', ')', '{', '}', ',', '*', '=', '.', ':name', '?', '@context', '@column', '@sql', '@include',
        'a', 'b', 'docs', 'view', "'msg'", '1', 'true', 'null', '~', '"open', '#note'];

    mt_srand(20261013);

    for ($i = 0; $i < 2000; $i++) {
        $source = implode(' ', array_map(static fn () => $words[mt_rand(0, count($words) - 1)], range(1, mt_rand(1, 14))));

        foreach (WarrantParser::analyze($source)->diagnostics as $diagnostic) {
            expect($diagnostic->offset)->toBeGreaterThanOrEqual(0)
                ->and($diagnostic->endOffset)->toBeLessThanOrEqual(strlen($source));
        }
    }
});

it('reads a missing name as none, and keeps what is around it', function (string $source, array $missing, array $repaired) {
    expect(analysisDiagnostics($source))->toBe([$missing])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqualSyntax($repaired);
})->with(function () {
    $canEdit = [new CanClauseNode([nameNode('edit')], null, SyntaxLocation::noProducer())];
    $canView = [new CanClauseNode([nameNode('view')], null, SyntaxLocation::noProducer())];

    return [
        'the schema of a for header' => [
            'for if a they can view',
            ["Reserved word 'if' cannot be used as a name; expected a schema name after `for`.", 'if'],
            [new RuleSetNode(nameNode(INode::MISSING_NAME), [new WarrantRuleNode(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), $canView, [], SyntaxLocation::noProducer())], SyntaxLocation::noProducer())],
        ],
        'the ability of a can' => [
            'if can( for docs) they can edit',
            ["Reserved word 'for' cannot be used as a name; expected an ability name.", 'for'],
            [new WarrantRuleNode(new CrossSchemaCanNode(nameNode('docs'), nameNode(INode::MISSING_NAME), false, [], [], null, SyntaxLocation::noProducer()), $canEdit, [], SyntaxLocation::noProducer())],
        ],
        'the schema of a check' => [
            'if check(a for ) they can view',
            ['Expected a schema name.', ')'],
            [new WarrantRuleNode(new CrossSchemaConditionNode(nameNode(INode::MISSING_NAME), new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), false, [], [], null, SyntaxLocation::noProducer()), $canView, [], SyntaxLocation::noProducer())],
        ],
        'the template of an include' => [
            '@include for view',
            ["Reserved word 'for' cannot be used as a name; expected a rule template name.", 'for'],
            [new IncludeInvocationNode(nameNode(INode::MISSING_NAME), [], [nameNode('view')], SyntaxLocation::noProducer())],
        ],
        'an ability after a comma' => [
            'they can view,',
            ['Expected an ability name.', ''],
            [new WarrantRuleNode(null, $canView, [], SyntaxLocation::noProducer())],
        ],
    ];
});

it('reads an as with no name after it as no alias', function () {
    $source = 'if can(view for docs as ) they can edit';

    expect(analysisDiagnostics($source))->toBe([["Expected an alias name after 'as'.", ')']])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqualSyntax(WarrantParser::parse('if can(view for docs) they can edit')->children);
});

it('puts a placeholder where an argument is missing, so the arguments keep their count', function (string $source, array $missing, array $arguments) {
    expect(analysisDiagnostics($source))->toBe([$missing])
        ->and(WarrantParser::analyze($source)->syntax->children)
        ->toEqualSyntax([new WarrantRuleNode(new ConditionNode(nameNode('a'), array_map(argumentNode(...), $arguments), SyntaxLocation::noProducer()), [new CanClauseNode([nameNode('view')], null, SyntaxLocation::noProducer())], [], SyntaxLocation::noProducer())]);
})->with(function () {
    $expectedArgument = 'Expected an argument: a literal, a binding (:name or ?), @context <key>, '
        .'@column <column> or @column <name>.<column>, or @sql "<sql>".';

    return [
        'after a comma' => ['if a(1, ) they can view', [$expectedArgument, ')'], [1, new MissingArgumentNode(SyntaxLocation::noProducer())]],
        'in an unclosed list' => ['if a( they can view', [$expectedArgument, 'they'], [new MissingArgumentNode(SyntaxLocation::noProducer())]],
        'a context reference with no key' => ['if a(@context) they can view', ["Expected a context key after '@context'.", ')'], [new MissingArgumentNode(SyntaxLocation::noProducer())]],
        'a column reference with no column' => ['if a(@column x.) they can view', ["Expected a column name after '@column x.'.", ')'], [new MissingArgumentNode(SyntaxLocation::noProducer())]],
        'an sql reference with a number for a body' => ['if a(@sql 5) they can view', ["Expected a quoted SQL string or a binding (:name or ?) after '@sql'.", '5'], [new MissingArgumentNode(SyntaxLocation::noProducer())]],
    ];
});

it('reads a with map past a missing key or value, and keeps both of a key written twice', function (string $source, array $missing, array $contextMap) {
    expect(analysisDiagnostics($source))->toBe([$missing])
        ->and(WarrantParser::analyze($source)->syntax->children)
        ->toEqualSyntax([new WarrantRuleNode(new CrossSchemaCanNode(nameNode('docs'), nameNode('view'), false, [], $contextMap, null, SyntaxLocation::noProducer()), [new CanClauseNode([nameNode('edit')], null, SyntaxLocation::noProducer())], [], SyntaxLocation::noProducer())]);
})->with([
    'a missing value' => [
        'if can(view for docs with org = ) they can edit',
        ['Expected an argument: a literal, a binding (:name or ?), @context <key>, @column <column> or @column <name>.<column>, or @sql "<sql>".', ')'],
        withEntries(['org' => new MissingArgumentNode(SyntaxLocation::noProducer())]),
    ],
    'a missing key' => ['if can(view for docs with = 1, org = 2) they can edit', ['Expected a context key name.', '='], withEntries([INode::MISSING_NAME => 1, 'org' => 2])],
    'a key written twice' => [
        'if can(view for docs with org = 1, org = 2) they can edit',
        ["Duplicate key 'org' in the 'with' map.", 'org'],
        [...withEntries(['org' => 1]), ...withEntries(['org' => 2])],
    ],
]);

it('reads a because with no message as giving none', function () {
    $source = 'if a they cannot view because';

    expect(analysisDiagnostics($source))->toBe([["Expected a denial message after 'because': a quoted string or a binding (:name or ?).", '']])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqualSyntax(WarrantParser::parse('if a they cannot view')->children);
});

it('reads a block missing its for header as one whose header names no schema', function (string $source, array $mistake, array $blocks) {
    expect(analysisDiagnostics($source))->toBe([$mistake])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqualSyntax($blocks);
})->with(function () {
    $noHeader = ['A `{ ... }` block needs a `for <schema>` header before it.', '{'];

    return [
        'with rules' => [
            '{ they can view }',
            $noHeader,
            [new RuleSetNode(nameNode(INode::MISSING_NAME), [new WarrantRuleNode(null, [new CanClauseNode([nameNode('view')], null, SyntaxLocation::noProducer())], [], SyntaxLocation::noProducer())], SyntaxLocation::noProducer())],
        ],
        'with an expression' => [
            '{ a and b }',
            $noHeader,
            [new SchemaConditionNode(nameNode(INode::MISSING_NAME), new AndNode(new ConditionNode(nameNode('a'), [], SyntaxLocation::noProducer()), new ConditionNode(nameNode('b'), [], SyntaxLocation::noProducer()), SyntaxLocation::noProducer()), SyntaxLocation::noProducer())],
        ],
        'with no name after for' => [
            'for { they can view }',
            ['Expected a schema name after `for`.', '{'],
            [new RuleSetNode(nameNode(INode::MISSING_NAME), [new WarrantRuleNode(null, [new CanClauseNode([nameNode('view')], null, SyntaxLocation::noProducer())], [], SyntaxLocation::noProducer())], SyntaxLocation::noProducer())],
        ],
    ];
});

it('reads on past a block written where it should not be, and every block after it', function (string $source, array $mistake, string $headed) {
    expect(analysisDiagnostics($source))->toBe([$mistake]);

    $children = WarrantParser::analyze($source)->syntax->children;
    $expected = WarrantParser::parse($headed)->children;

    // The text writes no header for a block that `$headed` writes `for missing` before.
    expect(array_map(static fn ($block) => $block->schemaKey(), $children))
        ->toBe(array_map(static fn ($block) => $block->schemaKey() === 'missing' ? INode::MISSING_NAME : $block->schemaKey(), $expected))
        ->and(array_map(static fn ($block) => $block->entries, $children))
        ->toEqualSyntax(array_map(static fn ($block) => $block->entries, $expected));
})->with([
    'a block with no header after a braced one' => [
        'for docs { they can view } { they can edit } for files { they can share }',
        ['Expected `for <schema> { ... }`; every rule set beside a braced one needs a `for` header and braces.', '{'],
        'for docs { they can view } for missing { they can edit } for files { they can share }',
    ],
    'a block after an unbraced one' => [
        'for docs they can view for files { they can edit }',
        ['Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.', 'for'],
        'for docs { they can view } for files { they can edit }',
    ],
    'a bare brace after an unbraced block' => [
        'for docs they can view { they can edit }',
        ['Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.', '{'],
        'for docs { they can view } for missing { they can edit }',
    ],
]);

it('keeps every token of the text, the comments and the unreadable text included', function () {
    $tokens = WarrantParser::analyze("if a # mine\nthey can view ~")->tokens;

    expect(array_map(static fn (Token $token): array => [$token->type, $token->lexeme], $tokens))->toBe([
        [TokenType::IF, 'if'],
        [TokenType::IDENTIFIER, 'a'],
        [TokenType::COMMENT, '# mine'],
        [TokenType::THEY, 'they'],
        [TokenType::CAN, 'can'],
        [TokenType::IDENTIFIER, 'view'],
        [TokenType::ERROR, '~'],
        [TokenType::EOF, ''],
    ]);
});

it('says which kind of mistake each diagnostic is about, and that it is an error', function (string $source, DiagnosticCode $code) {
    $diagnostics = analyzedDiagnostics($source, null);

    expect(array_map(static fn (Diagnostic $diagnostic): DiagnosticCode => $diagnostic->code, $diagnostics))->toBe([$code])
        ->and($diagnostics[0]->severity)->toBe(DiagnosticSeverity::Error);
})->with([
    'a character no token starts with' => ['if a they can view ~', DiagnosticCode::UnexpectedCharacter],
    'an escape a string does not allow' => ['if a("x\\q") they can view', DiagnosticCode::InvalidEscape],
    'an unknown word after @' => ['if a(@nope) they can view', DiagnosticCode::UnknownReference],
    'a token after the end' => ['if a they can view )', DiagnosticCode::UnexpectedToken],
    'a missing bracket' => ['if can view they can edit', DiagnosticCode::MissingToken],
    'a reserved word as a name' => ['if they can view', DiagnosticCode::ReservedWordAsName],
    'a missing name' => ['if check(a for ) they can view', DiagnosticCode::MissingName],
    'a missing condition' => ['if () they can view', DiagnosticCode::MissingCondition],
    'a missing argument' => ['if a(1, ) they can view', DiagnosticCode::MissingArgument],
    'a missing denial message' => ['if a they cannot view because', DiagnosticCode::MissingDenialMessage],
    'a missing clause' => ['if a they view', DiagnosticCode::MissingClause],
    'a key written twice' => ['if can(view for docs with org = 1, org = 2) they can edit', DiagnosticCode::DuplicateContextKey],
    'an ability list with no can they' => ['for docs { view, edit { they can } }', DiagnosticCode::AbilityListWithoutCanThey],
    'a block with no header' => ['{ they can view }', DiagnosticCode::BlockWithoutHeader],
    'a block after an unbraced one' => ['for docs they can view for files { they can edit }', DiagnosticCode::UnbracedBlockBesideAnother],
    'a block after rules with no header' => ['they can view for docs { }', DiagnosticCode::RulesBeforeBlock],
    'named and positional placeholders' => ['if a(:x, ?) they can view', DiagnosticCode::MixedBindings],
]);

it('throws the kind of mistake with the error in a strict parse', function () {
    try {
        WarrantParser::parse('if a they view');
    } catch (WarrantSyntaxException $error) {
    }

    expect($error->diagnosticCode ?? null)->toBe(DiagnosticCode::MissingClause);
});

