<?php

namespace Warrant\DSL\Parsing\Grammar;

use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\MissingExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Parsing\Parsers\Parser;
use Warrant\DSL\Parsing\WarrantSyntaxException;

/**
 * The errors for a spot where something must be written and is not: a name,
 * a boolean expression, an argument. Used by parsers of the grammar, which
 * supply the token and reading helpers these use.
 */
trait ReportsMissingSyntax
{
    /**
     * The keywords that are spelled as words, and so could be mistaken for a
     * name.
     */
    private const RESERVED_WORDS = [
        TokenType::IF,
        TokenType::THEY,
        TokenType::CAN,
        TokenType::CANNOT,
        TokenType::BECAUSE,
        TokenType::CHECK,
        TokenType::AND,
        TokenType::OR,
        TokenType::NOT,
        TokenType::FOR,
        TokenType::WITH,
        TokenType::AS,
    ];

    /**
     * Read a name here, or report one missing: a strict parse throws, and a
     * tolerant one notes it and puts {@see INode::MISSING_NAME} in its place,
     * placed where the name should be.
     *
     * @param string $expected What the spot expects, as words for the author,
     *   such as 'an ability name'.
     */
    private function nameOrMissing(string $expected): NameNode
    {
        $name = $this->parse(ParseName::class)?->value;

        if ($name !== null) {
            return $name;
        }

        $this->report($this->nameError($expected));

        return new NameNode(INode::MISSING_NAME, $this->emptyLocationHere());
    }

    /**
     * Error for a spot expecting a name, with a clearer hint when the offending
     * token is a reserved word (which cannot be used as a name).
     *
     * @param string $expected What the spot expects, as words for the author,
     *   such as 'an ability name'.
     * @param DiagnosticCode $code Which kind of mistake its absence is, when what
     *   stands there is not a reserved word written as a name.
     */
    private function nameError(string $expected, DiagnosticCode $code = DiagnosticCode::MissingName): WarrantSyntaxException
    {
        $token = $this->peek();

        // `!` is a NOT too; only a keyword spelled as a word looks like a name.
        if (in_array($token->type, self::RESERVED_WORDS, true) && ctype_alpha($token->lexeme)) {
            return $this->errorAtCurrent(
                DiagnosticCode::ReservedWordAsName,
                sprintf("Reserved word '%s' cannot be used as a name; expected %s.", $token->lexeme, $expected)
            );
        }

        return $this->errorAtCurrent($code, sprintf('Expected %s.', $expected));
    }

    /**
     * Error for a spot where a boolean expression must start and none does.
     */
    /**
     * Read a boolean expression with $parser, or report one missing here: a
     * strict parse throws, and a tolerant one notes it and puts a
     * {@see MissingExpressionNode} in its place, so what is around it is kept.
     *
     * @param class-string<Parser<IBooleanExpressionNode>> $parser What reads the
     *   expression here: a whole one, or the single operand that `not`, `and` and
     *   `or` take.
     */
    private function expressionOrMissing(string $parser): IBooleanExpressionNode
    {
        $parsed = $this->parse($parser);

        if ($parsed !== null) {
            return $parsed->value;
        }

        $this->report($this->missingBooleanExpressionError());

        return new MissingExpressionNode($this->emptyLocationHere());
    }

    private function missingBooleanExpressionError(): WarrantSyntaxException
    {
        return $this->nameError("a condition, 'can', 'check(', or '('", DiagnosticCode::MissingCondition);
    }

    /**
     * Error for a spot where an argument must be and none is.
     */
    private function missingArgumentError(): WarrantSyntaxException
    {
        return $this->errorAtCurrent(
            DiagnosticCode::MissingArgument,
            'Expected an argument: a literal, a binding (:name), @context <key>, '
                .'@column <column> or @column <name>.<column>, or @sql "<sql>".'
        );
    }
}
