<?php

namespace Warrant\DSL\Formatting;

use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\CannotClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\ISchemaScopedNode;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;

/**
 * Lays a parsed text out in the house style, which `specs/formatter.md` sets out
 * rule by rule.
 *
 * Each construct is laid out flat when it fits in the rest of its line, and
 * broken into lines otherwise; a construct breaks before the ones inside it, so
 * an inner one breaks only when it still does not fit. Every method that lays
 * a construct out is told the column it starts at and the indentation of the
 * line it starts on, and answers {@see LaidOutLines}.
 *
 * What the text says comes from the tree. The written layout matters only
 * where the style keeps it: blank lines, comments, whether a block was written
 * on one line, and whether a single rule set was braced.
 */
final class RuleTextLayout
{
    /** How wide a line may be, from the rule text's left edge. */
    public const WIDTH = 80;

    /** The spaces in one level of indentation. */
    private const INDENT = 4;

    /** @var array<int, Token> Every token, comments included, by where it starts. */
    private array $tokenStartingAt = [];

    /** @var array<int, Token> The token before each one, comments included, by where the later one starts. */
    private array $tokenBefore = [];

    /** @var array<int, Token> The token after each one that is not a comment, by where the earlier one ends. */
    private array $tokenAfter = [];

    private readonly CommentPlacement $comments;

    /**
     * @param string $source The text.
     * @param WarrantSyntax $syntax Its tree, read with no syntax errors.
     * @param list<Token> $tokens Every token of the text, comments included.
     * @param bool $onOneLine Whether to lay the whole text out on one line, as a
     *   quoted PHP string holds it, with no width to fit in. The text must have
     *   no comments.
     */
    public function __construct(
        private readonly string $source,
        private readonly WarrantSyntax $syntax,
        array $tokens,
        private readonly bool $onOneLine = false,
    ) {
        $this->comments = new CommentPlacement($syntax, $tokens, $source);

        $previous = null;
        $previousCode = null;

        foreach ($tokens as $token) {
            $this->tokenStartingAt[$token->offset] = $token;

            if ($previous !== null) {
                $this->tokenBefore[$token->offset] = $previous;
            }

            if ($token->type !== TokenType::COMMENT) {
                if ($previousCode !== null) {
                    $this->tokenAfter[$previousCode->endOffset()] = $token;
                }

                $previousCode = $token;
            }

            $previous = $token;
        }
    }

    /**
     * The formatted text, ending in a line break unless it is empty or laid out
     * on one line.
     */
    public function layout(): string
    {
        $children = $this->syntax->children;

        if ($this->onOneLine) {
            return match (true) {
                $children === [] => '',
                $children[0] instanceof ISchemaScopedNode => implode(' ', array_map(fn (ISchemaScopedNode $block): string => $this->flatSchemaBlock($block, count($children) > 1), $children)),
                $children[0] instanceof IRuleEntryNode => (string) $this->flatEntries($children),
                default => (string) $this->flatExpression($children[0]),
            };
        }

        $lines = match (true) {
            $children === [] => null,
            $children[0] instanceof ISchemaScopedNode => $this->schemaBlocks($children),
            $children[0] instanceof IRuleEntryNode => $this->body($children, $this->syntax, false, 0),
            default => $this->commented($children[0], $this->expression($children[0], 0, 0, 0, true)),
        };

        if ($children === [] || ! $children[0] instanceof IRuleEntryNode) {
            $lines = $this->withDangling($lines, $this->syntax, false);
        }

        return $lines === null ? '' : $lines->render()."\n";
    }

    /**
     * The operands of an `and` or `or` chain, left to right: the chain read as
     * one, so `a or b or c` answers three, while an operand of the same kind
     * written in parentheses on the right stays one operand.
     *
     * @return list<IBooleanExpressionNode>
     */
    public static function chainOperands(OrNode|AndNode $chain): array
    {
        $left = $chain->leftSide;

        return [
            ...($left::class === $chain::class ? self::chainOperands($left) : [$left]),
            $chain->rightSide,
        ];
    }

    // -------------------------------------------------------------------------
    // Bodies and entries
    // -------------------------------------------------------------------------

