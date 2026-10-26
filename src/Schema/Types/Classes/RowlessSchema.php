<?php

namespace Warrant\Schema\Types\Classes;

/**
 * A schema whose abilities are granted to the user as a whole, about no row:
 * `can('export')`. It answers only checks without a target, and its rules use
 * global and derived conditions.
 *
 * ```php
 * final class BillingSchema extends RowlessSchema
 * {
 *     #[Ability] public const EXPORT = 'export';
 *     #[Ability] public const REFUND = 'refund';
 *
 *     #[GlobalCondition]
 *     public function isFinanceStaff(GlobalConditionContext $c): bool
 *     {
 *         return $c->user->department === 'finance';
 *     }
 * }
 * ```
 */
abstract class RowlessSchema extends AbilitySchema
{
}
