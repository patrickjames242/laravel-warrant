<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IHandleNode;
use Warrant\DSL\Parsing\ASTNodes\Quantifier;
use Warrant\DSL\Parsing\ASTNodes\RelationHandleNode;
use Warrant\DSL\Parsing\ASTNodes\SchemaHandleNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The handle after `for` in `can(...)` and `check(...)`: `its <relation>`, or a
 * schema name with an optional row selector `schema(<arg>, …)`. A schema handle
 * without a row selector is unbound: it selects no rows.
 *
 * The selector's arguments are bound positionally to the target schema's row
 * key, as a condition's arguments are bound to its parameters, and are read
 * the same way, so `schema()` is an empty list rather than a syntax error. How
 * many the target's key requires is not knowable here, so arity is left to
 * {@see \Warrant\DSL\Validation\SyntaxValidator}, along with whether the schema
 * or relation exists.
 *
 * Either may follow a quantifier, `any of` or `all of`, which the handle's
 * location covers. Whether the handle selects rows for it to count is left to
 * the validator, as an alias is.
 *
 * The `as <alias>` that may follow is read by {@see ParseHandleAlias}, so the
 * handle's location covers the handle alone.
 *
 * @extends Parser<IHandleNode>
 */
final class ParseHandle extends Parser
{
    use ReportsMissingSyntax;

    protected function read(): IHandleNode
    {
        $quantifier = $this->quantifier();

        if ($this->check(TokenType::ITS)) {
            $this->advance(); // consume 'its'

            return new RelationHandleNode($this->nameOrMissing("a relation name after 'its'"), $quantifier, $this->locationEndingHere());
        }

        $schemaKey = $this->nameOrMissing('a schema name');
        $boundKey = $this->parse(new ParseArguments('the row selector'));

        return new SchemaHandleNode($schemaKey, $boundKey !== null, $boundKey?->value ?? [], $quantifier, $this->locationEndingHere());
    }

    private function quantifier(): ?Quantifier
    {
        $quantifier = match (true) {
            $this->check(TokenType::ANY) => Quantifier::Any,
            $this->check(TokenType::ALL) => Quantifier::All,
            default => null,
        };

        if ($quantifier !== null) {
            $word = $this->advance()->lexeme;
            $this->expectOrAssume(TokenType::OF, "Expected 'of' after '{$word}'.");
        }

        return $quantifier;
    }
}
