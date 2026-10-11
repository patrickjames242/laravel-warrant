<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\Grammar\Arguments\ParseArguments;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * An `@include`: the template to expand, its arguments, and the abilities the
 * clauses it expands to will take.
 *
 * In a `for` body the include names those abilities with its own `for` list.
 * Inside a block it is generic, and a `for` list is rejected for the reason a
 * clause's ability list is: the block header is the one place the ability is
 * said. In rules with no `for` header it does what the first entry did.
 *
 * A `for` list written inside a block is reported, and read and dropped. One
 * written, or left off, against the first entry of rules with no `for` header is
 * reported, and the include takes what is written.
 *
 * @extends Parser<IncludeInvocationNode>
 */
final class ParseIncludeInvocation extends Parser
{
    use ReportsMissingSyntax;
    use ReportsAbilityNamingMismatches;

    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): IncludeInvocationNode|NoMatch
    {
        if (! $this->check(TokenType::INCLUDE_REF)) {
            return self::NOTHING;
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

        $names = $this->check(TokenType::FOR);

        if ($this->naming === AbilityNaming::Forbidden && $names) {
            $this->report($this->errorAtCurrent(
                'An @include inside an ability block may not name abilities; the block header already names them.'
            ));
            $this->advance(); // consume 'for'
            $this->parse(new ParseAbilityList(null));

            return new IncludeInvocationNode($template->lexeme, $arguments);
        }

        if ($this->naming->followsFirstEntry() && $names !== $this->naming->names()) {
            $this->report($this->abilityNamingMismatchError($names, 'This @include'));
        }

        if ($this->naming === AbilityNaming::Required && ! $names) {
            throw $this->errorAtCurrent(
                'An @include outside an ability block must name the abilities it applies to, as '
                    .'`@include <template> for <ability>, ...`.'
            );
        }

        if (! $names) {
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
