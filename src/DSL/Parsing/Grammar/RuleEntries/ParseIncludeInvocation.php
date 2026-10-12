<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\CouldNotParse;
use Warrant\DSL\Parsing\Parsers\Parser;

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

    protected function read(): IncludeInvocationNode|CouldNotParse
    {
        if (! $this->check(TokenType::INCLUDE_REF)) {
            return self::COULD_NOT_PARSE;
        }

        $this->advance();

        if (! $this->check(TokenType::IDENTIFIER)) {
            throw $this->nameError('a rule template name');
        }

        $template = $this->advance();
        $this->part(IncludeInvocationNode::PART_TEMPLATE_KEY, null, $template);

        $arguments = $this->parse(
            new ParseArguments(IncludeInvocationNode::PART_ARGUMENTS, 'the @include arguments')
        )?->value ?? [];

        if (! $this->check(TokenType::FOR)) {
            return new IncludeInvocationNode($template->lexeme, $arguments);
        }

        $this->advance(); // consume 'for'

        return new IncludeInvocationNode(
            $template->lexeme,
            $arguments,
            $this->parse(new ParseAbilityList(IncludeInvocationNode::PART_ABILITIES))->value,
        );
    }
}
