<?php

namespace Warrant\RuleSyntaxTree;

use Closure;
use LogicException;

/**
 * Renders {@see WarrantRule} ASTs back to the string DSL — the inverse of
 * {@see \Warrant\RuleSyntaxTree\Parsing\WarrantParser}.
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
     * Render rules to a self-contained string with inline literals.
     */
    public static function toSyntax(WarrantRule ...$rules): string
    {
        return (new self(bound: false))->writeRules($rules);
    }

    /**
     * Render rules to `?`-parameterized syntax plus the matching bindings.
     */
    public static function toBoundSyntax(WarrantRule ...$rules): BoundSyntax
    {
        $writer = new self(bound: true);

        return new BoundSyntax($writer->writeRules($rules), $writer->bindings);
    }

    /**
     * @param list<WarrantRule> $rules
     */
    private function writeRules(array $rules): string
    {
        return implode("\n\n", array_map($this->writeRule(...), $rules));
    }

    private function writeRule(WarrantRule $rule): string
    {
        $lines = [];

        if ($rule->conditions !== null) {
            $lines[] = 'if ' . $this->writeExpression($rule->conditions);
        }

        if ($rule->canAbilities !== []) {
            $lines[] = 'they can ' . implode(', ', $rule->canAbilities);
        }

        if ($rule->cannotAbilities !== []) {
            $line = 'they cannot ' . implode(', ', $rule->cannotAbilities);

            if ($rule->message !== null) {
                $line .= ' because ' . $this->messageArg($rule->message);
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
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
        return 'can(' . $node->ability . ' for '
            . $this->writeHandleAndWith($node->schemaKey, $node->isRowBound, $node->boundRow, $node->contextMap);
    }

    private function writeCrossSchemaCheck(CrossSchemaConditionNode $node): string
    {
        return 'check(' . $this->writeExpression($node->predicate) . ' for '
            . $this->writeHandleAndWith($node->schemaKey, $node->isRowBound, $node->boundRow, $node->contextMap);
    }

    /**
     * Render the shared cross-schema tail: the handle (`schema` or
     * `schema(<row>)`), an optional `with <map>`, and the closing paren.
     *
     * @param array<string, mixed> $contextMap
     */
    private function writeHandleAndWith(string $schemaKey, bool $isRowBound, mixed $boundRow, array $contextMap): string
    {
        $out = $schemaKey;

        if ($isRowBound) {
            $out .= '(' . $this->arg($boundRow) . ')';
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
