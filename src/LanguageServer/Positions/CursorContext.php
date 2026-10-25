<?php

namespace Warrant\LanguageServer\Positions;

use Closure;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\Node;
use Warrant\DSL\Parsing\ASTNodes\SchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\SchemaCatalog;

/**
 * What is at one offset of parsed rule text, for an editor answering about it: the
 * nodes written there, what kind of name the innermost is, and what is in scope
 * there.
 *
 * A name the text is missing was written nowhere, so an offset where one belongs
 * is on the node around it, with no slot. What was written just before such an
 * offset is in the tokens of the {@see \Warrant\DSL\Parsing\AnalysisResult}.
 */
final readonly class CursorContext
{
    /**
     * @param list<Node> $nodes Every node written over the offset, widest
     *   first, and of two over the same text the one holding the other first.
     *   The last is what the offset is on; the ones before it say what that
     *   sits inside, such as the check(...) whose schema a condition name
     *   belongs to.
     * @param CursorScope $scope What is in scope at the offset.
     * @param SlotKind|null $slot What kind of name the offset is on, or null when
     *   it is on no name.
     * @param string|null $name The name the offset is on, as written; null when
     *   it is on no name.
     * @param string|null $nameSchemaKey The schema that declares $name, which an
     *   editor looks it up in: the target of a `can(... for <schema>)` for its
     *   ability, the schema a frame name stands for, and the schema in scope
     *   for most others. Null for a schema's own name, which the catalog
     *   declares, and when that schema is not known.
     */
    public function __construct(
        public array $nodes,
        public CursorScope $scope,
        public ?SlotKind $slot = null,
        public ?string $name = null,
        public ?string $nameSchemaKey = null,
    ) {
    }

    /**
     * The context at byte $offset of $syntax.
     *
     * @param WarrantSyntax $syntax The parsed text, each node placed where it was
     *   written.
     * @param int $offset 0-based byte offset into the text; one just past a name
     *   is on that name, where a cursor sits after typing it.
     * @param SchemaCatalog|null $catalog Where the schemas the text names are
     *   found, to tell which have rows and so are frames; without one, every
     *   schema is taken to have rows.
     * @param string|null $schemaKey The schema rules with no `for` header are
     *   about, or null when that is not known.
     */
    public static function at(WarrantSyntax $syntax, int $offset, ?SchemaCatalog $catalog = null, ?string $schemaKey = null): self
    {
        $nodes = [];
        $parents = [];

        foreach ($syntax->children as $child) {
            self::collect($child, null, $offset, $nodes, $parents);
        }

        /* The walk lists a node before the nodes inside it, and a stable sort
           keeps that order between two over the same text. */
        usort($nodes, static fn (Node $a, Node $b): int => $b->location->span->length() <=> $a->location->span->length());

        $innermost = $nodes === [] ? null : $nodes[array_key_last($nodes)];

        $hasRows = static fn (string $key): bool => $catalog?->find($key)?->describesRows() ?? true;
        $headerless = CursorScope::root($schemaKey, $schemaKey !== null && $hasRows($schemaKey));

        $scope = null;

        if ($innermost !== null) {
            foreach ($syntax->children as $child) {
                $scope = self::scopeOf($innermost, $child, $headerless, $hasRows);

                if ($scope !== null) {
                    break;
                }
            }
        }

        $scope ??= $syntax->isSchemaScoped() ? new CursorScope : $headerless;

        [$slot, $name, $nameSchemaKey] = $innermost instanceof NameNode
            ? self::slotOf($innermost, $parents, $scope)
            : [null, null, null];

        return new self($nodes, $scope, $slot, $name, $nameSchemaKey);
    }

    /**
     * Add $node and every node inside it that was written over $offset to
     * $nodes, parent before child, and note each one's parent in $parents.
     * Every node is looked in, since a stand-in for something missing is placed
     * where it belongs, which may be past the end of the node holding it. A
     * missing name was written nowhere, so it is never on the offset.
     *
     * @param list<Node> $nodes
     * @param array<int, Node> $parents Each node's parent, by object id.
     */
    private static function collect(Node $node, ?Node $parent, int $offset, array &$nodes, array &$parents): void
    {
        if ($parent !== null) {
            $parents[spl_object_id($node)] = $parent;
        }

        $span = $node->location->span;
        $missingName = $node instanceof NameNode && $node->name === Node::MISSING_NAME;

        if ($span !== null && $span->contains($offset) && ! $missingName) {
            $nodes[] = $node;
        }

        foreach ($node->children() as $child) {
            self::collect($child, $node, $offset, $nodes, $parents);
        }
    }

    /**
     * The scope $target is written in, when it is $node or inside it; null when
     * it is not. A `for` body is its own scope, since what is written in it is
     * about the schema its header names, and a `check(...)` predicate is written
     * in the scope its handle enters.
     *
     * @param Node $target The node to find.
     * @param Node $node The node to look in, written in $scope.
     * @param CursorScope $scope The scope $node is written in.
     * @param Closure(string): bool $hasRows Whether the schema of a key has rows.
     */
    private static function scopeOf(Node $target, Node $node, CursorScope $scope, Closure $hasRows): ?CursorScope
    {
        if ($node instanceof SchemaScopedNode) {
            $key = $node->schemaKey() === Node::MISSING_NAME ? null : $node->schemaKey();
            $scope = CursorScope::root($key, $key !== null && $hasRows($key));
        }

        if ($node === $target) {
            return $scope;
        }

        foreach ($node->children() as $child) {
            $childScope = $scope;

            if ($node instanceof CrossSchemaConditionNode && $child === $node->predicate) {
                $key = $node->schemaKey->name === Node::MISSING_NAME ? null : $node->schemaKey->name;
                $childScope = $scope->enteringPredicate($key, $node->alias?->name, $key !== null && $hasRows($key));
            }

            $found = self::scopeOf($target, $child, $childScope, $hasRows);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * What kind of name $name is, the name, and the schema that declares it: read
     * off the field of the node holding it.
     *
     * @param NameNode $name The innermost node over the offset.
     * @param array<int, Node> $parents Each node's parent, by object id.
     * @param CursorScope $scope The scope $name is written in.
     * @return array{0: ?SlotKind, 1: ?string, 2: ?string}
     */
    private static function slotOf(NameNode $name, array $parents, CursorScope $scope): array
    {
        $holder = $parents[spl_object_id($name)] ?? null;
        $here = $scope->schemaKey;
        $written = $name->name;

        if ($holder instanceof WithEntryNode) {
            $handle = $parents[spl_object_id($holder)] ?? null;

            return [SlotKind::ContextKey, $written, $handle?->schemaKey?->name ?? $here];
        }

        return match (true) {
            $holder instanceof ConditionNode && $name === $holder->conditionKey
                => [SlotKind::Condition, $written, $here],
            $holder instanceof CrossSchemaCanNode && $name === $holder->ability
                => [SlotKind::Ability, $written, $holder->schemaKey?->name ?? $here],
            ($holder instanceof CanClauseNode || $holder instanceof CannotClauseNode
                || $holder instanceof AbilityBlockNode || $holder instanceof IncludeInvocationNode)
                && in_array($name, $holder->abilities, true)
                => [SlotKind::Ability, $written, $here],
            $holder instanceof IncludeInvocationNode && $name === $holder->templateKey
                => [SlotKind::Template, $written, $here],
            ($holder instanceof RuleSetNode || $holder instanceof SchemaConditionNode
                || $holder instanceof CrossSchemaCanNode || $holder instanceof CrossSchemaConditionNode)
                && $name === $holder->schemaKey
                => [SlotKind::Schema, $written, null],
            ($holder instanceof CrossSchemaCanNode || $holder instanceof CrossSchemaConditionNode)
                && $name === $holder->alias
                => [SlotKind::Alias, $written, $holder->schemaKey?->name],
            $holder instanceof ContextRef
                => [SlotKind::ContextKey, $written, $here],
            $holder instanceof ColumnRef && $name === $holder->alias
                => [SlotKind::Alias, $written, $scope->frames[$written] ?? null],
            $holder instanceof ColumnRef && $name === $holder->column
                => [SlotKind::Column, $written, $holder->alias === null ? $scope->rowSchemaKey : ($scope->frames[$holder->alias->name] ?? null)],
            default => [null, null, null],
        };
    }
}
