<?php

namespace Warrant\DSL\Parsing\Grammar\RuleEntries;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IRuleEntryNode;
use Warrant\DSL\Parsing\ASTNodes\WhenBlockNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Grammar\BooleanExpressions\ParseBooleanExpression;
use Warrant\DSL\Parsing\Grammar\ReportsMissingSyntax;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\Parsers\TokensAhead;

/**
 * A when block: `when <boolean expression> { <rules> }`, optionally followed by
 * `otherwise { <rules> }`.
 *
 * Both bodies are braced, always. `otherwise` takes a body and nothing else, so
 * `otherwise when` is reported rather than read as a chain: a branch meaning
 * "not the condition before, and this one" is left for the author to write out.
 * A tolerant parse reads the `when` block after it as the `otherwise` body, which
 * is what the author meant.
 *
 * The block is read as it is written; an empty body is the validator's to report.
 *
 * @extends Parser<WhenBlockNode>
 */
final class ParseWhenBlock extends Parser
{
    use ReportsMissingSyntax;

    protected static function startsHere(TokensAhead $ahead): bool
    {
        return $ahead->nextIs(TokenType::WHEN);
    }

    protected function read(): WhenBlockNode
    {
        $this->advance(); // consume 'when'
        $condition = $this->expressionOrMissing(ParseBooleanExpression::class);
        $entries = $this->body("Expected '{' to open the when block body.", "Expected '}' to close the when block body.");

        return new WhenBlockNode($condition, $entries, $this->otherwiseEntries(), $this->locationEndingHere());
    }

    /**
     * The `otherwise` body after the block's, or null when there is none.
     *
     * @return list<IRuleEntryNode>|null
     */
    private function otherwiseEntries(): ?array
    {
        if (! $this->check(TokenType::OTHERWISE)) {
            return null;
        }

        $this->advance(); // consume 'otherwise'

        if ($this->check(TokenType::WHEN)) {
            $this->report($this->errorAtCurrent(
                DiagnosticCode::OtherwiseWhen,
                "'otherwise' is followed by a body in braces, not another 'when'. "
                    .'Write the next case as a when block of its own, with its whole condition.',
            ));

            return [$this->parse(self::class)->value];
        }

        return $this->body("Expected '{' to open the otherwise body.", "Expected '}' to close the otherwise body.");
    }

    /**
     * @return list<IRuleEntryNode>
     */
    private function body(string $unopened, string $unclosed): array
    {
        $this->expectOrAssume(TokenType::LBRACE, $unopened);
        $entries = $this->parse(new ParseRuleEntries($unclosed))->value;
        $this->expectOrAssume(TokenType::RBRACE, $unclosed);

        return $entries;
    }
}
