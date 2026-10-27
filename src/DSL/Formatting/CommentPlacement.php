<?php

namespace Warrant\DSL\Formatting;

use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\EveryAbilityExceptNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;

/**
 * Where each comment of a text goes when the text is formatted: before a node,
 * after one, or at the end of a body.
 *
 * The nodes a comment can stand beside are the ones the formatter may start a
 * line with or end one after: entries and `for` blocks, a rule's condition and
 * clauses, the operands of an `and` or `or` chain, arguments, abilities and
 * `with` entries.
 *
 * A comment with code before it on its line trails the outermost of those nodes
 * that ends just before it. Any other comment leads the first such node after
 * it within the innermost node holding it; with none after it there, it goes
 * to the end of the body holding it, or failing that leads the next node
 * further out. A comment after a `{` therefore leads the body, and one between
 * `}` and `otherwise` leads the `otherwise` body.
 */
final class CommentPlacement
{
    /** @var list<array{node: INode, start: int, end: int, depth: int}> */
    private array $anchors = [];

    /** @var array<int, list<array{text: string, blank: bool}>> By node id. */
    private array $leading = [];

    /** @var array<int, string> By node id. */
    private array $trailing = [];

    /** @var array<string, list<array{text: string, blank: bool}>> By body key. */
    private array $dangling = [];

    /** @var array<int, bool> Whether a node has a comment somewhere inside it, by node id. */
    private array $inside = [];

    /** @var array<int, int> Where each `otherwise` keyword starts, by when block id. */
    private array $otherwiseAt = [];

    /**
     * @param WarrantSyntax $syntax The text's tree.
     * @param list<Token> $tokens Every token of the text, comments included.
     * @param string $source The text.
     */
    public function __construct(private readonly WarrantSyntax $syntax, private readonly array $tokens, private readonly string $source)
    {
        foreach ($syntax->children as $child) {
            $this->register($child, 0);
        }

        $previous = null;
        $beforePrevious = null;
        $previousAny = null;

        foreach ($this->tokens as $token) {
            if ($token->type === TokenType::COMMENT) {
                /* A comment after a comma trails the item before the comma, which
                   is laid out with its comma after it. */
                $trails = $previous?->type === TokenType::COMMA && $beforePrevious !== null
                    ? [$previous->line, $beforePrevious->endOffset()]
                    : [$previous?->line, $previous?->endOffset()];
                $this->place($token, $trails, $previousAny);
            } elseif ($token->type !== TokenType::EOF) {
                $beforePrevious = $previous;
                $previous = $token;
            }

            $previousAny = $token;
        }
    }

    /**
     * The comments that go on lines of their own before $node, each with whether
     * a blank line was written before it.
     *
     * @return list<array{text: string, blank: bool}>
     */
    public function leadingOf(INode $node): array
    {
        return $this->leading[spl_object_id($node)] ?? [];
    }

    /**
     * The comment that goes at the end of $node's last line, if any.
     */
    public function trailingOf(INode $node): ?string
    {
        return $this->trailing[spl_object_id($node)] ?? null;
    }

    /**
     * Whether $node has a comment before it or after it.
     */
    public function isCommented(INode $node): bool
    {
        return isset($this->leading[spl_object_id($node)]) || isset($this->trailing[spl_object_id($node)]);
    }

    /**
     * The comments that end a body of $owner, after its last entry.
     *
     * @param INode|WarrantSyntax $owner The block, `for` block or whole text.
     * @param bool $otherwise Whether it is a when block's `otherwise` body.
     * @return list<array{text: string, blank: bool}>
     */
    public function danglingIn(INode|WarrantSyntax $owner, bool $otherwise = false): array
    {
        return $this->dangling[self::bodyKey($owner, $otherwise)] ?? [];
    }

    /**
     * Whether a comment sits anywhere inside $node, not counting the ones before
     * or after it.
     */
    public function hasCommentsInside(INode $node): bool
    {
        $id = spl_object_id($node);

        if (isset($this->inside[$id])) {
            return $this->inside[$id];
        }

        $inside = isset($this->dangling[self::bodyKey($node, false)]) || isset($this->dangling[self::bodyKey($node, true)]);

        foreach ($node->children() as $child) {
            if ($inside) {
                break;
            }

            $inside = $this->isCommented($child) || $this->hasCommentsInside($child);
        }

        return $this->inside[$id] = $inside;
    }

    private function register(INode $node, int $depth): void
    {
        $this->anchor($node, $depth);

        match (true) {
            $node instanceof RuleSetNode => $this->registerAll($node->entries, $depth + 1),
            $node instanceof SchemaConditionNode => $this->registerExpression($node->expression, $depth + 1, true),
            $node instanceof RuleNode => $this->registerRule($node, $depth + 1),
            $node instanceof AbilityBlockNode => $this->registerAll([...$node->abilities, ...$node->entries], $depth + 1),
            $node instanceof WhenBlockNode => $this->registerWhen($node, $depth + 1),
            $node instanceof IncludeInvocationNode => $this->registerAll([...$node->arguments, ...$node->abilities], $depth + 1),
            $node instanceof EveryAbilityExceptNode => $this->registerAll($node->exceptions, $depth + 1),
            $node instanceof IBooleanExpressionNode => $this->registerExpression($node, $depth, false),
            default => null,
        };
    }

    /**
     * @param list<INode> $nodes
     */
    private function registerAll(array $nodes, int $depth): void
    {
        foreach ($nodes as $node) {
            $this->register($node, $depth);
        }
    }

