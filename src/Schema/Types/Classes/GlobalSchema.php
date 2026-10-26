<?php

namespace Warrant\Schema\Types\Classes;

use Warrant\Schema\Types\Interfaces\IGlobalSchema;

/**
 * Rule templates and conditions any rule may use without naming this schema.
 *
 * It declares no abilities and no required context: no check is ever made
 * against it, so neither would mean anything.
 *
 * ```php
 * final class TenancySchema extends GlobalSchema
 * {
 *     #[GlobalCondition]
 *     public function inCurrentTenant(GlobalConditionContext $c): bool
 *     {
 *         return $c->user->tenant_id === $c->context['tenant_id'];
 *     }
 * }
 * ```
 */
abstract class GlobalSchema extends Schema implements IGlobalSchema
{
}