    /**
     * Top-level `for` blocks, with one blank line between each.
     *
     * @param list<ISchemaScopedNode> $blocks
     */
    private function schemaBlocks(array $blocks): LaidOutLines
    {
        $lines = null;

        foreach ($blocks as $block) {
            [, $leading] = $this->leadingOfEntry($block);
            $laid = $block instanceof RuleSetNode
                ? $this->ruleSet($block, count($blocks) === 1)
                : $this->schemaCondition($block, count($blocks) > 1);
            $laid = $this->trailed($block, $laid)->lead($leading);

            $lines === null ? $lines = $laid : $lines->addBlankLine()->addLine($laid, 0);
        }

        return $lines;
    }

    /**
     * Entries one to a line at column $column, with the blank lines written
     * between them and the comments that end the body.
     *
     * @param list<IRuleEntryNode> $entries
     * @param INode|WarrantSyntax $owner What holds the body.
     * @param bool $otherwise Whether it is a when block's `otherwise` body.
     */
    private function body(array $entries, INode|WarrantSyntax $owner, bool $otherwise, int $column): ?LaidOutLines
    {
        $lines = null;

        foreach ($entries as $entry) {
            [$blank, $leading] = $this->leadingOfEntry($entry);
            $laid = $this->trailed($entry, $this->entry($entry, $column))->lead($leading);

            if ($lines === null) {
                $lines = $laid;

                continue;
            }

            if ($blank) {
                $lines->addBlankLine();
            }

            $lines->addLine($laid, 0);
        }

        return $this->withDangling($lines, $owner, $otherwise);
    }

    /**
     * $lines followed by the comments that end a body of $owner.
     */
    private function withDangling(?LaidOutLines $lines, INode|WarrantSyntax $owner, bool $otherwise): ?LaidOutLines
    {
        foreach ($this->comments->danglingIn($owner, $otherwise) as $comment) {
            if ($lines === null) {
                $lines = LaidOutLines::of($comment['text']);

                continue;
            }

            if ($comment['blank']) {
                $lines->addBlankLine();
            }

            $lines->addLine(LaidOutLines::of($comment['text']), 0);
        }

        return $lines;
    }

    private function entry(IRuleEntryNode $entry, int $column): LaidOutLines
    {
        return match (true) {
            $entry instanceof RuleNode => $this->rule($entry, $column),
            $entry instanceof AbilityBlockNode => $this->abilityBlock($entry, $column),
            $entry instanceof WhenBlockNode => $this->whenBlock($entry, $column),
            $entry instanceof IncludeInvocationNode => $this->include($entry, $column),
        };
    }

    /**
     * Whether a blank line goes before $entry and the comments leading it, and
     * the lines of those comments, with the blank lines written among them and
     * after them.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private function leadingOfEntry(INode $entry): array
    {
        $comments = $this->comments->leadingOf($entry);
        $blankBeforeEntry = $this->blankLineBefore(CommentPlacement::spanOf($entry)[0]);

        if ($comments === []) {
            return [$blankBeforeEntry, []];
        }

        $lines = [];

        foreach ($comments as $index => $comment) {
            if ($index > 0 && $comment['blank']) {
                $lines[] = '';
            }

            $lines[] = $comment['text'];
        }

        if ($blankBeforeEntry) {
            $lines[] = '';
        }

        return [$comments[0]['blank'], $lines];
    }

    /**
     * Whether the source has a blank line just before the token at $offset.
     */
    private function blankLineBefore(int $offset): bool
    {
        $before = $this->tokenBefore[$offset] ?? null;

        return $before !== null
            && substr_count(substr($this->source, $before->endOffset(), $offset - $before->endOffset()), "\n") >= 2;
    }

    /**
     * $lines with the comments before and after $node, which is not an entry.
     */
    private function commented(INode $node, LaidOutLines $lines): LaidOutLines
    {
        $lines->lead(array_map(static fn (array $comment): string => $comment['text'], $this->comments->leadingOf($node)));

        return $this->trailed($node, $lines);
    }

    private function trailed(INode $node, LaidOutLines $lines): LaidOutLines
    {
        $trailing = $this->comments->trailingOf($node);

        return $trailing === null ? $lines : $lines->trail($trailing);
    }

    // -------------------------------------------------------------------------
    // `for` blocks
    // -------------------------------------------------------------------------