    private function registerRule(RuleNode $rule, int $depth): void
    {
        if ($rule->conditions !== null) {
            $this->registerExpression($rule->conditions, $depth, true);
        }

        foreach ([...$rule->canClauses, ...$rule->cannotClauses] as $clause) {
            $this->anchor($clause, $depth);
            $this->registerAll($clause->abilities, $depth + 1);
        }
    }

    private function registerWhen(WhenBlockNode $block, int $depth): void
    {
        $this->registerExpression($block->condition, $depth, true);
        $this->registerAll([...$block->entries, ...$block->otherwiseEntries ?? []], $depth);

        if ($block->otherwiseEntries !== null) {
            $after = self::spanOf($block->entries === [] ? $block->condition : $block->entries[array_key_last($block->entries)])[1];

            foreach ($this->tokens as $token) {
                if ($token->type === TokenType::OTHERWISE && $token->offset >= $after) {
                    $this->otherwiseAt[spl_object_id($block)] = $token->offset;
                    break;
                }
            }
        }
    }

    /**
     * Register the anchors inside $expression, and $expression itself when
     * $isAnchor: a condition a rule, block or `for` header holds.
     */
    private function registerExpression(IBooleanExpressionNode $expression, int $depth, bool $isAnchor): void
    {
        if ($isAnchor) {
            $this->anchor($expression, $depth);
        }

        if ($expression instanceof OrNode || $expression instanceof AndNode) {
            foreach (RuleTextLayout::chainOperands($expression) as $operand) {
                $this->anchor($operand, $depth + 1);
                $this->registerExpression($operand, $depth + 1, false);
            }
        } elseif ($expression instanceof NotNode) {
            $this->registerExpression($expression->operand, $depth + 1, false);
        } elseif ($expression instanceof ConditionNode) {
            $this->registerAll($expression->parameters, $depth + 1);
        } elseif ($expression instanceof CrossSchemaCanNode) {
            $this->registerAll([...$expression->boundKey, ...$expression->contextMap], $depth + 1);
        } elseif ($expression instanceof CrossSchemaConditionNode) {
            $this->registerExpression($expression->predicate, $depth + 1, true);
            $this->registerAll([...$expression->boundKey, ...$expression->contextMap], $depth + 1);
        }
    }

    private function anchor(INode $node, int $depth): void
    {
        [$start, $end] = self::spanOf($node);
        $this->anchors[] = ['node' => $node, 'start' => $start, 'end' => $end, 'depth' => $depth];
    }

    /**
     * @param array{0: ?int, 1: ?int} $trails The line of the last token before
     *   the comment that is not one, and where the code the comment would trail
     *   ends.
     * @param Token|null $previousAny The token just before the comment.
     */
    private function place(Token $comment, array $trails, ?Token $previousAny): void
    {
        $text = rtrim($comment->lexeme);
        $blank = $previousAny !== null
            && substr_count(substr($this->source, $previousAny->endOffset(), $comment->offset - $previousAny->endOffset()), "\n") >= 2;
        [$line, $end] = $trails;

        if ($line === $comment->line) {
            $trailed = $this->outermost(static fn (array $anchor): bool => $anchor['end'] === $end);

            if ($trailed !== null && ! isset($this->trailing[spl_object_id($trailed)])) {
                $this->trailing[spl_object_id($trailed)] = $text;

                return;
            }
        }

        $at = $comment->offset;
        $holders = array_filter($this->anchors, static fn (array $anchor): bool => $anchor['start'] < $at && $at < $anchor['end']);
        usort($holders, static fn (array $a, array $b): int => ($a['end'] - $a['start']) <=> ($b['end'] - $b['start']));

        foreach ($holders as $holder) {
            $led = $this->outermost(static fn (array $anchor): bool => $anchor['start'] > $at && $anchor['end'] <= $holder['end']);

            if ($led !== null) {
                $this->leading[spl_object_id($led)][] = ['text' => $text, 'blank' => $blank];

                return;
            }

            $node = $holder['node'];

            if ($node instanceof RuleSetNode || $node instanceof AbilityBlockNode || $node instanceof WhenBlockNode) {
                $otherwise = $node instanceof WhenBlockNode && $at > ($this->otherwiseAt[spl_object_id($node)] ?? PHP_INT_MAX);
                $this->dangling[self::bodyKey($node, $otherwise)][] = ['text' => $text, 'blank' => $blank];

                return;
            }
        }

        $led = $this->outermost(static fn (array $anchor): bool => $anchor['start'] > $at);

        if ($led !== null) {
            $this->leading[spl_object_id($led)][] = ['text' => $text, 'blank' => $blank];

            return;
        }

        $this->dangling[self::bodyKey($this->syntax, false)][] = ['text' => $text, 'blank' => $blank];
    }

    /**
     * Of the anchors $matches accepts, the one starting first, and of those the
     * outermost.
     *
     * @param callable(array{node: INode, start: int, end: int, depth: int}): bool $matches
     */
    private function outermost(callable $matches): ?INode
    {
        $best = null;

        foreach ($this->anchors as $anchor) {
            if (! $matches($anchor)) {
                continue;
            }

            if ($best === null || [$anchor['start'], $anchor['depth']] < [$best['start'], $best['depth']]) {
                $best = $anchor;
            }
        }

        return $best['node'] ?? null;
    }

    private static function bodyKey(INode|WarrantSyntax $owner, bool $otherwise): string
    {
        return spl_object_id($owner).($otherwise ? ':otherwise' : ':main');
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function spanOf(INode $node): array
    {
        /** @var \Warrant\DSL\Parsing\Positions\SyntaxLocation $location */
        $location = $node->location;
        $span = $location->span;

        return [$span?->offset ?? 0, $span?->endOffset ?? 0];
    }
}
