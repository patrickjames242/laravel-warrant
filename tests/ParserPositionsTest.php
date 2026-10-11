<?php

use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
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
    'symbolic references' => 'if in_period(@context year, @column docs.org_id, @column org_id, @sql "select 1") they can view',
];

it('records every node with the text it was written as', function () {
    expect(recordedNodes(POSITIONS_CORPUS['a rule set with every kind of rule']))->toBe([
        ['RuleSetNode', POSITIONS_CORPUS['a rule set with every kind of rule']],
        ['WarrantRuleNode', "if is_owner(@context org) and not (is_archived or is_locked) they can view they cannot edit because 'no'"],
        ['AndNode', 'is_owner(@context org) and not (is_archived or is_locked)'],
        ['ConditionNode', 'is_owner(@context org)'],
        ['ContextRef', '@context org'],
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
        ['ContextRef', '@context folder'],
        ['ContextRef', '@context org'],
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

it('records each symbolic reference as written', function () {
    expect(recordedNodes(POSITIONS_CORPUS['symbolic references']))->toBe([
        ['WarrantRuleNode', POSITIONS_CORPUS['symbolic references']],
        ['ConditionNode', 'in_period(@context year, @column docs.org_id, @column org_id, @sql "select 1")'],
        ['ContextRef', '@context year'],
        ['ColumnRef', '@column docs.org_id'],
        ['ColumnRef', '@column org_id'],
        ['SqlRef', '@sql "select 1"'],
        ['CanClauseNode', 'can view'],
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

it('finds what an offset is inside, from the rule set down to the part', function () {
    $source = POSITIONS_CORPUS['a rule set with every kind of rule'];
    $result = WarrantParser::parseWithPositions($source);

    $chain = array_map(
        static fn (SourceEntry $entry): array => [(new ReflectionClass($entry->node))->getShortName(), $entry->part],
        $result->positions->containing(strpos($source, 'is_locked') + 3),
    );

    expect($result)->toBeInstanceOf(ParseResult::class)
        ->and($chain)->toBe([
            ['RuleSetNode', null], ['WarrantRuleNode', null], ['AndNode', null], ['NotNode', null],
            ['OrNode', null], ['ConditionNode', null], ['ConditionNode', ConditionNode::PART_CONDITION_KEY],
        ]);
});

it('puts an argument inside the part of the node it is passed to', function () {
    $source = POSITIONS_CORPUS['symbolic references'];
    $result = WarrantParser::parseWithPositions($source);

    $chain = array_map(
        static fn (SourceEntry $entry): array => [(new ReflectionClass($entry->node))->getShortName(), $entry->part, $entry->key],
        $result->positions->containing(strpos($source, 'org_id') + 2),
    );

    expect($chain)->toBe([
        ['WarrantRuleNode', null, null], ['ConditionNode', null, null],
        ['ConditionNode', ConditionNode::PART_PARAMETERS, 1], ['ColumnRef', null, null],
        ['ColumnRef', ColumnRef::PART_COLUMN, null],
    ]);
});

// -- parts ----------------------------------------------------------------------

/**
 * The text a part of a node was recorded as written in, or null.
 */
function partText(ParseResult $result, string $source, object $node, string $part, int|string|null $key = null): ?string
{
    $span = $result->positions->spanOf($node, $part, $key);

    return $span === null ? null : substr($source, $span->offset, $span->length());
}

it('records the names and arguments of a rule set\'s entries', function () {
    $source = POSITIONS_CORPUS['a rule set with every kind of rule'];
    $result = WarrantParser::parseWithPositions($source);
    $ruleSet = $result->syntax->children[0];
    [$rule, $block, $include] = $ruleSet->entries;
    $isOwner = $rule->conditions->leftSide;

    expect(partText($result, $source, $ruleSet, RuleSetNode::PART_SCHEMA_KEY))->toBe('docs')
        ->and(partText($result, $source, $isOwner, ConditionNode::PART_CONDITION_KEY))->toBe('is_owner')
        ->and(partText($result, $source, $isOwner, ConditionNode::PART_PARAMETERS, 0))->toBe('@context org')
        ->and(partText($result, $source, $rule->canClauses[0], CanClauseNode::PART_ABILITIES, 0))->toBe('view')
        ->and(partText($result, $source, $rule->cannotClauses[0], CannotClauseNode::PART_ABILITIES, 0))->toBe('edit')
        ->and(partText($result, $source, $rule->cannotClauses[0], CannotClauseNode::PART_MESSAGE))->toBe("'no'")
        ->and(partText($result, $source, $block, AbilityBlockNode::PART_ABILITIES, 0))->toBe('share')
        ->and(partText($result, $source, $block->entries[0]->conditions, CrossSchemaCanNode::PART_ABILITY))->toBe('view')
        ->and(partText($result, $source, $include, IncludeInvocationNode::PART_TEMPLATE_KEY))->toBe('admin')
        ->and(partText($result, $source, $include, IncludeInvocationNode::PART_ARGUMENTS, 0))->toBe('1')
        ->and(partText($result, $source, $include, IncludeInvocationNode::PART_ABILITIES, 0))->toBe('delete');
});

it('records each part of a cross-schema reference', function () {
    $source = 'if check(is_open(@column docs.org_id, 2) for folders(@context folder, 7) as f with org = @context org, n = 1) '
        .'they can edit, *';
    $result = WarrantParser::parseWithPositions($source);
    $rule = $result->syntax->children[0];
    $check = $rule->conditions;

    expect(partText($result, $source, $check, CrossSchemaConditionNode::PART_SCHEMA_KEY))->toBe('folders')
        ->and(partText($result, $source, $check, CrossSchemaConditionNode::PART_BOUND_KEY, 0))->toBe('@context folder')
        ->and(partText($result, $source, $check, CrossSchemaConditionNode::PART_BOUND_KEY, 1))->toBe('7')
        ->and(partText($result, $source, $check, CrossSchemaConditionNode::PART_ALIAS))->toBe('f')
        ->and(partText($result, $source, $check, CrossSchemaConditionNode::PART_CONTEXT_MAP_KEY, 'org'))->toBe('org')
        ->and(partText($result, $source, $check, CrossSchemaConditionNode::PART_CONTEXT_MAP, 'org'))->toBe('@context org')
        ->and(partText($result, $source, $check, CrossSchemaConditionNode::PART_CONTEXT_MAP_KEY, 'n'))->toBe('n')
        ->and(partText($result, $source, $check, CrossSchemaConditionNode::PART_CONTEXT_MAP, 'n'))->toBe('1')
        ->and(partText($result, $source, $check->predicate, ConditionNode::PART_PARAMETERS, 0))->toBe('@column docs.org_id')
        ->and(partText($result, $source, $rule->canClauses[0], CanClauseNode::PART_ABILITIES, 0))->toBe('edit')
        ->and(partText($result, $source, $rule->canClauses[0], CanClauseNode::PART_ABILITIES, 1))->toBe('*');
});

it('records the names inside each symbolic reference', function () {
    $source = POSITIONS_CORPUS['symbolic references'];
    $result = WarrantParser::parseWithPositions($source);
    [$context, $qualified, $unqualified, $sql] = $result->syntax->children[0]->conditions->parameters;

    expect(partText($result, $source, $context, ContextRef::PART_KEY))->toBe('year')
        ->and(partText($result, $source, $qualified, ColumnRef::PART_ALIAS))->toBe('docs')
        ->and(partText($result, $source, $qualified, ColumnRef::PART_COLUMN))->toBe('org_id')
        ->and(partText($result, $source, $unqualified, ColumnRef::PART_ALIAS))->toBeNull()
        ->and(partText($result, $source, $unqualified, ColumnRef::PART_COLUMN))->toBe('org_id')
        ->and(partText($result, $source, $sql, SqlRef::PART_SQL))->toBe('"select 1"');
});

it('records a bound @sql string as the binding it was written as', function () {
    $source = 'if in_period(@sql :query) they can view';
    $result = WarrantParser::parseWithPositions($source, ['query' => 'select 1']);
    $sql = $result->syntax->children[0]->conditions->parameters[0];

    expect(partText($result, $source, $sql, SqlRef::PART_SQL))->toBe(':query');
});

it('records the schema of every for header', function () {
    $source = POSITIONS_CORPUS['braced blocks'];
    $result = WarrantParser::parseWithPositions($source);

    expect(array_map(
        static fn (object $body): ?string => partText($result, $source, $body, RuleSetNode::PART_SCHEMA_KEY),
        $result->syntax->children,
    ))->toBe(['docs', 'folders', 'docs'])
        ->and(partText($result, $source, $result->syntax->children[1], SchemaConditionNode::PART_SCHEMA_KEY))->toBe('folders');

    $bare = WarrantParser::parseWithPositions(POSITIONS_CORPUS['a scoped expression']);

    expect(partText($bare, POSITIONS_CORPUS['a scoped expression'], $bare->syntax->children[0], SchemaConditionNode::PART_SCHEMA_KEY))->toBe('docs');
});

it('records every value a node holds as a part of it, named by one of its constants', function (string $source) {
    $result = WarrantParser::parseWithPositions($source);

    foreach (treeNodes($result->syntax) as $node) {
        foreach (get_object_vars($node) as $name => $value) {
            if ($value === null || is_bool($value) || $value instanceof INode) {
                continue;
            }

            if (! is_array($value)) {
                expect($result->positions->spanOf($node, $name))->not->toBeNull($node::class." [$name]")
                    ->and((new ReflectionClass($node))->getConstants())->toContain($name);

                continue;
            }

            foreach ($value as $key => $item) {
                if ($item instanceof INode) {
                    continue;
                }

                expect($result->positions->spanOf($node, $name, $key))->not->toBeNull($node::class." [$name][$key]")
                    ->and((new ReflectionClass($node))->getConstants())->toContain($name);

                if ($name === CrossSchemaCanNode::PART_CONTEXT_MAP) {
                    expect($result->positions->spanOf($node, $node::PART_CONTEXT_MAP_KEY, $key))->not->toBeNull();
                }
            }
        }
    }
})->with(POSITIONS_CORPUS);
