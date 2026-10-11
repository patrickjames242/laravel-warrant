<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * One argument: a literal, a binding, or an `@context`, `@column` or `@sql`
 * reference. Its value can be null, as the literal `null` is, or a binding
 * whose value is null.
 *
 * A reference is a node, and each name or string inside it is one of its parts,
 * so the key of `@context org` and the frame and column of `@column docs.org_id`
 * each have a place in the text of their own.
 *
 * @extends Parser<mixed>
 */
final class ParseArgument extends Parser
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

        $key = $this->expect(TokenType::IDENTIFIER, "Expected a context key after '@context'.");
        $this->part(ContextRef::PART_KEY, null, $key);

        return new ContextRef($key->lexeme);
    }

    /**
     * A `@column <column>` or `@column <name>.<column>` reference: one name is
     * the column, on the rows the rule is already about; two are a frame name
     * and then the column.
     */
    private function columnRef(): ColumnRef
    {
        $this->advance(); // consume '@column'

        $first = $this->expect(TokenType::IDENTIFIER, "Expected a column name after '@column'.");

        if (! $this->check(TokenType::DOT)) {
            $this->part(ColumnRef::PART_COLUMN, null, $first);

            return new ColumnRef(null, $first->lexeme);
        }

        $this->part(ColumnRef::PART_ALIAS, null, $first);
        $this->advance(); // consume '.'

        $column = $this->expect(TokenType::IDENTIFIER, "Expected a column name after '@column {$first->lexeme}.'.");
        $this->part(ColumnRef::PART_COLUMN, null, $column);

        return new ColumnRef($first->lexeme, $column->lexeme);
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

        $this->part(SqlRef::PART_SQL, null, $token);

        return new SqlRef($sql);
    }
}
