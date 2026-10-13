<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * The schema handle after `for` in `can(...)` and `check(...)`: a schema name
 * with an optional row selector `schema(<arg>, …)` and an optional
 * `as <alias>`. A handle without a row selector is unbound: it selects no rows.
 *
 * The selector's arguments are bound positionally to the target schema's row
 * key, as a condition's arguments are bound to its parameters, and are read
 * the same way, so `schema()` is an empty list rather than a syntax error. How
 * many the target's key requires is not knowable here, so arity is left to
 * {@see \Warrant\DSL\Parsing\Validation\RuleSetValidator}, along with the rest
 * of the handle's coherence, including whether the alias is allowed, since an
 * unbound handle selects nothing to name.
 *
 * @extends Parser<array{0: string, 1: bool, 2: array<int, mixed>, 3: ?string}>
 */
final class ParseSchemaHandle extends Parser
{
    use ReportsMissingSyntax;

    /**
     * @param class-string<CrossSchemaCanNode|CrossSchemaConditionNode> $owner The
     *   node the handle belongs to, whose constants name the parts read here.
     */
    public function __construct(private readonly string $owner)
    {
    }

    /**
     * @return array{0: string, 1: bool, 2: array<int, mixed>, 3: ?string} [schemaKey, isRowBound, boundKey, alias]
     */
    protected function read(): array
    {
        $schemaKey = INode::MISSING_NAME;

        if ($this->check(TokenType::IDENTIFIER)) {
            $schema = $this->advance();
            $this->part($this->owner::PART_SCHEMA_KEY, null, $schema);
            $schemaKey = $schema->lexeme;
        } else {
            $this->report($this->nameError('a schema name'));
        }

        $boundKey = $this->parse(new ParseArguments($this->owner::PART_BOUND_KEY, 'the row selector'));
        $alias = null;

        if ($this->check(TokenType::AS)) {
            $this->advance();

            // An `as` with no name after it is read as no alias.
            if ($this->check(TokenType::IDENTIFIER)) {
                $aliasToken = $this->advance();
                $this->part($this->owner::PART_ALIAS, null, $aliasToken);
                $alias = $aliasToken->lexeme;
            } else {
                $this->report($this->nameError("an alias name after 'as'"));
            }
        }

        return [$schemaKey, $boundKey !== null, $boundKey?->value ?? [], $alias];
    }
}
