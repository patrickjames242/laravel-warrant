<?php

use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\SyntaxDiagnostic;
use Warrant\DSL\Parsing\WarrantParser;

/**
 * The diagnostics of an analysis, as [message, the text each covers].
 *
 * @return list<array{0: string, 1: string}>
 */
function analysisDiagnostics(string $source): array
{
    return array_map(
        static fn (SyntaxDiagnostic $diagnostic): array => [
            $diagnostic->message,
            substr($source, $diagnostic->offset, $diagnostic->endOffset - $diagnostic->offset),
        ],
        WarrantParser::analyze($source)->diagnostics,
    );
}

it('reads sound text to the same tree and positions as a strict read, with no diagnostics', function (string $source) {
    $analysed = WarrantParser::analyze($source);
    $strict = WarrantParser::parseWithPositions($source);

    expect($analysed->syntax)->toEqual($strict->syntax)
        ->and($analysed->diagnostics)->toBe([])
        ->and(count($analysed->positions->containing(0)))->toBe(count($strict->positions->containing(0)));
})->with([
    'a rule set' => "for docs {\n  if is_owner(@context org) they can view # mine\n  can they share { they can }\n}",
    'unscoped rules' => 'they can view if is_owner they can edit @include shared for view',
    'a bare expression' => 'is_owner or (is_admin and check(is_open for folders(@column folder_id)))',
    'nothing' => '',
]);

it('reads each placeholder as its own text, since there are no values to bind', function () {
    $syntax = WarrantParser::analyze('if in_period(:year, @sql :query) they cannot view because :why')->syntax;
    $rule = $syntax->children[0];

    expect($rule->conditions)->toEqual(new ConditionNode('in_period', [':year', new SqlRef(':query')]))
        ->and($rule->cannotClauses[0]->message)->toBe(':why')
        ->and(WarrantParser::analyze('if in_period(?, ?) they can view')->syntax->children[0]->conditions)
        ->toEqual(new ConditionNode('in_period', ['?', '?']));
});

it('still reports named and positional placeholders mixed', function () {
    expect(analysisDiagnostics('if a(:x, ?) they can view'))->toBe([
        ['Cannot mix named and positional bindings.', '?'],
    ]);
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
        ->and($analysed->positions->containing(0))->toBe([]);
});

it('reports a lexical error before the grammar error it leads to', function () {
    expect(analysisDiagnostics('if a they can view ~'))->toBe([
        ["Unexpected character '~'.", '~'],
        ['Unexpected token; expected end of input.', '~'],
    ]);
});
