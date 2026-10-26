<?php

namespace Warrant\Schema\Denials;

/**
 * What a schema says when a check against it is denied.
 *
 * Each method answers with a string (wrapped in a
 * {@see \Warrant\WarrantAuthorizationException} → 403), a `Throwable` (thrown as
 * it is), or null to fall through to the generic message.
 */
interface IProvidesDenialMessages
{
    /**
     * The message when a `cannot` rule with no message of its own blocked the
     * check.
     */
    public function forbiddenDenialMessage(WarrantDenialContext $context): string|\Throwable|null;

    /**
     * The message when no `can` rule granted the check, and no `cannot` rule
     * blocked it.
     */
    public function ungrantedDenialMessage(WarrantUngrantedContext $context): string|\Throwable|null;
}
