<?php

use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ParseResult;
use Warrant\DSL\Parsing\Parsers\ParsingState;
use Warrant\DSL\Parsing\SyntaxDiagnostic;
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

it('reports an error it cannot read past, over the token it is at, with an empty tree', function () {
    $source = 'is_owner and (is_admin or )';
    $analysed = WarrantParser::analyze($source);

    expect(analysisDiagnostics($source))->toBe([["Expected a condition, 'can(', 'check(', or '('.", ')']])
        ->and($analysed->syntax->children)->toBe([])
        ->and($analysed->positions->containing(0))->toBe([]);
});

it('reports only the first error at a place, as the lexer\'s before the grammar\'s it leads to', function () {
    expect(analysisDiagnostics('if a they can view ~'))->toBe([
        ["Unexpected character '~'.", '~'],
    ]);
});

// -- errors the read goes on past -------------------------------------------------

/**
 * The analysis of $source, which must be the tree a strict read gives $sound,
 * with $diagnostics.
 *
 * @param list<array{0: string, 1: string}> $diagnostics
 */
function expectAnalysedAs(string $source, string $sound, array $diagnostics): void
{
    expect(WarrantParser::analyze($source)->syntax)->toEqual(WarrantParser::parse($sound))
        ->and(analysisDiagnostics($source))->toBe($diagnostics);
}

it('keeps what it read when text follows it', function () {
    expectAnalysedAs('if a they can view ) b', 'if a they can view', [
        ['Unexpected token; expected end of input.', ')'],
    ]);
});

it('keeps what it read before a block that cannot follow it, and reports the block once', function () {
    expectAnalysedAs('for a they can view for b they can edit', 'for a they can view', [
        ['Multiple rule sets in one source must each be braced, as `for <schema> { ... }`.', 'for'],
    ]);
    expectAnalysedAs('for a { they can view } they can edit', 'for a { they can view }', [
        ['Expected `for <schema> { ... }`; every rule set beside a braced one needs a `for` header and braces.', 'they'],
    ]);
    expectAnalysedAs('they can view for b they can edit', 'they can view', [
        ['Rules without a `for` header cannot be followed by a `for` block; put them in a block of their own.', 'for'],
    ]);
    expectAnalysedAs('{ they can view }', '', [
        ['A `{ ... }` block needs a `for <schema>` header before it.', '{'],
    ]);
});

it('reports abilities named or left off against the rules around them, and keeps what is written', function () {
    $mismatch = static fn (string $entry, bool $names): string => sprintf(
        '%s %s, but the rules before it %s; rules with no `for` header either all name their abilities '
            .'or all leave them to be named where the rules are placed.',
        $entry,
        $names ? 'names abilities' : 'names none',
        $names ? 'name none' : 'name theirs',
    );

    $syntax = WarrantParser::analyze('they can view they cannot')->syntax;

    expect($syntax->children[0]->canClauses[0]->abilities)->toBe(['view'])
        ->and($syntax->children[0]->cannotClauses[0]->abilities)->toBe([])
        ->and(analysisDiagnostics('they can view they cannot'))->toBe([[$mismatch('This clause', false), '']]);

    expect(analysisDiagnostics('they can if a they can view'))->toBe([[$mismatch('This clause', true), 'view']])
        ->and(analysisDiagnostics('they can @include t for view'))->toBe([[$mismatch('This @include', true), 'for']])
        ->and(analysisDiagnostics('they can can they view { they can }'))->toBe([[$mismatch('An ability block', true), 'can']]);
});

it('reports abilities named in an ability block, and reads and drops them', function () {
    $source = 'for d { can they a { they can b @include t for c } }';

    expectAnalysedAs($source, 'for d { can they a { they can @include t } }', [
        ['A clause inside an ability block may not name abilities; the block header already names them.', 'b'],
        ['An @include inside an ability block may not name abilities; the block header already names them.', 'for'],
    ]);
});

it('reports a denial message on a can clause and steps over it', function () {
    expectAnalysedAs("they can view because 'no' they cannot edit", 'they can view they cannot edit', [
        ["'because' may only follow a 'they cannot ...' clause, not 'they can ...'.", 'because'],
    ]);
});

it('drops what a parser that does not match reported', function () {
    $state = ParsingState::forAnalysis('if a they can view');
    $checkpoint = $state->checkpoint();

    $state->diagnose(WarrantSyntaxException::atOffset('Wrong.', 'if a they can view', 0, 1, 1));
    $state->restore($checkpoint);

    expect($state->diagnostics)->toBe([]);
});

// -- recovering from a broken rule entry ------------------------------------------

it('steps over a broken entry and reads the entries after it', function () {
    expectAnalysedAs('if a they can view if b they can , if d they can share', 'if a they can view if d they can share', [
        ['Expected an ability name.', ','],
    ]);
});

it('steps over a broken entry inside an ability block, and keeps the block', function () {
    expectAnalysedAs(
        'for x { can they a { if b and and c they can if d they can } they can e }',
        'for x { can they a { they can if d they can } they can e }',
        [["Reserved word 'and' cannot be used as a name; expected a condition, 'can(', 'check(', or '('.", 'and']],
    );
});

it('steps over braces a broken entry runs into, rather than restarting inside them', function () {
    expectAnalysedAs('for x { if a they can , y { they can z } they can w }', 'for x { they can w }', [
        ['Expected an ability name.', ','],
    ]);
});

it('reads a for body as a rule set when its only entry was stepped over', function () {
    $syntax = WarrantParser::analyze('for x { if b they can , }')->syntax;

    expect($syntax->children)->toHaveCount(1)
        ->and($syntax->children[0]->entries)->toBe([]);
});

it('notes at most a hundred diagnostics', function () {
    expect(WarrantParser::analyze(str_repeat('they can view, , ', 150))->diagnostics)
        ->toHaveCount(ParsingState::MAX_DIAGNOSTICS);
});

it('finishes on every prefix of every example rule text, with a source map it can commit', function () {
    preg_match_all("/<<<'WARRANT'\n(.*?)\n\s*WARRANT/s", file_get_contents(__DIR__.'/../src/DSL/RuleSyntaxExamples.php'), $matches);

    expect($matches[1])->not->toBe([]);

    foreach ($matches[1] as $source) {
        for ($length = 0; $length <= strlen($source); $length++) {
            expect(WarrantParser::analyze(substr($source, 0, $length)))->toBeInstanceOf(ParseResult::class);
        }
    }
});
