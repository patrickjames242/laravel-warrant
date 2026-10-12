<?php

use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\WarrantParser;

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

it('reports the error a strict read throws, over the token it is at, with an empty tree', function () {
    $source = 'if a they can view )';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([['Unexpected token; expected end of input.', ')']])
        ->and($analysed->syntax->children)->toBe([])
        ->and($analysed->syntax->positions->containing(0))->toBe([]);
});

it('reports a lexical error before the grammar error it leads to', function () {
    expect(analysisDiagnostics('if a they can view ~'))->toBe([
        ["Unexpected character '~'.", '~'],
        ['Unexpected token; expected end of input.', '~'],
    ]);
});
