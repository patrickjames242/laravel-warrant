<?php

namespace Warrant\DSL\Parsing\Writing;

use Closure;
use LogicException;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
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
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

/**
 * Renders Warrant syntax trees back to the string DSL — the inverse of
 * {@see \Warrant\DSL\Parsing\WarrantParser}.
 *
 * Two forms:
 *
 * - **Inline** ({@see toSyntax}) — a self-contained string with scalar condition
 *   parameters written as literals. Throws if a parameter has no inline
 *   representation (arrays, objects, NAN/INF, exponent-only floats).
 * - **Bound** ({@see toBoundSyntax}) — every condition parameter becomes a
 *   positional `?`, and the raw values are collected into a flat, left-to-right
 *   {@see BoundSyntax}. Lossless for any PHP value.
 *
 * The `if` expression is printed with minimal parentheses honouring the DSL's
 * `not` > `and` > `or` precedence, so `(a and b) or c` renders as `a and b or c`
 * while `a and (b or c)` keeps its parentheses. Output is semantically equal to
 * the source (not necessarily textually identical) and idempotent under
 * re-parsing.
 */
final class RuleSyntaxWriter
{
    // Binding precedence, tightest-binding last: or < and < not < primary.
    private const PREC_OR = 1;
    private const PREC_AND = 2;
    private const PREC_NOT = 3;

    /** @var list<mixed> Positional bindings collected in bound mode. */
    private array $bindings = [];

    private function __construct(private readonly bool $bound)
    {
    }

    /**
     * Render a whole tree to a self-contained string with inline literals.
     * `for <schema>` bodies are written as `for <schema> { ... }` blocks one blank
     * line apart, which reads back the same whether there is one or several;
     * headless entries and a bare expression are written as they are.
     */
    public static function toSyntax(WarrantSyntax $syntax): string
    {
        return (new self(bound: false))->writeSyntax($syntax);
    }

    /**
     * Render a whole tree to `?`-parameterized syntax plus the matching bindings.
     */
    public static function toBoundSyntax(WarrantSyntax $syntax): BoundSyntax
    {
        $writer = new self(bound: true);

        return new BoundSyntax($writer->writeSyntax($syntax), $writer->bindings);
    }

    private function writeSyntax(WarrantSyntax $syntax): string
    {
        return implode("\n\n", array_map(
            fn (INode $child): string => match (true) {
                $child instanceof ISchemaScopedNode => $this->writeScoped($child),
                $child instanceof IRuleEntryNode => $this->writeEntry($child),
                $child instanceof IBooleanExpressionNode => $this->writeExpression($child),
                default => throw new LogicException('Cannot render unknown node ' . $child::class . '.'),
            },
            $syntax->children,
        ));
    }

    /**
     * @param list<IRuleEntryNode> $entries
     */
    private function writeEntries(array $entries): string
    {
        return implode("\n\n", array_map($this->writeEntry(...), $entries));
    }

    private function writeEntry(IRuleEntryNode $entry): string
    {
        return match (true) {
            $entry instanceof IncludeInvocationNode => $this->writeInclude($entry),
            $entry instanceof AbilityBlockNode => $this->writeAbilityBlock($entry),
            $entry instanceof WarrantRuleNode => $this->writeRule($entry),
            default => throw new LogicException('Cannot render unknown entry ' . $entry::class . '.'),
        };
    }

    /**
     * Render an `@include` as it was written rather than as what it expands to.
     * Its abilities are spelled out with `for`; a headless include has none,
     * because the block header around it says them.
     */
    private function writeInclude(IncludeInvocationNode $include): string
    {
        $arguments = $include->arguments === []
            ? ''
            : '(' . implode(', ', array_map($this->arg(...), $include->arguments)) . ')';

        $written = "@include {$include->templateKey}{$arguments}";

        return $include->isHeadless() ? $written : $written . ' for ' . implode(', ', $include->abilities);
    }

    /**
     * Render a `can they <abilities> { ... }` block around its headless body.
     */
    private function writeAbilityBlock(AbilityBlockNode $block): string
    {
        $body = $this->writeEntries($block->entries);

        $header = 'can they ' . implode(', ', $block->abilities);

        return $body === '' ? "{$header} {\n}" : "{$header} {\n" . $this->indent($body) . "\n}";
    }

