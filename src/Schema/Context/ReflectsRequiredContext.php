<?php

namespace Warrant\Schema\Context;

use ReflectionClass;
use ReflectionClassConstant;

/**
 * Implements {@see \Warrant\Schema\Context\IProvidesContext::requiredContextKeys()} over
 * the schema's `#[RequiredContext]` constants, read once per class.
 */
trait ReflectsRequiredContext
{
    /**
     * @var array<class-string, list<string>>
     */
    private static array $requiredContextKeys = [];

    /**
     * The values of the constants marked `#[RequiredContext]`.
     *
     * @return list<string>
     */
    public function requiredContextKeys(): array
    {
        return self::$requiredContextKeys[static::class] ??= array_values(array_map(
            static fn (ReflectionClassConstant $constant): string => $constant->getValue(),
            array_filter(
                (new ReflectionClass(static::class))->getReflectionConstants(),
                static fn (ReflectionClassConstant $constant): bool => $constant->getAttributes(RequiredContext::class) !== [],
            ),
        ));
    }
}
