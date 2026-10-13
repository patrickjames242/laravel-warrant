<?php

use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
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

it('reports a lexical error before the grammar error it leads to', function () {
    expect(analysisDiagnostics('if a they can view ~'))->toBe([
        ["Unexpected character '~'.", '~'],
        ['Unexpected token; expected end of input.', '~'],
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

it('reports each mistake in a text, and keeps the rules around them', function () {
    $source = "if a they view\nif b they can edit\nif ( they can x\nif d they can delete";
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([
        ["Expected 'can' or 'cannot' after 'they'.", 'view'],
        ["Reserved word 'they' cannot be used as a name; expected a condition, 'can(', 'check(', or '('.", 'they'],
    ])->and($analysed->syntax->children)->toEqual(WarrantParser::parse("if b they can edit\nif d they can delete")->children);
});

it('records where the rules it keeps were written, and nothing of a rule it leaves out', function () {
    $source = "if a they view\nif b they can edit";
    $positions = WarrantParser::analyze($source)->syntax->positions;

    expect($positions->containing(strpos($source, 'a ')))->toBe([])
        ->and($positions->containing(strpos($source, 'b ')))->not->toBe([]);
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
    $source = 'for docs { a and } for files { they can view }';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected a condition, 'can(', 'check(', or '('.", '}']])
        ->and($analysed->syntax->children)->toEqual(WarrantParser::parse('for files { they can view }')->children);
});

it('reads on past a mistake inside an ability block to the end of the block', function () {
    $source = 'can they view { if a they } if b they can edit';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected 'can' or 'cannot' after 'they'.", '}']])
        ->and($analysed->syntax->children)->toEqual(WarrantParser::parse('can they view { } if b they can edit')->children);
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