    private function writeScoped(ISchemaScopedNode $node): string
    {
        $body = match (true) {
            $node instanceof RuleSetNode => $this->writeEntries($node->entries),
            $node instanceof SchemaConditionNode => $this->writeExpression($node->expression),
            default => throw new LogicException('Cannot render unknown node ' . $node::class . '.'),
        };

        if ($body === '') {
            return "for {$node->schemaKey()} {\n}";
        }

        return "for {$node->schemaKey()} {\n" . $this->indent($body) . "\n}";
    }

    /**
     * Indent every non-empty line of $text by four spaces (blank lines stay blank).
     */
    private function indent(string $text): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => $line === '' ? '' : '    ' . $line,
            explode("\n", $text),
        ));
    }

    /**
     * Render a rule's `if` line and clauses, one line per clause. A headless
     * clause names no abilities, so it says only `they can` or `they cannot`.
     */
    private function writeRule(WarrantRuleNode $rule): string
    {
        $lines = [];

        if ($rule->conditions !== null) {
            $lines[] = 'if ' . $this->writeExpression($rule->conditions);
        }

        foreach ($rule->canClauses as $clause) {
            $lines[] = $this->clauseLine('they can', $clause->abilities);
        }

        // Each `they cannot ...` clause groups the abilities that share its message.
        foreach ($rule->cannotClauses as $clause) {
            $line = $this->clauseLine('they cannot', $clause->abilities);

            if ($clause->message !== null) {
                $line .= ' because ' . $this->messageArg($clause->message);
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $abilities
     */
    private function clauseLine(string $keyword, array $abilities): string
    {
        return $abilities === [] ? $keyword : $keyword . ' ' . implode(', ', $abilities);
    }

    private function writeExpression(IBooleanExpressionNode $node): string
    {
        return match (true) {
            $node instanceof OrNode => $this->child($node->leftSide, self::PREC_OR)
                . ' or ' . $this->child($node->rightSide, self::PREC_OR),
            $node instanceof AndNode => $this->child($node->leftSide, self::PREC_AND)
                . ' and ' . $this->child($node->rightSide, self::PREC_AND),
            $node instanceof NotNode => 'not ' . $this->child($node->operand, self::PREC_NOT),
            $node instanceof CrossSchemaCanNode => $this->writeCrossSchemaCan($node),
            $node instanceof CrossSchemaConditionNode => $this->writeCrossSchemaCheck($node),
            $node instanceof ConditionNode => $this->writeCondition($node),
            $node instanceof BooleanNode => throw new LogicException(
                'A constant boolean expression has no rule-language representation.'
            ),
            default => throw new LogicException('Cannot render unknown node ' . $node::class . '.'),
        };
    }

    /**
     * Render a child, parenthesizing only when its own precedence binds looser
     * than the surrounding context requires.
     */
    private function child(IBooleanExpressionNode $node, int $context): string
    {
        $rendered = $this->writeExpression($node);

        return $this->precedence($node) < $context ? "($rendered)" : $rendered;
    }

    private function precedence(IBooleanExpressionNode $node): int
    {
        return match (true) {
            $node instanceof OrNode => self::PREC_OR,
            $node instanceof AndNode => self::PREC_AND,
            $node instanceof NotNode => self::PREC_NOT,
            default => PHP_INT_MAX, // primaries never need wrapping
        };
    }

    private function writeCrossSchemaCan(CrossSchemaCanNode $node): string
    {
        if ($node->schemaKey === null) {
            return 'can(' . $node->ability . ')';
        }

        return 'can(' . $node->ability . ' for '
            . $this->writeHandleAndWith(
                $node->schemaKey,
                $node->isRowBound,
                $node->boundKey,
                $node->contextMap,
                $node->alias,
            );
    }

    private function writeCrossSchemaCheck(CrossSchemaConditionNode $node): string
    {
        return 'check(' . $this->writeExpression($node->predicate) . ' for '
            . $this->writeHandleAndWith(
                $node->schemaKey,
                $node->isRowBound,
                $node->boundKey,
                $node->contextMap,
                $node->alias,
            );
    }

    /**
     * Render the shared cross-schema tail: the handle (`schema` or
     * `schema(<row>, …)`), an optional `as <alias>`, an optional `with <map>`, and
     * the closing paren.
     *
     * A row-bound handle always carries its parentheses, empty ones included:
     * `schema()` addresses a row with a key that takes no arguments, and bare
     * `schema` addresses none, so the parentheses are what tell them apart.
     *
     * @param array<int, mixed> $boundKey
     * @param array<string, mixed> $contextMap
     */
    private function writeHandleAndWith(
        string $schemaKey,
        bool $isRowBound,
        array $boundKey,
        array $contextMap,
        ?string $alias = null,
    ): string {
        $out = $schemaKey;

        if ($isRowBound) {
            $out .= '(' . implode(', ', array_map(fn (mixed $argument): string => $this->arg($argument), $boundKey)) . ')';
        }

        if ($alias !== null) {
            $out .= ' as ' . $alias;
        }

        if ($contextMap !== []) {
            $entries = [];

            foreach ($contextMap as $key => $value) {
                $entries[] = $key . ' = ' . $this->arg($value);
            }

            $out .= ' with ' . implode(', ', $entries);
        }

        return $out . ')';
    }

    private function writeCondition(ConditionNode $node): string
    {
        if ($node->parameters === []) {
            return $node->conditionKey;
        }

        return $node->conditionKey . '(' . implode(', ', array_map($this->arg(...), $node->parameters)) . ')';
    }

    /**
     * Render a `cannot` clause's denial message. In bound mode it becomes a
     * positional `?` and its value — a string or a closure — is collected like
     * any other binding, so it round-trips losslessly via toBoundSyntax(). In
     * inline mode a string is written as a literal; a closure has no textual
     * form, so it throws, directing the caller to toBoundSyntax().
     */
    private function messageArg(string|Closure $message): string
    {
        if ($this->bound) {
            $this->bindings[] = $message;

            return '?';
        }

        if ($message instanceof Closure) {
            throw new LogicException(
                'A closure denial message has no inline representation; use toBoundSyntax().'
            );
        }

        return $this->literal($message);
    }

    private function arg(mixed $value): string
    {
        // A context ref is a compile-time reference, not a runtime value: it must
        // render identically in inline and bound modes, and must NOT consume a
        // positional binding (else the `?` count desyncs on re-parse).
        if ($value instanceof ContextRef) {
            return '@context ' . $value->key;
        }

        // A column ref is likewise a compile-time reference, not a runtime value —
        // same rule: render identically in both modes and consume no `?` binding.
        if ($value instanceof ColumnRef) {
            return '@column ' . ($value->alias === null ? '' : $value->alias . '.') . $value->column;
        }

        // An @sql ref is likewise a compile-time reference, not a runtime value —
        // same rule: render identically in both modes and consume no `?` binding.
        // The body is quoted/escaped so it round-trips back through the lexer.
        if ($value instanceof SqlRef) {
            return '@sql ' . $this->literal($value->sql);
        }

        if ($this->bound) {
            $this->bindings[] = $value;

            return '?';
        }

        return $this->literal($value);
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'",
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->floatLiteral($value),
            is_null($value) => 'null',
            default => throw new LogicException(sprintf(
                'Condition parameter of type %s cannot be written inline; use toBoundSyntax().',
                get_debug_type($value),
            )),
        };
    }

    private function floatLiteral(float $value): string
    {
        if (! is_finite($value)) {
            throw new LogicException('NAN/INF cannot be written inline; use toBoundSyntax().');
        }

        $rendered = (string) $value;

        if (str_contains($rendered, 'E') || str_contains($rendered, 'e')) {
            throw new LogicException(sprintf(
                'Float %s requires exponent notation, unsupported inline; use toBoundSyntax().',
                $rendered,
            ));
        }

        // The DSL distinguishes INT and FLOAT by the decimal point; keep it a float.
        return str_contains($rendered, '.') ? $rendered : $rendered . '.0';
    }
}