    /**
     * @param bool $alone Whether it is the text's only block, so it may be unbraced.
     */
    private function ruleSet(RuleSetNode $set, bool $alone): LaidOutLines
    {
        $header = 'for '.$set->schemaKey->name;

        if (! $alone || $this->isBraced($set)) {
            $oneLine = $this->oneLineBlock($set, $header, [[' ', $set->entries]], 0);

            return $oneLine !== null
                ? LaidOutLines::of($oneLine)
                : $this->bracedBody($set, LaidOutLines::of($header), false, $set->entries, 0);
        }

        $lines = LaidOutLines::of($header);
        $body = $this->body($set->entries, $set, false, 0);

        if ($body !== null) {
            if ($set->entries !== [] && $this->leadingOfEntry($set->entries[0])[0]) {
                $lines->addBlankLine();
            }

            $lines->addLine($body, 0);
        }

        return $lines;
    }

    /**
     * @param bool $braced Whether it stands beside other blocks, where the grammar needs braces.
     */
    private function schemaCondition(SchemaConditionNode $block, bool $braced): LaidOutLines
    {
        $condition = $this->commented($block->expression, $this->expression($block->expression, self::INDENT, self::INDENT, 0, true));
        $lines = LaidOutLines::of('for '.$block->schemaKey->name.($braced ? ' {' : ''))->addLine($condition, self::INDENT);

        return $braced ? $lines->addLine(LaidOutLines::of('}'), 0) : $lines;
    }

    private function isBraced(RuleSetNode $set): bool
    {
        return ($this->tokenAfter[CommentPlacement::spanOf($set->schemaKey)[1]] ?? null)?->type === TokenType::LBRACE;
    }

    private function flatSchemaBlock(ISchemaScopedNode $block, bool $braced): string
    {
        $header = 'for '.$block->schemaKey().' ';

        if ($block instanceof SchemaConditionNode) {
            return $header.($braced ? '{ '.$this->flatExpression($block->expression).' }' : $this->flatExpression($block->expression));
        }

        $entries = $this->flatEntries($block->entries);

        if (! $braced && ! $this->isBraced($block)) {
            return rtrim($header.$entries);
        }

        return $header.($block->entries === [] ? '{}' : '{ '.$entries.' }');
    }

    // -------------------------------------------------------------------------
    // Blocks
    // -------------------------------------------------------------------------

    private function abilityBlock(AbilityBlockNode $block, int $column): LaidOutLines
    {
        $header = 'can they '.implode(', ', array_map($this->abilityText(...), $block->abilities));
        $oneLine = $this->oneLineBlock($block, $header, [[' ', $block->entries]], $column);

        if ($oneLine !== null) {
            return LaidOutLines::of($oneLine);
        }

        if (! $this->anyCommented($block->abilities) && $this->fits($column, $header.' {', 0)) {
            return $this->bracedBody($block, LaidOutLines::of($header), false, $block->entries, $column);
        }

        return $this->bracedBody($block, $this->brokenList('can they', $block->abilities, ''), true, $block->entries, $column);
    }

    private function whenBlock(WhenBlockNode $block, int $column): LaidOutLines
    {
        $condition = $this->flatExpression($block->condition);
        $bodies = [[' ', $block->entries], ...($block->otherwiseEntries === null ? [] : [[' otherwise ', $block->otherwiseEntries]])];

        if ($condition !== null && ($oneLine = $this->oneLineBlock($block, 'when '.$condition, $bodies, $column)) !== null) {
            return LaidOutLines::of($oneLine);
        }

        $headerBroken = $condition === null
            || $this->comments->trailingOf($block->condition) !== null
            || ! $this->fits($column, 'when '.$condition.' {', 0);

        $lines = $headerBroken
            ? LaidOutLines::of('when ')->appendLines($this->commented($block->condition, $this->expression($block->condition, $column + 5, $column, 0, true)))
            : $this->commented($block->condition, LaidOutLines::of('when '.$condition));

        $main = $this->body($block->entries, $block, false, $column + self::INDENT);
        $this->openBody($lines, $headerBroken, $main);

        if ($block->otherwiseEntries === null) {
            return $main === null ? $lines : $lines->addLine(LaidOutLines::of('}'), 0);
        }

        $otherwise = $this->body($block->otherwiseEntries, $block, true, $column + self::INDENT);
        $keyword = ' otherwise '.($otherwise === null ? '{}' : '{');
        $main === null ? $lines->append($keyword) : $lines->addLine(LaidOutLines::of('}'.$keyword), 0);

        return $otherwise === null
            ? $lines
            : $lines->addLine($otherwise, self::INDENT)->addLine(LaidOutLines::of('}'), 0);
    }

