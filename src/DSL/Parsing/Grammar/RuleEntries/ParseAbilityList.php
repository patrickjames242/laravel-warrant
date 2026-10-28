<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * A comma-separated list of at least one ability, each a name, `*` or a
 * binding.
 *
 * @extends Parser<list<NameNode|BindingNode>>
 */
final class ParseAbilityList extends Parser
{
    use ReportsMissingSyntax;

    /**
     * @return list<NameNode|BindingNode>
     */
    protected function read(): array
    {
        $missingAbility = fn () => $this->nameError('an ability name');

        $abilities = $this->parseSeparatedList(ParseAbility::class, TokenType::COMMA, $missingAbility);

        if ($abilities === []) {
            $this->report($missingAbility());
        }

        return $abilities;
    }
}
