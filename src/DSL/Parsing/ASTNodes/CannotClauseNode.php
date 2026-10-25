<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Warrant\DSL\Parsing\Positions\SyntaxLocation;

/**
 * One `they cannot <abilities> [because <message>]` clause of a {@see RuleNode}.
 *
 * A rule's `cannot` side is a list of these, so different abilities can carry
 * different denial messages while still living on one rule. Every ability in a
 * single clause shares that clause's {@see $message}.
 *
 * The compiler and reachability analyzer treat the abilities purely as a
 * membership list ({@see RuleNode::cannotAbilities()} flattens every clause),
 * so the message never affects the compiled predicate — it is surfaced only when
 * diagnosing a denial (see {@see RuleNode::messageFor()}).
 */
readonly class CannotClauseNode implements INode
{
    /**
     * @param list<NameNode|BindingNode> $abilities Denied ability names (or
     *   `*`), and bindings whose values name them until expansion puts those
     *   names in their place.
     * @param INode|null $message The denial message shared by every ability in
     *   this clause, as a {@see LiteralNode} or a {@see BindingNode} that
     *   supplies one until expansion puts it in its place. Its value is a string,
     *   wrapped in a {@see \Warrant\WarrantAuthorizationException}, or a
     *   closure, which receives the denial context and returns a string
     *   (wrapped) or a Throwable (thrown as-is). Null means no message (a plain
     *   forbid). Anything else written after `because`, such as `@context`, is
     *   held as written for the validator to report.
     */
    public function __construct(
        public array $abilities,
        public ?INode $message,
        public SyntaxLocation $location,
    ) {}

    public function children(): array
    {
        return [
            ...$this->abilities,
            ...($this->message === null ? [] : [$this->message]),
        ];
    }
}