    /**
     * $header, then a braced body of $entries one level in.
     *
     * @param bool $headerBroken Whether the header took more than one line, so
     *   the `{` goes on a line of its own.
     * @param list<IRuleEntryNode> $entries
     */
    private function bracedBody(INode $owner, LaidOutLines $header, bool $headerBroken, array $entries, int $column): LaidOutLines
    {
        $body = $this->body($entries, $owner, false, $column + self::INDENT);
        $this->openBody($header, $headerBroken, $body);

        return $body === null ? $header : $header->addLine(LaidOutLines::of('}'), 0);
    }

    /**
     * Add the `{` that opens a body to $header, and the body after it; an empty
     * body is `{}`.
     */
    private function openBody(LaidOutLines $header, bool $headerBroken, ?LaidOutLines $body): void
    {
        $brace = $body === null ? '{}' : '{';
        $headerBroken ? $header->addLine(LaidOutLines::of($brace), 0) : $header->append(' '.$brace);

        if ($body !== null) {
            $header->addLine($body, self::INDENT);
        }
    }

    /**
     * The block on one line, when it was written on one line, holds at most one
     * rule and fits; otherwise null.
     *
     * @param list<array{0: string, 1: list<IRuleEntryNode>}> $bodies Each body,
     *   with what goes before its `{`.
     * @param int|null $column Where it starts, or null not to check it fits.
     */
    private function oneLineBlock(INode $block, string $header, array $bodies, ?int $column): ?string
    {
        if (! $this->onOneLine && (
            str_contains($this->writtenText($block), "\n")
            || $this->comments->hasCommentsInside($block)
            || $this->ruleCount($block) > 1
        )) {
            return null;
        }

        $text = $header;

        foreach ($bodies as [$before, $entries]) {
            if ($entries === []) {
                $text .= $before.'{}';

                continue;
            }

            $flat = $this->flatEntries($entries);

            if ($flat === null) {
                return null;
            }

            $text .= $before.'{ '.$flat.' }';
        }

        return $column === null || $this->fits($column, $text, 0) ? $text : null;
    }

    /**
     * How many rules and includes $block holds, in all its bodies, at any depth.
     */
    private function ruleCount(INode $block): int
    {
        $count = 0;

        foreach ($block->children() as $child) {
            $count += match (true) {
                $child instanceof RuleNode, $child instanceof IncludeInvocationNode => 1,
                $child instanceof AbilityBlockNode, $child instanceof WhenBlockNode => $this->ruleCount($child),
                default => 0,
            };
        }

        return $count;
    }

    /**
     * $entries on one line, or null when they cannot be: on one line only one
     * entry may stand, unless the whole text is laid out on one line.
     *
     * @param list<IRuleEntryNode> $entries
     */
    private function flatEntries(array $entries): ?string
    {
        if (! $this->onOneLine && count($entries) > 1) {
            return null;
        }

        $flat = [];

        foreach ($entries as $entry) {
            $text = match (true) {
                $entry instanceof RuleNode => $this->flatRule($entry),
                $entry instanceof IncludeInvocationNode => $this->flatInclude($entry),
                $entry instanceof AbilityBlockNode => $this->oneLineBlock($entry, 'can they '.implode(', ', array_map($this->abilityText(...), $entry->abilities)), [[' ', $entry->entries]], null),
                $entry instanceof WhenBlockNode => $this->flatWhenBlock($entry),
            };

            if ($text === null) {
                return null;
            }

            $flat[] = $text;
        }

        return implode(' ', $flat);
    }

    private function flatWhenBlock(WhenBlockNode $block): ?string
    {
        $condition = $this->flatExpression($block->condition);

        return $condition === null ? null : $this->oneLineBlock(
            $block,
            'when '.$condition,
            [[' ', $block->entries], ...($block->otherwiseEntries === null ? [] : [[' otherwise ', $block->otherwiseEntries]])],
            null,
        );
    }

    // -------------------------------------------------------------------------
    // Rules and includes
    // -------------------------------------------------------------------------

