<?php

namespace Warrant\DSL\Parsing\Writing;

use Closure;
use LogicException;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Formatting\WarrantFormatter;
use Warrant\DSL\Expanding\UnknownNode;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\AndNode;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
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
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\OrNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\ASTNodes\WithEntryNode;
use Warrant\DSL\Parsing\BoundSyntax;

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
 *   named binding of the writer's own, `:b0`, `:b1` and so on in the order
 *   written, and the raw values are collected under those names into a
 *   {@see BoundSyntax}. Lossless for any PHP value.
 *
 * A binding is written as the value its own source gives it, so a placeholder
 * reads back the same in either form.
 *
 * The tree is written on one line, with only the parentheses the DSL's
 * `not` > `and` > `or` precedence needs, and the {@see WarrantFormatter} then
 * lays that text out, so the answer is in the house style every formatted
 * text is in. It says what the tree says (not necessarily as the source wrote
 * it), and reads back to the same tree.
 */
final class RuleSyntaxWriter
{
    // Binding precedence, tightest-binding last: or < and < not < primary.
    private const PREC_OR = 1;
    private const PREC_AND = 2;
    private const PREC_NOT = 3;

    /** @var array<string, mixed> The bindings collected in bound mode, by name. */
    private array $bindings = [];

    private function __construct(private readonly bool $bound)
    {
    }

    /**
     * Render a whole tree to a self-contained string with inline literals.
     * `for <schema>` bodies are written as braced `for <schema> { ... }` blocks,
     * which read back the same whether there is one or several; unscoped
     * entries and a bare expression are written as they are.
     */
    public static function toSyntax(ValidSyntax $syntax): string
    {
        return self::formatted((new self(bound: false))->writeSyntax($syntax));
    }

    /**
     * Render a whole tree to syntax with every value a named binding, plus the
     * values those bindings are given.
     */
    public static function toBoundSyntax(ValidSyntax $syntax): BoundSyntax
    {
        $writer = new self(bound: true);

        return new BoundSyntax(self::formatted($writer->writeSyntax($syntax)), $writer->bindings);
    }

    /**
     * $text, written on one line, laid out by the formatter, without the line
     * break that ends a formatted text.
     */
    private static function formatted(string $text): string
    {
        return rtrim((new WarrantFormatter)->format($text), "\n");
    }

