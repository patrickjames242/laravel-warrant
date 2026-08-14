<?php

namespace Warrant;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown when a singular-target authorization check fails.
 *
 * Extends Laravel's {@see AuthorizationException} so the framework's exception
 * handler renders it as a 403 with the message out of the box — no handler
 * wiring required. When a matching `cannot` rule carried a denial message, that
 * message is used; otherwise the generic "This action is unauthorized." default
 * applies, indistinguishable from the previous bare `abort(403)`.
 *
 * `$denial` carries the diagnosed {@see WarrantDenialContext} when a specific
 * rule was identified as the cause, and is null for a generic denial.
 */
class WarrantAuthorizationException extends AuthorizationException
{
    public function __construct(
        string $message = 'This action is unauthorized.',
        public readonly ?WarrantDenialContext $denial = null,
    ) {
        parent::__construct($message);
    }
}