    private function rule(RuleNode $rule, int $column): LaidOutLines
    {
        $flat = $this->flatRule($rule);

        if ($flat !== null && $this->fits($column, $flat, 0)) {
            return LaidOutLines::of($flat);
        }

        $lines = $rule->conditions === null ? null : LaidOutLines::of('if ')->appendLines(
            $this->commented($rule->conditions, $this->expression($rule->conditions, $column + 3, $column, 0, true)),
        );

        foreach ($this->clausesOf($rule) as $clause) {
            $laid = $this->commented($clause, $this->clause($clause, $column));
            $lines === null ? $lines = $laid : $lines->addLine($laid, 0);
        }

        return $lines;
    }

    /**
     * $rule on one line, or null when it cannot be. A rule takes several lines
     * when it has more than one clause, or when its condition is more than one
     * plain condition, unless the whole text is laid out on one line.
     */
    private function flatRule(RuleNode $rule): ?string
    {
        $clauses = $this->clausesOf($rule);
        $takesLines = count($clauses) > 1 || ($rule->conditions !== null && ! self::isPlainCondition($rule->conditions));

        if ($this->comments->hasCommentsInside($rule) || (! $this->onOneLine && $takesLines)) {
            return null;
        }

        $flat = array_map(fn (CanClauseNode|CannotClauseNode $clause): string => $this->flatClause($clause), $clauses);

        return ($rule->conditions === null ? '' : 'if '.$this->flatExpression($rule->conditions).' ').implode(' ', $flat);
    }

    /**
     * Whether $condition is one plain condition, which a rule may share a line
     * with: a condition of this schema, with or without arguments, `can` on an
     * ability of this schema, or `not` before either. A chain of `and` or `or`,
     * a `can(...)` that crosses to another schema, and a `check(...)` are not.
     */
    private static function isPlainCondition(IBooleanExpressionNode $condition): bool
    {
        return match (true) {
            $condition instanceof NotNode => self::isPlainCondition($condition->operand),
            $condition instanceof CrossSchemaCanNode => $condition->schemaKey === null && $condition->contextMap === [],
            $condition instanceof OrNode, $condition instanceof AndNode, $condition instanceof CrossSchemaConditionNode => false,
            default => true,
        };
    }

    /**
     * A rule's clauses in the order they were written.
     *
     * @return list<CanClauseNode|CannotClauseNode>
     */
    private function clausesOf(RuleNode $rule): array
    {
        $clauses = [...$rule->canClauses, ...$rule->cannotClauses];
        usort($clauses, static fn (INode $a, INode $b): int => CommentPlacement::spanOf($a)[0] <=> CommentPlacement::spanOf($b)[0]);

        return $clauses;
    }

    private function clause(CanClauseNode|CannotClauseNode $clause, int $column): LaidOutLines
    {
        $flat = $this->flatClause($clause);

        if ($clause->abilities === [] || (! $this->comments->hasCommentsInside($clause) && (count($clause->abilities) === 1 || $this->fits($column, $flat, 0)))) {
            return LaidOutLines::of($flat);
        }

        return $this->brokenList($this->clauseHead($clause), $clause->abilities, $this->because($clause));
    }

    private function flatClause(CanClauseNode|CannotClauseNode $clause): string
    {
        $abilities = implode(', ', array_map($this->abilityText(...), $clause->abilities));

        return $this->clauseHead($clause).($abilities === '' ? '' : ' '.$abilities).$this->because($clause);
    }

    private function clauseHead(CanClauseNode|CannotClauseNode $clause): string
    {
        return $clause instanceof CannotClauseNode ? 'they cannot' : 'they can';
    }

    private function because(CanClauseNode|CannotClauseNode $clause): string
    {
        $message = $clause->message;

        return $message === null ? '' : ' because '.$this->argumentText($message);
    }

    /**
     * $head, then $items one per line one level in, each but the last followed
     * by a comma and the last by $after.
     *
     * @param list<INode> $items
     */
    private function brokenList(string $head, array $items, string $after): LaidOutLines
    {
        $lines = LaidOutLines::of($head);
        $last = array_key_last($items);

        foreach ($items as $index => $item) {
            $text = $item instanceof WithEntryNode ? $this->withEntryText($item) : $this->argumentText($item);
            $lines->addLine($this->commented($item, LaidOutLines::of($text.($index === $last ? $after : ','))), self::INDENT);
        }

        return $lines;
    }

