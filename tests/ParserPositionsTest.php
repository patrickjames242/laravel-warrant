<?php

use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\ParseResult;
use Warrant\DSL\Parsing\Positions\SourceEntry;
use Warrant\DSL\Parsing\WarrantParser;

/**
 * Every node of a tree but its root, parents before children, in the order their
 * properties hold them.
 *
 * @return list<INode>
 */
function treeNodes(mixed $value): array
{
    $nodes = [];

    if ($value instanceof INode && ! $value instanceof WarrantSyntax) {
        $nodes[] = $value;
    }

    if (is_object($value) || is_array($value)) {
        foreach (is_object($value) ? get_object_vars($value) : $value as $child) {
            array_push($nodes, ...treeNodes($child));
        }
    }

    return $nodes;
}

/**
 * Each node of the parsed tree, as its class's short name and the text it was
 * recorded as written in.
 *
 * @return list<array{0: string, 1: ?string}>
 */
function recordedNodes(string $source): array
{
    $result = WarrantParser::parseWithPositions($source);

    return array_map(static function (INode $node) use ($result, $source): array {
        $span = $result->positions->spanOf($node);

        return [
            (new ReflectionClass($node))->getShortName(),
            $span === null ? null : substr($source, $span->offset, $span->length()),
        ];
    }, treeNodes($result->syntax));
}

const POSITIONS_CORPUS = [
    'a rule set with every kind of rule' => <<<'WARRANT'
        for docs {
            if is_owner(@context org) and not (is_archived or is_locked) they can view they cannot edit because 'no'
            can they share { if can(view) they can }
            @include admin(1) for delete
        }
        WARRANT,
    'cross-schema references' => 'if check(is_open and not is_locked for folders(@context folder) as f with org = @context org) '
        .'or can(edit for folders) they can view',
    'unscoped rules' => 'they can view if is_owner they can edit, delete @include shared for view',
    'a bare expression' => 'is_owner or (is_admin and is_active)',
    'a scoped expression' => 'for docs is_owner and can(view)',
    'braced blocks' => 'for docs { they can view } for folders { is_open } for docs { }',
    'an empty scoped body' => 'for docs',
    'comments' => "for docs { # rules\n  if is_owner # why\n they can view # done\n} # end",
];

it('records every node with the text it was written as', function () {
    expect(recordedNodes(POSITIONS_CORPUS['a rule set with every kind of rule']))->toBe([
        ['RuleSetNode', POSITIONS_CORPUS['a rule set with every kind of rule']],
        ['WarrantRuleNode', "if is_owner(@context org) and not (is_archived or is_locked) they can view they cannot edit because 'no'"],
        ['AndNode', 'is_owner(@context org) and not (is_archived or is_locked)'],
        ['ConditionNode', 'is_owner(@context org)'],
        ['NotNode', 'not (is_archived or is_locked)'],
        ['OrNode', 'is_archived or is_locked'],
        ['ConditionNode', 'is_archived'],
        ['ConditionNode', 'is_locked'],
        ['CanClauseNode', 'can view'],
        ['CannotClauseNode', "cannot edit because 'no'"],
        ['AbilityBlockNode', 'can they share { if can(view) they can }'],
        ['WarrantRuleNode', 'if can(view) they can'],
        ['CrossSchemaCanNode', 'can(view)'],
        ['CanClauseNode', 'can'],
        ['IncludeInvocationNode', '@include admin(1) for delete'],
    ]);
});

it('records cross-schema references whole, and the predicate inside', function () {
    expect(recordedNodes(POSITIONS_CORPUS['cross-schema references']))->toBe([
        ['WarrantRuleNode', POSITIONS_CORPUS['cross-schema references']],
        ['OrNode', 'check(is_open and not is_locked for folders(@context folder) as f with org = @context org) or can(edit for folders)'],
        ['CrossSchemaConditionNode', 'check(is_open and not is_locked for folders(@context folder) as f with org = @context org)'],
        ['AndNode', 'is_open and not is_locked'],
        ['ConditionNode', 'is_open'],
        ['NotNode', 'not is_locked'],
        ['ConditionNode', 'is_locked'],
        ['CrossSchemaCanNode', 'can(edit for folders)'],
        ['CanClauseNode', 'can view'],
    ]);
});

