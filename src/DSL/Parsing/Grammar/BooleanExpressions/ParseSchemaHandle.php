<?php

namespace Warrant\DSL\Parsing\Grammar\BooleanExpressions;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\ParseName;
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
 * {@see \Warrant\DSL\Validation\SyntaxValidator}, along with the rest
 * of the handle's coherence, including whether the alias is allowed, since an
 * unbound handle selects nothing to name.
 *
 * @extends Parser<array{0: NameNode, 1: bool, 2: list<INode>, 3: ?NameNode}>
 */
final class ParseSchemaHandle extends Parser
{
    use ReportsMissingSyntax;

    /**
     * @return array{0: NameNode, 1: bool, 2: list<INode>, 3: ?NameNode} [schemaKey, isRowBound, boundKey, alias]
     */
    protected function read(): array
    {
        $schemaKey = $this->nameOrMissing('a schema name');
        $boundKey = $this->parse(new ParseArguments('the row selector'));
        $alias = null;

        if ($this->check(TokenType::AS)) {
            $this->advance();
            $alias = $this->parse(ParseName::class)?->value;

            // An `as` with no name after it is read as no alias.
            if ($alias === null) {
                $this->report($this->nameError("an alias name after 'as'"));
            }
        }

        return [$schemaKey, $boundKey !== null, $boundKey?->value ?? [], $alias];
    }
}