    private function include(IncludeInvocationNode $include, int $column): LaidOutLines
    {
        $name = '@include '.$include->templateKey->name;
        $head = $include->arguments === [] ? $name : $name.'('.implode(', ', array_map($this->argumentText(...), $include->arguments)).')';
        $abilities = implode(', ', array_map($this->abilityText(...), $include->abilities));
        $for = $abilities === '' ? '' : 'for '.$abilities;
        $argumentsCommented = $this->anyCommented($include->arguments);
        $abilitiesCommented = $this->anyCommented($include->abilities);

        if (! $argumentsCommented && ! $abilitiesCommented && $this->fits($column, rtrim($head.' '.$for), 0)) {
            return LaidOutLines::of(rtrim($head.' '.$for));
        }

        // The `for` list breaks one ability to a line when it does not fit, as a clause's does.
        $listBreaks = static fn (bool $fits): bool => $abilitiesCommented || (count($include->abilities) > 1 && ! $fits);

        // `for` stays on the `@include` line when it fits there, its abilities below it.
        if (! $argumentsCommented && ($include->arguments === [] || $this->fits($column, $head.($for === '' ? '' : ' for'), 0))) {
            if ($for === '') {
                return LaidOutLines::of($head);
            }

            return $listBreaks(false)
                ? LaidOutLines::of($head.' ')->appendLines($this->brokenList('for', $include->abilities, ''))
                : LaidOutLines::of($head.' '.$for);
        }

        $lines = $this->brokenCall($name, $include->arguments);

        if ($for === '') {
            return $lines;
        }

        return $listBreaks($this->fits($column, ') '.$for, 0))
            ? $lines->append(' ')->appendLines($this->brokenList('for', $include->abilities, ''))
            : $lines->append(' '.$for);
    }

    private function flatInclude(IncludeInvocationNode $include): ?string
    {
        if ($this->comments->hasCommentsInside($include)) {
            return null;
        }

        $name = '@include '.$include->templateKey->name;
        $head = $include->arguments === [] ? $name : $name.'('.implode(', ', array_map($this->argumentText(...), $include->arguments)).')';
        $abilities = implode(', ', array_map($this->abilityText(...), $include->abilities));

        return $abilities === '' ? $head : $head.' for '.$abilities;
    }

    // -------------------------------------------------------------------------
    // Expressions
    // -------------------------------------------------------------------------

    /**
     * $expression laid out from column $column, on a line indented $indent,
     * with $trail characters to follow it on its last line.
     *
     * @param bool $hanging Whether a chain's later operands go one level past
     *   its first, as everywhere but directly inside parentheses.
     */
    private function expression(IBooleanExpressionNode $expression, int $column, int $indent, int $trail, bool $hanging): LaidOutLines
    {
        $flat = $this->flatExpression($expression);

        if ($flat !== null && $this->fits($column, $flat, $trail)) {
            return LaidOutLines::of($flat);
        }

        return match (true) {
            $expression instanceof OrNode, $expression instanceof AndNode => $this->chain($expression, $column, $indent, $trail, $hanging),
            $expression instanceof NotNode => LaidOutLines::of('not ')->appendLines($this->operand($expression->operand, $column + 4, $indent, $trail)),
            $expression instanceof ConditionNode => $expression->parameters === []
                ? LaidOutLines::of($expression->conditionKey->name)
                : $this->call($expression->conditionKey->name, $expression->parameters, $column, $trail),
            $expression instanceof CrossSchemaCanNode && ($expression->schemaKey !== null || $expression->contextMap !== []) => $this->can($expression, $indent),
            $expression instanceof CrossSchemaConditionNode => $this->check($expression, $indent),
            default => LaidOutLines::of($flat ?? $this->expressionText($expression)),
        };
    }

    private function chain(OrNode|AndNode $chain, int $column, int $indent, int $trail, bool $hanging): LaidOutLines
    {
        $operator = $chain instanceof OrNode ? 'or' : 'and';
        $operands = self::chainOperands($chain);
        $last = array_key_last($operands);
        $lines = $this->commented($operands[0], $this->operand($operands[0], $column, $indent, 0));

        if (count($operands) === 2 && ! $lines->isOneLine() && ! $lines->endsWithComment()) {
            $lineIndent = $indent + $lines->lastLineIndent();
            $end = $indent + $lines->lastLineWidth() + strlen($operator) + 2;

            return $lines->append(' '.$operator.' ')->appendLines(
                $this->commented($operands[1], $this->operand($operands[1], $end, $lineIndent, $trail)),
            );
        }

        $pad = $hanging ? self::INDENT : 0;

        foreach (array_slice($operands, 1, null, true) as $index => $operand) {
            $lineIndent = $indent + $pad;
            $laid = $this->operand($operand, $lineIndent + strlen($operator) + 1, $lineIndent, $index === $last ? $trail : 0);
            $lines->addLine(LaidOutLines::of($operator.' ')->appendLines($this->commented($operand, $laid)), $pad);
        }

        return $lines;
    }

