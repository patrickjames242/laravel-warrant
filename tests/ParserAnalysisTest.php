<?php

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\WarrantParser;
use Warrant\DSL\Parsing\WarrantSyntaxException;

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
        WarrantParser::analyze($source)->diagnostics,
    );
}

it('reads sound text to the same tree and positions as a strict read, with no diagnostics', function (string $source) {
    $analysed = WarrantParser::analyze($source);
    $strict = WarrantParser::parse($source);

    expect($analysed->syntax->children)->toEqual($strict->children)
        ->and($analysed->diagnostics)->toBe([])
        ->and(count($analysed->syntax->positions->containing(0)))->toBe(count($strict->positions->containing(0)));
})->with([
    'a rule set' => "for docs {\n  if is_owner(@context org) they can view # mine\n  can they share { they can }\n}",
    'unscoped rules' => 'they can view if is_owner they can edit @include shared for view',
    'a bare expression' => 'is_owner or (is_admin and check(is_open for folders(@column folder_id)))',
    'nothing' => '',
]);

it('keeps each placeholder as a binding node, since there are no values to bind', function () {
    $syntax = WarrantParser::analyze('if in_period(:year, @sql :query) they cannot view because :why')->syntax;
    $rule = $syntax->children[0];

    expect($rule->conditions)->toEqual(new ConditionNode('in_period', [new BindingNode('year'), new SqlRef(new BindingNode('query'))]))
        ->and($rule->cannotClauses[0]->message)->toEqual(new BindingNode('why'))
        ->and(WarrantParser::analyze('if in_period(?, ?) they can view')->syntax->children[0]->conditions)
        ->toEqual(new ConditionNode('in_period', [new BindingNode, new BindingNode]));
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
        ->and($analysed->syntax->children)->toEqual(WarrantParser::parse('if a they can view')->children)
        ->and($analysed->syntax->positions->containing(0))->not->toBe([]);
});

it('reports text the lexer could not read once, and reads the text around it as though it were not there', function (string $source, string $unreadable, string $without) {
    $analysed = WarrantParser::analyze($source);

    expect(array_map(static fn (array $diagnostic): string => $diagnostic[1], analysisDiagnostics($source)))->toBe([$unreadable])
        ->and($analysed->syntax->children)->toEqual(WarrantParser::parse($without)->children);
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
]);

it('reports each mistake in a text, and keeps every rule, repaired where it can be', function () {
    $source = "if a they view\nif b they can edit\nif ( they can x\nif d they can delete";
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([
        ["Expected 'can' or 'cannot' after 'they'.", 'view'],
        ["Reserved word 'they' cannot be used as a name; expected a condition, 'can(', 'check(', or '('.", 'they'],
    ])->and($analysed->syntax->children)->toEqual([
        new WarrantRuleNode(new ConditionNode('a'), [], []),
        ...WarrantParser::parse('if b they can edit')->children,
        new WarrantRuleNode(new MissingExpressionNode, [new CanClauseNode(['x'])], []),
        ...WarrantParser::parse('if d they can delete')->children,
    ]);
});

it('records where the rules it keeps were written, and nothing of a rule it leaves out', function () {
    $source = "if a they cannot view because\nif b they can edit";
    $analysed = WarrantParser::analyze($source);

    expect($analysed->syntax->children)->toEqual(WarrantParser::parse('if b they can edit')->children)
        ->and($analysed->syntax->positions->containing(strpos($source, 'a ')))->toBe([])
        ->and($analysed->syntax->positions->containing(strpos($source, 'b ')))->not->toBe([]);
});

it('reports a stray token in a block with the error a strict read throws there, and reads the entries after it', function () {
    $source = 'for docs { if a they can view ) if b they can edit }';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected '}' to close the rule set body.", ')']])
        ->and($analysed->syntax->children)
        ->toEqual(WarrantParser::parse('for docs { if a they can view if b they can edit }')->children);
});

