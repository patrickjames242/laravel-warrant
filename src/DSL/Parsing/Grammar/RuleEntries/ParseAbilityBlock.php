<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\AbilityBlockNode;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\Parsers\NoMatch;
use Warrant\DSL\Parsing\Parsers\Parser;

/**
 * An ability block: `can they <ability>, ... { <rules> }`, whose rules take the
 * header's abilities instead of naming any.
 *
 * The block is grouping and nothing more. Its entries are generic, as the
 * source writes them, and the header alone says which abilities they take;
 * expansion applies it ({@see \Warrant\DSL\Expanding\RuleSetExpander}).
 *
 * @extends Parser<AbilityBlockNode>
 */
final class ParseAbilityBlock extends Parser
{
    use LooksAheadAtRuleEntries;
    use ReportsAbilityNamingMismatches;

    /**
     * @param AbilityNaming $naming The naming of the body the block is written
     *   in, not of the block's own body.
     */
    public function __construct(private readonly AbilityNaming $naming)
    {
    }

    protected function read(): AbilityBlockNode|NoMatch
    {
        if (! $this->abilityBlockAhead()) {
            return self::NOTHING;
        }

        if ($this->naming === AbilityNaming::Forbidden) {
            throw $this->errorAtCurrent(
                'An ability block may not contain another; the enclosing block already names the abilities.'
            );
        }

        if ($this->naming === AbilityNaming::FirstEntryNamesNone) {
            throw $this->abilityNamingMismatchError(true, 'An ability block');
        }

        $this->advance(); // consume 'can'
        $this->advance(); // consume 'they'

        $abilities = $this->parse(new ParseAbilityList(AbilityBlockNode::PART_ABILITIES))->value;

        $this->expect(TokenType::LBRACE, "Expected '{' to open the ability block body.");
        /** @var list<WarrantRuleNode|IncludeInvocationNode> $entries */
        $entries = $this->parse(new ParseRuleEntries(AbilityNaming::Forbidden))->value;
        $this->expect(TokenType::RBRACE, "Expected '}' to close the ability block body.");

        return new AbilityBlockNode($abilities, $entries);
    }
}
