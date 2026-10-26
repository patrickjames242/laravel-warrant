<?php

namespace Warrant\Schema\Types\Classes;

use Illuminate\Contracts\Auth\Authenticatable;
use Warrant\Facades\Warrant;
use Warrant\Guard\WarrantGuardForSchema;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\ReflectsAbilities;
use Warrant\Schema\Context\ReflectsRequiredContext;
use Warrant\Schema\Denials\WarrantDenialContext;
use Warrant\Schema\Denials\WarrantUngrantedContext;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;

/**
 * What every schema checks are made against shares: abilities, the schema's own
 * rules, its context policy, and its denial messages.
 *
 * Nothing extends this directly. A schema extends {@see RowlessSchema},
 * {@see ModelSchema} or {@see QuerySchema}.
 */
abstract class AbilitySchema extends Schema implements IAbilitySchema
{
    use ReflectsAbilities;
    use ReflectsRequiredContext;

    /**
     * The guard for this schema and a user, the current one by default:
     * `DocumentSchema::guard($user)->can('publish', $document)`.
     */
    public static function guard(?Authenticatable $user = null): WarrantGuardForSchema
    {
        return Warrant::forSchema(static::class, $user);
    }

    /**
     * This schema's own rules, merged ahead of whatever the global
     * {@see \Warrant\Rules\IRuleProvider} returns, and validated and compiled
     * exactly as its rules are. Called once per guard, which is fixed to one
     * schema and one user, so the rules may depend on the user:
     *
     * ```php
     * public function rules(RuleProviderContext $context): string
     * {
     *     return <<<'WARRANT'
     *         if is_super_admin they can *
     *         if is_suspended they cannot *
     *     WARRANT;
     * }
     * ```
     */
    public function rules(RuleProviderContext $context): mixed
    {
        return [];
    }

    /**
     * Override to source the context from the request, the tenant or the
     * container, so a check that takes no context still receives it:
     *
     * ```php
     * public function defaultContext(): array
     * {
     *     return ['workspace_id' => app('tenant')->id];
     * }
     * ```
     *
     * @return array<string, mixed>
     */
    public function defaultContext(): array
    {
        return [];
    }

    public function forbiddenDenialMessage(WarrantDenialContext $context): string|\Throwable|null
    {
        return null;
    }

    public function ungrantedDenialMessage(WarrantUngrantedContext $context): string|\Throwable|null
    {
        return null;
    }

    protected function verifyDeclarations(): void
    {
        parent::verifyDeclarations();

        $this->abilities();
    }
}
