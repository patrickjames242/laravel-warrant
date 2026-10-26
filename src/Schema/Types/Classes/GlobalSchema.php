<?php

namespace Warrant\Schema\Types\Classes;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use Warrant\Schema\Abilities\IDeclaresAbility;
use Warrant\Schema\Context\RequiredContext;
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
    protected function verifyDeclarations(): void
    {
        parent::verifyDeclarations();

        foreach ((new ReflectionClass(static::class))->getReflectionConstants() as $constant) {
            $declaresAbility = $constant->getAttributes(IDeclaresAbility::class, ReflectionAttribute::IS_INSTANCEOF) !== [];

            if ($declaresAbility || $constant->getAttributes(RequiredContext::class) !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Constant [%s::%s] declares %s, but a global schema is never checked. Extend '
                        .'RowlessSchema, ModelSchema or QuerySchema for a schema with abilities.',
                    static::class,
                    $constant->getName(),
                    $declaresAbility ? 'an ability' : 'required context',
                ));
            }
        }
    }
}
