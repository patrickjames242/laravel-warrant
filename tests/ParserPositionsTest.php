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
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\WarrantParser;

require_once __DIR__.'/Support/TestSupport.php';

/**
 * Every node of a tree, parents before children, in the order each node
 * lists the nodes it holds.
 *
 * @return list<INode>
 */
function treeNodes(WarrantSyntax|INode $value): array
{
    $nodes = $value instanceof INode ? [$value] : [];

    foreach ($value instanceof INode ? $value->children() : $value->children as $child) {
        array_push($nodes, ...treeNodes($child));
    }

    return $nodes;
}

/**
 * Whether $node is a name, a literal or a `with` entry: a piece of the node
 * holding it rather than a construct of its own.
 */
function isLeafPiece(INode $node): bool
{
    return $node instanceof NameNode || $node instanceof LiteralNode || $node instanceof WithEntryNode;
}

/**
 * The text $node was written as in $source, or null when it was written nowhere.
 */
function writtenText(string $source, ?INode $node): ?string
{
    $span = $node?->location->span;

    return $span === null ? null : substr($source, $span->offset, $span->length());
}

/**
 * Each construct of the parsed tree, as its class's short name and the text it
 * was written as.
 *
 * @return list<array{0: string, 1: ?string}>
 */
function recordedNodes(string $source): array
{
    return array_map(
        static fn (INode $node): array => [(new ReflectionClass($node))->getShortName(), writtenText($source, $node)],
        array_values(array_filter(treeNodes(WarrantParser::parse($source)), static fn (INode $node): bool => ! isLeafPiece($node))),
    );
}

/**
 * The nodes of $syntax written over $offset, as their classes' short names,
 * parents before children.
 *
 * @return list<string>
 */
function nodesOver(WarrantSyntax $syntax, int $offset): array
{
    return array_values(array_map(
        static fn (INode $node): string => (new ReflectionClass($node))->getShortName(),
        array_filter(treeNodes($syntax), static fn (INode $node): bool => $node->location->span?->contains($offset) ?? false),
    ));
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
    'a when block' => 'when is_open and is_shared { if is_owner they can edit @include shared for view } otherwise { they cannot edit }',
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
        ['RuleNode', "if is_owner(@context org) and not (is_archived or is_locked) they can view they cannot edit because 'no'"],
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
        ['RuleNode', 'if can(view) they can'],
        ['CrossSchemaCanNode', 'can(view)'],
        ['CanClauseNode', 'can'],
        ['IncludeInvocationNode', '@include admin(1) for delete'],
    ]);
});

it('records a when block whole, its condition and both bodies', function () {
    expect(recordedNodes(POSITIONS_CORPUS['a when block']))->toBe([
        ['WhenBlockNode', POSITIONS_CORPUS['a when block']],
        ['AndNode', 'is_open and is_shared'],
        ['ConditionNode', 'is_open'],
        ['ConditionNode', 'is_shared'],
        ['RuleNode', 'if is_owner they can edit'],
        ['ConditionNode', 'is_owner'],
        ['CanClauseNode', 'can edit'],
        ['IncludeInvocationNode', '@include shared for view'],
        ['RuleNode', 'they cannot edit'],
        ['CannotClauseNode', 'cannot edit'],
    ]);
});

it('records cross-schema references whole, and the predicate inside', function () {
    expect(recordedNodes(POSITIONS_CORPUS['cross-schema references']))->toBe([
        ['RuleNode', POSITIONS_CORPUS['cross-schema references']],
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
        ['RuleNode', 'they can view'],
        ['CanClauseNode', 'can view'],
        ['RuleNode', 'if is_owner they can edit, delete'],
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
        ['RuleNode', 'they can view'],
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
        ['RuleNode', POSITIONS_CORPUS['symbolic references']],
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
        ['RuleNode', "if is_owner # why\n they can view"],
        ['ConditionNode', 'is_owner'],
        ['CanClauseNode', 'can view'],
    ]);
});

it('reads the same tree through WarrantSyntax::parse() as through the parser', function (string $source) {
    expect(WarrantSyntax::parse($source)->children)->toEqualSyntax(WarrantParser::parse($source)->children);
})->with(POSITIONS_CORPUS);

it('places every node where it was written, and never two constructs over the same text', function (string $source) {
    $ranges = [];

    foreach (treeNodes(WarrantParser::parse($source)) as $node) {
        expect($node->location->isWritten())->toBeTrue($node::class);

        if (! isLeafPiece($node)) {
            $ranges[] = $node->location->span->offset.'-'.$node->location->span->endOffset;
        }
    }

    expect($ranges)->toBe(array_unique($ranges));
})->with(POSITIONS_CORPUS);

it('finds what an offset is inside, from the rule set down to the name', function () {
    $source = POSITIONS_CORPUS['a rule set with every kind of rule'];

    expect(nodesOver(WarrantParser::parse($source), strpos($source, 'is_locked') + 3))->toBe([
        'RuleSetNode', 'RuleNode', 'AndNode', 'NotNode', 'OrNode', 'ConditionNode', 'NameNode',
    ]);
});