    /**
     * An operand of a chain or of `not`, in parentheses when it is a chain itself.
     */
    private function operand(IBooleanExpressionNode $operand, int $column, int $indent, int $trail): LaidOutLines
    {
        if (! $operand instanceof OrNode && ! $operand instanceof AndNode) {
            return $this->expression($operand, $column, $indent, $trail, true);
        }

        $flat = $this->flatExpression($operand);

        if ($flat !== null && $this->fits($column, '('.$flat.')', $trail)) {
            return LaidOutLines::of('('.$flat.')');
        }

        return LaidOutLines::of('(')
            ->addLine($this->expression($operand, $indent + self::INDENT, $indent + self::INDENT, 0, false), self::INDENT)
            ->addLine(LaidOutLines::of(')'), 0);
    }

    /**
     * `name(arguments)` on one line when it fits, and otherwise broken one
     * argument to a line.
     *
     * @param list<INode> $arguments
     */
    private function call(string $name, array $arguments, int $column, int $trail): LaidOutLines
    {
        $flat = $name.'('.implode(', ', array_map($this->argumentText(...), $arguments)).')';

        return ! $this->anyCommented($arguments) && $this->fits($column, $flat, $trail)
            ? LaidOutLines::of($flat)
            : $this->brokenCall($name, $arguments);
    }

    /**
     * @param list<INode> $arguments
     */
    private function brokenCall(string $name, array $arguments): LaidOutLines
    {
        return $this->brokenList($name.'(', $arguments, '')->addLine(LaidOutLines::of(')'), 0);
    }

    private function can(CrossSchemaCanNode $can, int $indent): LaidOutLines
    {
        $lines = LaidOutLines::of('can(')->addLine(LaidOutLines::of($this->abilityText($can->ability)), self::INDENT);

        return $this->crossSchemaParts($lines, $can->schemaKey, $can->isRowBound, $can->boundKey, $can->alias, $can->contextMap, $indent);
    }

    private function check(CrossSchemaConditionNode $check, int $indent): LaidOutLines
    {
        $inner = $indent + self::INDENT;
        $lines = LaidOutLines::of('check(')->addLine(
            $this->commented($check->predicate, $this->expression($check->predicate, $inner, $inner, 0, true)),
            self::INDENT,
        );

        return $this->crossSchemaParts($lines, $check->schemaKey, $check->isRowBound, $check->boundKey, $check->alias, $check->contextMap, $indent);
    }

    /**
     * The `for` handle and `with` map of a broken `can(...)` or `check(...)`,
     * each on a line of its own, and its closing `)`.
     *
     * @param list<INode> $boundKey
     * @param list<WithEntryNode> $contextMap
     */
    private function crossSchemaParts(LaidOutLines $lines, ?NameNode $schemaKey, bool $isRowBound, array $boundKey, ?NameNode $alias, array $contextMap, int $indent): LaidOutLines
    {
        $inner = $indent + self::INDENT;

        if ($schemaKey !== null) {
            $alias = $alias === null ? '' : ' as '.$alias->name;
            $handle = $isRowBound ? $this->call($schemaKey->name, $boundKey, $inner + 4, strlen($alias)) : LaidOutLines::of($schemaKey->name);
            $lines->addLine(LaidOutLines::of('for ')->appendLines($handle)->append($alias), self::INDENT);
        }

        if ($contextMap !== []) {
            $flat = 'with '.implode(', ', array_map($this->withEntryText(...), $contextMap));
            $with = ! $this->anyCommented($contextMap) && $this->fits($inner, $flat, 0)
                ? LaidOutLines::of($flat)
                : $this->brokenList('with', $contextMap, '');
            $lines->addLine($with, self::INDENT);
        }

        return $lines->addLine(LaidOutLines::of(')'), 0);
    }

    /**
     * $expression on one line, or null when a comment inside it means it cannot be.
     */
    private function flatExpression(IBooleanExpressionNode $expression): ?string
    {
        return $this->comments->hasCommentsInside($expression) ? null : $this->expressionText($expression);
    }

