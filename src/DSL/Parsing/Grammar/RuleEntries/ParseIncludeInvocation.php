<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * An `@include`: the template to expand, its arguments, and the abilities the
 * clauses it expands to will take.
 *
 * The include names those abilities with a `for` list, or leaves them to the
 * ability block it is written in. Whether it may name them where it is written
 * is the validator's to report, as for a clause.
 *
 * @extends Parser<IncludeInvocationNode>
 */
final class ParseIncludeInvocation extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::INCLUDE_REF);
    }

    protected function read(): IncludeInvocationNode
    {
        $this->advance(); // consume '@include'

        $template = INode::MISSING_NAME;

        if ($this->check(TokenType::IDENTIFIER)) {
            $templateToken = $this->advance();
            $this->part(IncludeInvocationNode::PART_TEMPLATE_KEY, null, $templateToken);
            $template = $templateToken->lexeme;
        } else {
            $this->report($this->nameError('a rule template name'));
        }

        $arguments = $this->parse(
            new ParseArguments(IncludeInvocationNode::PART_ARGUMENTS, 'the @include arguments')
        )?->value ?? [];

        if (! $this->check(TokenType::FOR)) {
            return new IncludeInvocationNode($template, $arguments, [], $this->located());
        }

        $this->advance(); // consume 'for'

        return new IncludeInvocationNode(
            $template,
            $arguments,
            $this->parse(new ParseAbilityList(IncludeInvocationNode::PART_ABILITIES))->value,
            $this->located(),
        );
    }
}