it('keeps a block whose closing brace is missing', function () {
    $source = 'for docs { if a they can view';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected '}' to close the rule set body.", '']])
        ->and($analysed->syntax->children)->toEqual(WarrantParser::parse('for docs { if a they can view }')->children);
});

it('leaves out a braced block with a mistake in it, and reads the blocks after it', function () {
    $source = 'for docs { a(@context) } for files { they can view }';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected a context key after '@context'.", ')']])
        ->and($analysed->syntax->children)->toEqual(WarrantParser::parse('for files { they can view }')->children);
});

it('keeps a mistake inside an ability block within the block', function () {
    $source = 'can they view { if a they } if b they can edit';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected 'can' or 'cannot' after 'they'.", '}']])
        ->and($analysed->syntax->children)->toEqual([
            new AbilityBlockNode(['view'], [new WarrantRuleNode(new ConditionNode('a'), [], [])]),
            ...WarrantParser::parse('if b they can edit')->children,
        ]);
});

it('puts a placeholder where a condition is missing, and keeps what is around it', function (string $source, array $missing, IBooleanExpressionNode $conditions) {
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([$missing])
        ->and($analysed->syntax->children)->toEqual([new WarrantRuleNode($conditions, [new CanClauseNode(['view'])], [])]);
})->with(function () {
    $beforeThey = ["Reserved word 'they' cannot be used as a name; expected a condition, 'can(', 'check(', or '('.", 'they'];

    return [
        'after if' => ['if they can view', $beforeThey, new MissingExpressionNode],
        'after and' => ['if a and they can view', $beforeThey, new AndNode(new ConditionNode('a'), new MissingExpressionNode)],
        'after not' => ['if not they can view', $beforeThey, new NotNode(new MissingExpressionNode)],
        'inside a group' => ['if () they can view', ["Expected a condition, 'can(', 'check(', or '('.", ')'], new MissingExpressionNode],
    ];
});

it('keeps a body whose expression is missing an operand', function () {
    $source = 'for docs { a and } for files { they can view }';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected a condition, 'can(', 'check(', or '('.", '}']])
        ->and($analysed->syntax->children)->toEqual([
            new SchemaConditionNode('docs', new AndNode(new ConditionNode('a'), new MissingExpressionNode)),
            ...WarrantParser::parse('for files { they can view }')->children,
        ]);
});

it('reads on as though a missing bracket or keyword were there', function (string $source, string $missing, string $at, string $repaired) {
    expect(analysisDiagnostics($source))->toBe([[$missing, $at]])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqual(WarrantParser::parse($repaired)->children);
})->with([
    'a closing parenthesis' => ['if (a or b they can view', "Expected ')' to close the group.", 'they', 'if (a or b) they can view'],
    'the end of an argument list' => ['if a(1 they can view', "Expected ')' to close the condition arguments.", 'they', 'if a(1) they can view'],
    'the parenthesis after can' => ['if can view they can edit', "Expected '(' after 'can'.", 'view', 'if can(view) they can edit'],
    'the end of a check' => ['if check(a for docs they can view', "Expected ')' to close 'check(...)'.", 'they', 'if check(a for docs) they can view'],
    'the brace after a block header' => ['can they view if a they can }', "Expected '{' to open the ability block body.", 'if', 'can they view { if a they can }'],
]);

it('keeps a rule whose clauses are missing', function (string $source, string $mistake) {
    expect(array_column(analysisDiagnostics($source), 0))->toBe([$mistake])
        ->and(WarrantParser::analyze($source)->syntax->children)->toEqual([new WarrantRuleNode(new ConditionNode('a'), [], [])]);
})->with([
    'no they' => ['if a', "Expected at least one 'they can ...' or 'they cannot ...' clause."],
    'a they with no can' => ['if a they', "Expected 'can' or 'cannot' after 'they'."],
]);

it('keeps the clauses around a they that heads none', function () {
    expect(WarrantParser::analyze('if a they can view they')->syntax->children)
        ->toEqual(WarrantParser::parse('if a they can view')->children);
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