    private function expressionText(IBooleanExpressionNode $expression): string
    {
        return match (true) {
            $expression instanceof OrNode, $expression instanceof AndNode => implode(
                $expression instanceof OrNode ? ' or ' : ' and ',
                array_map($this->operandText(...), self::chainOperands($expression)),
            ),
            $expression instanceof NotNode => 'not '.$this->operandText($expression->operand),
            $expression instanceof ConditionNode => $expression->parameters === []
                ? $expression->conditionKey->name
                : $expression->conditionKey->name.'('.implode(', ', array_map($this->argumentText(...), $expression->parameters)).')',
            $expression instanceof CrossSchemaCanNode => $this->canText($expression),
            $expression instanceof CrossSchemaConditionNode => 'check('.$this->expressionText($expression->predicate)
                .$this->crossSchemaText($expression->schemaKey, $expression->isRowBound, $expression->boundKey, $expression->alias, $expression->contextMap).')',
            default => $this->writtenText($expression),
        };
    }

    private function operandText(IBooleanExpressionNode $operand): string
    {
        $text = $this->expressionText($operand);

        return $operand instanceof OrNode || $operand instanceof AndNode ? '('.$text.')' : $text;
    }

    private function canText(CrossSchemaCanNode $can): string
    {
        $ability = $this->abilityText($can->ability);

        if ($can->schemaKey === null && $can->contextMap === []) {
            return 'can '.$ability;
        }

        return 'can('.$ability.$this->crossSchemaText($can->schemaKey, $can->isRowBound, $can->boundKey, $can->alias, $can->contextMap).')';
    }

    /**
     * @param list<INode> $boundKey
     * @param list<WithEntryNode> $contextMap
     */
    private function crossSchemaText(?NameNode $schemaKey, bool $isRowBound, array $boundKey, ?NameNode $alias, array $contextMap): string
    {
        $text = '';

        if ($schemaKey !== null) {
            $text .= ' for '.$schemaKey->name;
            $text .= $isRowBound ? '('.implode(', ', array_map($this->argumentText(...), $boundKey)).')' : '';
            $text .= $alias === null ? '' : ' as '.$alias->name;
        }

        if ($contextMap !== []) {
            $text .= ' with '.implode(', ', array_map($this->withEntryText(...), $contextMap));
        }

        return $text;
    }

    // -------------------------------------------------------------------------
    // Arguments, abilities and literals
    // -------------------------------------------------------------------------

    private function withEntryText(WithEntryNode $entry): string
    {
        return $entry->key->name.' = '.$this->argumentText($entry->value);
    }

    private function abilityText(INode $ability): string
    {
        return $ability instanceof BindingNode ? ':'.$ability->name : $this->argumentText($ability);
    }

    private function argumentText(INode $argument): string
    {
        return match (true) {
            $argument instanceof NameNode => $argument->name,
            $argument instanceof BindingNode => ':'.$argument->name,
            $argument instanceof LiteralNode => is_string($argument->value) ? self::quoted($argument->value) : $this->writtenText($argument),
            $argument instanceof ContextRef => '@context '.$argument->key->name,
            $argument instanceof ColumnRef => '@column '.($argument->alias === null ? '' : $argument->alias->name.'.').$argument->column->name,
            $argument instanceof SqlRef => '@sql '.$this->argumentText($argument->sql),
            default => $this->writtenText($argument),
        };
    }

    /**
     * $value as a string literal: in single quotes, unless double quotes need
     * fewer escapes.
     */
    private static function quoted(string $value): string
    {
        $quote = substr_count($value, "'") > substr_count($value, '"') ? '"' : "'";

        return $quote.str_replace(['\\', $quote], ['\\\\', '\\'.$quote], $value).$quote;
    }

    /**
     * $node exactly as the source writes it.
     */
    private function writtenText(INode $node): string
    {
        [$start, $end] = CommentPlacement::spanOf($node);

        return substr($this->source, $start, $end - $start);
    }

    /**
     * @param list<INode> $nodes
     */
    private function anyCommented(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($this->comments->isCommented($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $text fits on a line from column $column with $trail characters
     * after it.
     */
    private function fits(int $column, string $text, int $trail): bool
    {
        return $this->onOneLine || $column + mb_strlen($text) + $trail <= self::WIDTH;
    }
}
