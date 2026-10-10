<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\Grammar\GrammarParser;

/**
 * One argument: a literal, a binding, or an `@context`, `@column` or `@sql`
 * reference. Its value can be null, as the literal `null` is, or a binding
 * whose value is null.
 *
 * @extends GrammarParser<mixed>
 */
final class ParseArgument extends GrammarParser
{
    protected function read(): mixed
    {
        return match ($this->peek()->type) {
            TokenType::STRING,
            TokenType::INT,
            TokenType::FLOAT,
            TokenType::BOOL,
            TokenType::NULL => $this->advance()->value,
            TokenType::NAMED_BINDING => $this->bindings()->resolveNamed($this->advance()),
            TokenType::POSITIONAL => $this->bindings()->resolvePositional($this->advance()),
            TokenType::CONTEXT_REF => $this->contextRef(),
            TokenType::COLUMN_REF => $this->columnRef(),
            TokenType::SQL_REF => $this->sqlRef(),
            default => self::NOTHING,
        };
    }

    /**
     * A `@context <key>` reference. It is neither a named nor a positional
     * binding, so the binding rules do not apply to it; it is resolved at
     * compile time.
     */
    private function contextRef(): ContextRef
    {
        $this->advance(); // consume '@context'

        return new ContextRef(
            $this->expect(TokenType::IDENTIFIER, "Expected a context key after '@context'.")->lexeme,
        );
    }

    /**
     * A `@column <column>` or `@column <name>.<column>` reference: one name is
     * the column, on the rows the rule is already about; two are a frame name
     * and then the column.
     */
    private function columnRef(): ColumnRef
    {
        $this->advance(); // consume '@column'

        $first = $this->expect(TokenType::IDENTIFIER, "Expected a column name after '@column'.")->lexeme;

        if (! $this->check(TokenType::DOT)) {
            return new ColumnRef(null, $first);
        }

        $this->advance(); // consume '.'

        return new ColumnRef(
            $first,
            $this->expect(TokenType::IDENTIFIER, "Expected a column name after '@column {$first}.'.")->lexeme,
        );
    }

    /**
     * A `@sql "<sql>"` reference, whose body is a string literal or a binding
     * that resolves to a string.
     */
    private function sqlRef(): SqlRef
    {
        $this->advance(); // consume '@sql'

        $token = $this->peek();

        $sql = match ($token->type) {
            TokenType::STRING => $this->advance()->value,
            TokenType::NAMED_BINDING => $this->bindings()->resolveNamed($this->advance()),
            TokenType::POSITIONAL => $this->bindings()->resolvePositional($this->advance()),
            default => throw $this->errorAtCurrent(
                'Expected a quoted SQL string or a binding (:name or ?) after \'@sql\'.'
            ),
        };

        if (! is_string($sql)) {
            throw $this->errorAt(sprintf('An @sql binding must resolve to a string, got %s.', get_debug_type($sql)), $token);
        }

        return new SqlRef($sql);
    }
}
