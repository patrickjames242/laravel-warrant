<?php

namespace Warrant\DSL\Parsing\ASTNodes;

use Closure;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\Schema\WarrantDenialContext;

/**
 * One `they cannot <abilities> [because <message>]` clause of a {@see WarrantRuleNode}.
 *
 * A rule's `cannot` side is a list of these, so different abilities can carry
 * different denial messages while still living on one rule. Every ability in a
 * single clause shares that clause's {@see $message}.
 *
 * The compiler and reachability analyzer treat the abilities purely as a
 * membership list ({@see WarrantRuleNode::cannotAbilities()} flattens every clause),
 * so the message never affects the compiled predicate — it is surfaced only when
 * diagnosing a denial (see {@see WarrantRuleNode::messageFor()}).
 */
readonly class CannotClauseNode implements INode
{
    /* The names this node's parts are placed under in its {@see SyntaxLocation}:
       each is the property the part is stored in. */
    public const PART_ABILITIES = 'abilities';
    public const PART_MESSAGE = 'message';

    /**
     * @param list<string> $abilities Denied ability names (or `*`).
     * @param string|Closure(WarrantDenialContext):(string|\Throwable)|BindingNode|mixed|null $message
     *   The denial message shared by every ability in this clause: a string is
     *   wrapped in a {@see \Warrant\WarrantAuthorizationException}; a closure
     *   receives the denial context and returns a string (wrapped) or a Throwable
     *   (thrown as-is); a binding supplies one of those until expansion puts it
     *   in its place; null means no message (a plain forbid). Anything
     *   else written after `because`, such as `@context`, is held as written
     *   for the validator to report.
     */
    public function __construct(
        public array $abilities,
        public mixed $message,
        public SyntaxLocation $location,
    ) {}
}
