<?php

namespace Warrant\DSL\Parsing\Grammar\Arguments;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\MissingArgumentNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * One argument: a literal, a binding, or an `@context`, `@column` or `@sql`
 * reference. Its value can be null, as the literal `null` is.
 *
 * A reference is a node, and each name or string inside it is one of its parts,
 * so the key of `@context org` and the frame and column of `@column docs.org_id`
 * each have a place in the text of their own.
 *
 * @extends Parser<mixed>
 */
final class ParseArgument extends Parser
{
    protected static function startsHere(TokensAhead $ahead): bool
    {
        return in_array($ahead->peek()->type, [
            TokenType::STRING,
            TokenType::INT,
            TokenType::FLOAT,
            TokenType::BOOL,
            TokenType::NULL,
            TokenType::NAMED_BINDING,
            TokenType::POSITIONAL,
            TokenType::CONTEXT_REF,
            TokenType::COLUMN_REF,
            TokenType::SQL_REF,
        ], true);
    }

    protected function read(): mixed
    {
        return match ($this->peek()->type) {
            TokenType::STRING,
            TokenType::INT,
            TokenType::FLOAT,
            TokenType::BOOL,
            TokenType::NULL => $this->advance()->value,
            TokenType::NAMED_BINDING,
            TokenType::POSITIONAL => $this->parse(ParseBinding::class)->value,
            TokenType::CONTEXT_REF => $this->contextRef(),
            TokenType::COLUMN_REF => $this->columnRef(),
            TokenType::SQL_REF => $this->sqlRef(),
        };
    }

    /**
     * A `@context <key>` reference. It is neither a named nor a positional
     * binding, so the binding rules do not apply to it; it is resolved at
     * compile time.
     */
    private function contextRef(): ContextRef|MissingArgumentNode
    {
        $this->advance(); // consume '@context'

        if (! $this->check(TokenType::IDENTIFIER)) {
            return $this->missingReference("Expected a context key after '@context'.");
        }

        $key = $this->advance();
        $this->part(ContextRef::PART_KEY, null, $key);

        return new ContextRef($key->lexeme, $this->located());
    }

    /**
     * A `@column <column>` or `@column <name>.<column>` reference: one name is
     * the column, on the rows the rule is already about; two are a frame name
     * and then the column.
     */
    private function columnRef(): ColumnRef|MissingArgumentNode
    {
        $this->advance(); // consume '@column'

        if (! $this->check(TokenType::IDENTIFIER)) {
            return $this->missingReference("Expected a column name after '@column'.");
        }

        $first = $this->advance();

        if (! $this->check(TokenType::DOT)) {
            $this->part(ColumnRef::PART_COLUMN, null, $first);

            return new ColumnRef(null, $first->lexeme, $this->located());
        }

        $this->part(ColumnRef::PART_ALIAS, null, $first);
        $this->advance(); // consume '.'

        if (! $this->check(TokenType::IDENTIFIER)) {
            return $this->missingReference("Expected a column name after '@column {$first->lexeme}.'.");
        }

        $column = $this->advance();
        $this->part(ColumnRef::PART_COLUMN, null, $column);

        return new ColumnRef($first->lexeme, $column->lexeme, $this->located());
    }

    /**
     * A `@sql "<sql>"` reference, whose body is a string literal or a binding.
     * The validator checks that a binding's value is a string.
     */
    private function sqlRef(): SqlRef|MissingArgumentNode
    {
        $this->advance(); // consume '@sql'

        $token = $this->peek();

        $sql = match ($token->type) {
            TokenType::STRING => $this->advance()->value,
            TokenType::NAMED_BINDING,
            TokenType::POSITIONAL => $this->parse(ParseBinding::class)->value,
            default => null,
        };

        if ($sql === null) {
            $this->report($this->errorAtCurrent(
                DiagnosticCode::MissingArgument,
                'Expected a quoted SQL string or a binding (:name or ?) after \'@sql\'.',
            ));

            // A literal of another kind is the body written wrong, and is read past with the reference.
            if (in_array($token->type, [TokenType::INT, TokenType::FLOAT, TokenType::BOOL, TokenType::NULL], true)) {
                $this->advance();
            }

            return new MissingArgumentNode($this->located());
        }

        $this->part(SqlRef::PART_SQL, null, $token);

        return new SqlRef($sql, $this->located());
    }

    /**
     * Report a reference whose name or body is missing: a strict parse throws
     * $message, and a tolerant one notes it and reads the reference as a
     * {@see MissingArgumentNode}, so the arguments around it are kept. It is
     * placed over the reference as far as it was written.
     *
     * @param string $message The error naming what the reference is missing.
     */
    private function missingReference(string $message): MissingArgumentNode
    {
        $this->report($this->errorAtCurrent(DiagnosticCode::MissingArgument, $message));

        return new MissingArgumentNode($this->located());
    }
}
