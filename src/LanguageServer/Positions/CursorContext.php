<?php

namespace Warrant\LanguageServer\Positions;

use Closure;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Positions\SourceEntry;
use Warrant\DSL\Parsing\Positions\SourceMap;
use Warrant\DSL\SchemaCatalog;

/**
 * What is at one offset of parsed rule text, for an editor answering about it: the
 * node and part written there, what kind of name the part is, and what is in
 * scope there.
 *
 * A name the text is missing has no part, since nothing was written for it, so
 * an offset where one belongs is on the node around it with no slot. What was
 * written just before such an offset is in the tokens of the
 * {@see \Warrant\DSL\Parsing\AnalysisResult}.
 */
final readonly class CursorContext
{
    /**
     * @param list<SourceEntry> $entries Every node and part written over the
     *   offset, widest first; see {@see SourceMap::containing()}.
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
        public array $entries,
        public CursorScope $scope,
        public ?SlotKind $slot = null,
        public ?string $name = null,
        public ?string $nameSchemaKey = null,
    ) {
    }

    /**
     * The context at byte $offset of $syntax.
     *
     * @param WarrantSyntax $syntax The parsed text, with the positions its parse
     *   recorded.
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
        $entries = $syntax->positions?->containing($offset) ?? [];
        $innermost = $entries === [] ? null : $entries[array_key_last($entries)];

        $hasRows = static fn (string $key): bool => $catalog?->find($key)?->describesRows() ?? true;
        $headerless = CursorScope::root($schemaKey, $schemaKey !== null && $hasRows($schemaKey));

        $scope = null;

        if ($innermost !== null) {
            foreach ($syntax->children as $child) {
                $scope = self::scopeOf($innermost->node, $child, $headerless, $hasRows);

                if ($scope !== null) {
                    break;
                }
            }
        }

        $scope ??= $syntax->isSchemaScoped() ? new CursorScope : $headerless;

        [$slot, $name, $nameSchemaKey] = $innermost === null
            ? [null, null, null]
            : self::slotOf($innermost, $scope);

        return new self($entries, $scope, $slot, $name, $nameSchemaKey);
    }

    /**
     * The scope $target is written in, when it is $node or inside it; null when
     * it is not. A `for` body is its own scope, since what is written in it is
     * about the schema its header names.
     *
     * @param object $target The node to find.
     * @param mixed $node The node to look in, written in $scope; a value of any
     *   other kind holds no node.
     * @param CursorScope $scope The scope $node is written in.
     * @param Closure(string): bool $hasRows Whether the schema of a key has rows.
     */
    private static function scopeOf(object $target, mixed $node, CursorScope $scope, Closure $hasRows): ?CursorScope
    {
        if ($node instanceof ISchemaScopedNode) {
            $key = $node->schemaKey() === INode::MISSING_NAME ? null : $node->schemaKey();
            $scope = CursorScope::root($key, $key !== null && $hasRows($key));
        }

        if ($node === $target) {
            return $scope;
        }

        $inside = match (true) {
            $node instanceof RuleSetNode, $node instanceof AbilityBlockNode => $node->entries,
            $node instanceof SchemaConditionNode => [$node->expression],
            $node instanceof WarrantRuleNode => [$node->conditions, ...$node->canClauses, ...$node->cannotClauses],
            $node instanceof CanClauseNode, $node instanceof CannotClauseNode => [$node->message],
            $node instanceof IncludeInvocationNode => $node->arguments,
            $node instanceof AndNode, $node instanceof OrNode => [$node->leftSide, $node->rightSide],
            $node instanceof NotNode => [$node->operand],
            $node instanceof ConditionNode => $node->parameters,
            $node instanceof CrossSchemaCanNode, $node instanceof CrossSchemaConditionNode => [...$node->boundKey, ...$node->contextMap],
            default => [],
        };

        foreach ($inside as $child) {
            $found = self::scopeOf($target, $child, $scope, $hasRows);

            if ($found !== null) {
                return $found;
            }
        }

        if ($node instanceof CrossSchemaConditionNode) {
            $key = $node->schemaKey === INode::MISSING_NAME ? null : $node->schemaKey;

            return self::scopeOf(
                $target,
                $node->predicate,
                $scope->enteringPredicate($key, $node->alias, $key !== null && $hasRows($key)),
                $hasRows,
            );
        }

        return null;
    }

    /**
     * What kind of name $entry is, the name, and the schema that declares it.
     *
     * @param SourceEntry $entry The innermost node or part over the offset.
     * @param CursorScope $scope The scope $entry's node is written in.
     * @return array{0: ?SlotKind, 1: ?string, 2: ?string}
     */
    private static function slotOf(SourceEntry $entry, CursorScope $scope): array
    {
        $node = $entry->node;
        $here = $scope->schemaKey;

        return match (true) {
            $node instanceof ConditionNode && $entry->part === ConditionNode::PART_CONDITION_KEY
                => [SlotKind::Condition, $node->conditionKey, $here],
            $node instanceof CrossSchemaCanNode && $entry->part === CrossSchemaCanNode::PART_ABILITY
                => [SlotKind::Ability, $node->ability, $node->schemaKey ?? $here],
            ($node instanceof CanClauseNode || $node instanceof CannotClauseNode
                || $node instanceof AbilityBlockNode || $node instanceof IncludeInvocationNode)
                && $entry->part === $node::PART_ABILITIES
                => [SlotKind::Ability, $node->abilities[$entry->key], $here],
            $node instanceof IncludeInvocationNode && $entry->part === IncludeInvocationNode::PART_TEMPLATE_KEY
                => [SlotKind::Template, $node->templateKey, $here],
            ($node instanceof ISchemaScopedNode || $node instanceof CrossSchemaCanNode || $node instanceof CrossSchemaConditionNode)
                && $entry->part === $node::PART_SCHEMA_KEY
                => [SlotKind::Schema, $node instanceof ISchemaScopedNode ? $node->schemaKey() : $node->schemaKey, null],
            ($node instanceof CrossSchemaCanNode || $node instanceof CrossSchemaConditionNode)
                && $entry->part === $node::PART_ALIAS
                => [SlotKind::Alias, $node->alias, $node->schemaKey],
            ($node instanceof CrossSchemaCanNode || $node instanceof CrossSchemaConditionNode)
                && $entry->part === $node::PART_CONTEXT_MAP_KEY
                => [SlotKind::ContextKey, (string) $entry->key, $node->schemaKey ?? $here],
            $node instanceof ContextRef && $entry->part === ContextRef::PART_KEY
                => [SlotKind::ContextKey, $node->key, $here],
            $node instanceof ColumnRef && $entry->part === ColumnRef::PART_ALIAS
                => [SlotKind::Alias, $node->alias, $scope->frames[$node->alias] ?? null],
            $node instanceof ColumnRef && $entry->part === ColumnRef::PART_COLUMN
                => [SlotKind::Column, $node->column, $node->alias === null ? $scope->rowSchemaKey : ($scope->frames[$node->alias] ?? null)],
            default => [null, null, null],
        };
    }
}