it('records rules and expressions written with no for header', function () {
    expect(recordedNodes(POSITIONS_CORPUS['unscoped rules']))->toBe([
        ['WarrantRuleNode', 'they can view'],
        ['CanClauseNode', 'can view'],
        ['WarrantRuleNode', 'if is_owner they can edit, delete'],
        ['ConditionNode', 'is_owner'],
        ['CanClauseNode', 'can edit, delete'],
        ['IncludeInvocationNode', '@include shared for view'],
    ])->and(recordedNodes(POSITIONS_CORPUS['a bare expression']))->toBe([
        ['OrNode', 'is_owner or (is_admin and is_active)'],
        ['ConditionNode', 'is_owner'],
        ['AndNode', 'is_admin and is_active'],
        ['ConditionNode', 'is_admin'],
        ['ConditionNode', 'is_active'],
    ]);
});

it('records each for body from its header', function () {
    expect(recordedNodes(POSITIONS_CORPUS['a scoped expression']))->toBe([
        ['SchemaConditionNode', 'for docs is_owner and can(view)'],
        ['AndNode', 'is_owner and can(view)'],
        ['ConditionNode', 'is_owner'],
        ['CrossSchemaCanNode', 'can(view)'],
    ])->and(recordedNodes(POSITIONS_CORPUS['braced blocks']))->toBe([
        ['RuleSetNode', 'for docs { they can view }'],
        ['WarrantRuleNode', 'they can view'],
        ['CanClauseNode', 'can view'],
        ['SchemaConditionNode', 'for folders { is_open }'],
        ['ConditionNode', 'is_open'],
        ['RuleSetNode', 'for docs { }'],
    ])->and(recordedNodes(POSITIONS_CORPUS['an empty scoped body']))->toBe([
        ['RuleSetNode', 'for docs'],
    ]);
});

it('leaves a comment after a node out of its range', function () {
    expect(recordedNodes(POSITIONS_CORPUS['comments']))->toBe([
        ['RuleSetNode', "for docs { # rules\n  if is_owner # why\n they can view # done\n}"],
        ['WarrantRuleNode', "if is_owner # why\n they can view"],
        ['ConditionNode', 'is_owner'],
        ['CanClauseNode', 'can view'],
    ]);
});

it('parses to the same tree whether or not it records positions', function (string $source) {
    expect(WarrantParser::parseWithPositions($source)->syntax)->toEqual(WarrantParser::parse($source));
})->with(POSITIONS_CORPUS);

it('records every node, and never two over the same text', function (string $source) {
    $result = WarrantParser::parseWithPositions($source);
    $ranges = [];

    foreach (treeNodes($result->syntax) as $node) {
        $span = $result->positions->spanOf($node);

        expect($span)->not->toBeNull();

        $ranges[] = $span->offset.'-'.$span->endOffset;
    }

    expect($ranges)->toBe(array_unique($ranges));
})->with(POSITIONS_CORPUS);

it('finds what an offset is inside, from the rule set down', function () {
    $source = POSITIONS_CORPUS['a rule set with every kind of rule'];
    $result = WarrantParser::parseWithPositions($source);

    $chain = array_map(
        static fn (SourceEntry $entry): string => (new ReflectionClass($entry->node))->getShortName(),
        $result->positions->containing(strpos($source, 'is_locked') + 3),
    );

    expect($result)->toBeInstanceOf(ParseResult::class)
        ->and($chain)->toBe(['RuleSetNode', 'WarrantRuleNode', 'AndNode', 'NotNode', 'OrNode', 'ConditionNode']);
});