it('puts an argument inside the node it is passed to', function () {
    $source = POSITIONS_CORPUS['symbolic references'];

    expect(nodesOver(WarrantParser::parse($source), strpos($source, 'org_id') + 2))->toBe([
        'RuleNode', 'ConditionNode', 'ColumnRef', 'NameNode',
    ]);
});

// -- names and literals ---------------------------------------------------------

it('records the names and arguments of a rule set\'s entries', function () {
    $source = POSITIONS_CORPUS['a rule set with every kind of rule'];
    $result = WarrantParser::parse($source);
    $ruleSet = $result->children[0];
    [$rule, $block, $include] = $ruleSet->entries;
    $isOwner = $rule->conditions->leftSide;

    expect(writtenText($source, $ruleSet->schemaKey))->toBe('docs')
        ->and(writtenText($source, $isOwner->conditionKey))->toBe('is_owner')
        ->and(writtenText($source, $isOwner->parameters[0]))->toBe('@context org')
        ->and(writtenText($source, $rule->canClauses[0]->abilities[0]))->toBe('view')
        ->and(writtenText($source, $rule->cannotClauses[0]->abilities[0]))->toBe('edit')
        ->and(writtenText($source, $rule->cannotClauses[0]->message))->toBe("'no'")
        ->and(writtenText($source, $block->abilities[0]))->toBe('share')
        ->and(writtenText($source, $block->entries[0]->conditions->ability))->toBe('view')
        ->and(writtenText($source, $include->templateKey))->toBe('admin')
        ->and(writtenText($source, $include->arguments[0]))->toBe('1')
        ->and(writtenText($source, $include->abilities[0]))->toBe('delete');
});

it('records each part of a cross-schema reference', function () {
    $source = 'if check(is_open(@column docs.org_id, 2) for folders(@context folder, 7) as f with org = @context org, n = 1) '
        .'they can edit, *';
    $result = WarrantParser::parse($source);
    $rule = $result->children[0];
    $check = $rule->conditions;

    expect(writtenText($source, $check->schemaKey))->toBe('folders')
        ->and(writtenText($source, $check->boundKey[0]))->toBe('@context folder')
        ->and(writtenText($source, $check->boundKey[1]))->toBe('7')
        ->and(writtenText($source, $check->alias))->toBe('f')
        ->and(writtenText($source, $check->contextMap[0]))->toBe('org = @context org')
        ->and(writtenText($source, $check->contextMap[0]->key))->toBe('org')
        ->and(writtenText($source, $check->contextMap[0]->value))->toBe('@context org')
        ->and(writtenText($source, $check->contextMap[1]->key))->toBe('n')
        ->and(writtenText($source, $check->contextMap[1]->value))->toBe('1')
        ->and(writtenText($source, $check->predicate->parameters[0]))->toBe('@column docs.org_id')
        ->and(writtenText($source, $rule->canClauses[0]->abilities[0]))->toBe('edit')
        ->and(writtenText($source, $rule->canClauses[0]->abilities[1]))->toBe('*');
});

it('records the names inside each symbolic reference', function () {
    $source = POSITIONS_CORPUS['symbolic references'];
    $result = WarrantParser::parse($source);
    [$context, $qualified, $unqualified, $sql] = $result->children[0]->conditions->parameters;

    expect(writtenText($source, $context->key))->toBe('year')
        ->and(writtenText($source, $qualified->alias))->toBe('docs')
        ->and(writtenText($source, $qualified->column))->toBe('org_id')
        ->and($unqualified->alias)->toBeNull()
        ->and(writtenText($source, $unqualified->column))->toBe('org_id')
        ->and(writtenText($source, $sql->sql))->toBe('"select 1"');
});

it('records a bound @sql string as the binding it was written as', function () {
    $source = 'if in_period(@sql :query) they can view';
    $result = WarrantParser::parse($source);
    $sql = $result->children[0]->conditions->parameters[0];

    expect(writtenText($source, $sql->sql))->toBe(':query');
});

it('records the schema of every for header', function () {
    $source = POSITIONS_CORPUS['braced blocks'];
    $result = WarrantParser::parse($source);

    expect(array_map(
        static fn (RuleSetNode|SchemaConditionNode $body): ?string => writtenText($source, $body->schemaKey),
        $result->children,
    ))->toBe(['docs', 'folders', 'docs']);

    $bare = WarrantParser::parse(POSITIONS_CORPUS['a scoped expression']);

    expect(writtenText(POSITIONS_CORPUS['a scoped expression'], $bare->children[0]->schemaKey))->toBe('docs');
});

it('places every name and literal a node holds where it was written', function (string $source) {
    foreach (treeNodes(WarrantParser::parse($source)) as $node) {
        if ($node instanceof NameNode || $node instanceof LiteralNode) {
            expect(writtenText($source, $node))->not->toBeNull($node::class);
        }
    }
})->with(POSITIONS_CORPUS);
