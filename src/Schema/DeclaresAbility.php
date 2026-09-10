<?php

namespace Warrant\Schema;

/**
 * The contract an attribute must satisfy to declare a schema ability.
 *
 * A schema's abilities are its class constants carrying an attribute that
 * implements this interface; {@see Ability} is the one Warrant ships, and a
 * schema author needing nothing more never has to know the interface exists.
 * Declaring an attribute of your own is for the case where the required context
 * follows from something the author would rather say once —
 * `#[TenantAbility(scopedToBranch: true)]` — than repeat as a literal list on
 * every constant.
 *
 * The interface asks only for the required context because the engine reads
 * nothing else off the attribute: an ability's name is the constant's value, so
 * it is never the attribute's to answer. Implementations are free to take any
 * constructor they like, or none — the required context is whatever this method
 * returns, however it was arrived at.
 *
 * An attribute class is not an attribute by inheritance, so an implementation
 * must carry its own `#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]`.
 *
 * ```php
 * #[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
 * final class TenantAbility implements DeclaresAbility
 * {
 *     public function __construct(private bool $scopedToBranch = false) {}
 *
 *     public function requiredContext(): array
 *     {
 *         return $this->scopedToBranch ? ['tenant', 'branch'] : ['tenant'];
 *     }
 * }
 * ```
 */
interface DeclaresAbility
{
    /**
     * Context keys that must be present whenever the ability is checked. When
     * the ability is named in a yes/no check (can/canAny/authorize/@can) and a
     * key is missing, the check throws; when the ability is merely enumerated
     * (selectUserAbilities / abilities), it is skipped instead.
     *
     * @return array<int, string>
     */
    public function requiredContext(): array;
}