    private function writeSyntax(ValidSyntax $syntax): string
    {
        return implode(' ', array_map(
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
        return implode(' ', array_map($this->writeEntry(...), $entries));
    }

    private function writeEntry(IRuleEntryNode $entry): string
    {
        return match (true) {
            $entry instanceof IncludeInvocationNode => $this->writeInclude($entry),
            $entry instanceof AbilityBlockNode => $this->writeAbilityBlock($entry),
            $entry instanceof WhenBlockNode => $this->writeWhenBlock($entry),
            $entry instanceof RuleNode => $this->writeRule($entry),
            default => throw new LogicException('Cannot render unknown entry ' . $entry::class . '.'),
        };
    }

    /**
     * Render an `@include` as it was written rather than as what it expands to.
     * Its abilities are spelled out with `for`; a generic include has none,
     * because the block header around it says them.
     */
    private function writeInclude(IncludeInvocationNode $include): string
    {
        $arguments = $include->arguments === []
            ? ''
            : '(' . implode(', ', array_map($this->arg(...), $include->arguments)) . ')';

        $written = "@include {$include->templateKey->name}{$arguments}";

        return $include->isGeneric() ? $written : $written . ' for ' . $this->names($include->abilities);
    }

    /**
     * Render a `can they <abilities> { ... }` block around its generic body.
     */
    private function writeAbilityBlock(AbilityBlockNode $block): string
    {
        return $this->writeBraced('can they ' . $this->names($block->abilities), $block->entries);
    }

    /**
     * Render a `when <condition> { ... }` block, and its `otherwise { ... }` when
     * it has one, as written rather than as the rules it expands to.
     */
    private function writeWhenBlock(WhenBlockNode $block): string
    {
        $written = $this->writeBraced('when ' . $this->writeExpression($block->condition), $block->entries);

        return $block->otherwiseEntries === null
            ? $written
            : $written . ' ' . $this->writeBraced('otherwise', $block->otherwiseEntries);
    }

    /**
     * Render $header and its body of $entries in braces.
     *
     * @param list<IRuleEntryNode> $entries
     */
    private function writeBraced(string $header, array $entries): string
    {
        return self::braced($header, $this->writeEntries($entries));
    }

    /**
     * $header and $body in braces; an empty body is `{}`.
     */
    private static function braced(string $header, string $body): string
    {
        return $body === '' ? "{$header} {}" : "{$header} { {$body} }";
    }

    private function writeScoped(ISchemaScopedNode $node): string
    {
        $body = match (true) {
            $node instanceof RuleSetNode => $this->writeEntries($node->entries),
            $node instanceof SchemaConditionNode => $this->writeExpression($node->expression),
            default => throw new LogicException('Cannot render unknown node ' . $node::class . '.'),
        };

        return self::braced("for {$node->schemaKey()}", $body);
    }

    /**
     * Render a rule's `if` and can/cannot clauses. A generic can/cannot clause
     * names no abilities, so it says only `they can` or `they cannot`.
     */
    private function writeRule(RuleNode $rule): string
    {
        $lines = [];

        if ($rule->conditions !== null) {
            $lines[] = 'if ' . $this->writeExpression($rule->conditions);
        }

        foreach ($rule->canClauses as $clause) {
            $lines[] = $this->canOrCannotClauseLine('they can', $clause->abilities);
        }

        // Each `they cannot ...` clause groups the abilities that share its message.
        foreach ($rule->cannotClauses as $clause) {
            $line = $this->canOrCannotClauseLine('they cannot', $clause->abilities);

            if ($clause->message !== null) {
                $line .= ' because ' . $this->messageArg($clause->message);
            }

            $lines[] = $line;
        }

        return implode(' ', $lines);
    }

    /**
     * @param list<NameNode> $abilities
     */
    private function canOrCannotClauseLine(string $keyword, array $abilities): string
    {
        return $abilities === [] ? $keyword : $keyword . ' ' . $this->names($abilities);
    }

    /**
     * @param list<NameNode|BindingNode> $names
     */
    private function names(array $names): string
    {
        return implode(', ', array_map($this->ability(...), $names));
    }

    /**
     * An ability as it is written: a name, or a binding as the abilities its
     * value names.
     */
    private function ability(NameNode|BindingNode $ability): string
    {
        return $ability instanceof BindingNode ? implode(', ', (array) $ability->value()) : $ability->name;
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
            /* An expanded derived condition reads as the call the rule wrote, not
               as what it expanded to. */
            $node instanceof DerivedConditionNode => $this->writeCondition(
                new ConditionNode($node->conditionKey, $node->parameters, $node->location),
            ),
            $node instanceof BooleanNode => throw new LogicException(
                'A constant boolean expression has no rule-language representation.'
            ),
            $node instanceof UnknownNode => throw new LogicException(
                'An unknown answer has no rule-language representation.'
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
            return 'can(' . $this->ability($node->ability) . ')';
        }

        return 'can(' . $this->ability($node->ability) . ' for '
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
     * @param list<INode> $boundKey
     * @param list<WithEntryNode> $contextMap
     */
    private function writeHandleAndWith(
        NameNode $schemaKey,
        bool $isRowBound,
        array $boundKey,
        array $contextMap,
        ?NameNode $alias = null,
    ): string {
        $out = $schemaKey->name;

        if ($isRowBound) {
            $out .= '(' . implode(', ', array_map($this->arg(...), $boundKey)) . ')';
        }

        if ($alias !== null) {
            $out .= ' as ' . $alias->name;
        }

        if ($contextMap !== []) {
            $entries = [];

            foreach ($contextMap as $entry) {
                $entries[] = $entry->key->name . ' = ' . $this->arg($entry->value);
            }

            $out .= ' with ' . implode(', ', $entries);
        }

        return $out . ')';
    }

    private function writeCondition(ConditionNode $node): string
    {
        if ($node->parameters === []) {
            return $node->conditionKey->name;
        }

        return $node->conditionKey->name . '(' . implode(', ', array_map($this->arg(...), $node->parameters)) . ')';
    }

    /**
     * Render a `cannot` clause's denial message. In bound mode it becomes a
     * named binding and its value — a string or a closure — is collected like
     * any other binding, so it round-trips losslessly via toBoundSyntax(). In
     * inline mode a string is written as a literal; a closure has no textual
     * form, so it throws, directing the caller to toBoundSyntax().
     */
    private function messageArg(INode $node): string
    {
        $message = $this->value($node);

        if ($this->bound) {
            return $this->bind($message);
        }

        if ($message instanceof Closure) {
            throw new LogicException(
                'A closure denial message has no inline representation; use toBoundSyntax().'
            );
        }

        return $this->literal($message);
    }

    private function arg(INode $node): string
    {
        $value = $this->value($node);

        // A context ref is a compile-time reference, not a runtime value: it
        // renders identically in inline and bound modes, as a reference and not
        // a binding.
        if ($value instanceof ContextRef) {
            return '@context ' . $value->key->name;
        }

        // A column ref is likewise a compile-time reference, not a runtime value —
        // same rule: it renders identically in both modes, and not as a binding.
        if ($value instanceof ColumnRef) {
            return '@column ' . ($value->alias === null ? '' : $value->alias->name . '.') . $value->column->name;
        }

        // An @sql ref is likewise a compile-time reference, not a runtime value —
        // same rule: it renders identically in both modes, and not as a binding.
        // The body is quoted/escaped so it round-trips back through the lexer.
        if ($value instanceof SqlRef) {
            return '@sql ' . $this->literal($this->value($value->sql));
        }

        if ($this->bound) {
            return $this->bind($value);
        }

        return $this->literal($value);
    }

    /**
     * Collect $value under the next of the writer's binding names, and render
     * the binding that reads it.
     */
    private function bind(mixed $value): string
    {
        $name = 'b' . count($this->bindings);
        $this->bindings[$name] = $value;

        return ':' . $name;
    }

    /**
     * What $node stands for: a literal's value, a binding's value, or the node
     * itself for a reference. A binding's value may itself be a reference.
     */
    private function value(INode $node): mixed
    {
        return match (true) {
            $node instanceof LiteralNode => $node->value,
            $node instanceof BindingNode => $node->value(),
            default => $node,
        };
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
