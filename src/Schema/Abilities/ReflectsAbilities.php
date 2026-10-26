<?php

namespace Warrant\Schema\Abilities;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Implements {@see \Warrant\Schema\Abilities\IProvidesAbilities} over the
 * schema's constants carrying an {@see IDeclaresAbility} attribute: `#[Ability]`,
 * or an attribute of the author's own implementing the same interface. Read once
 * per class.
 */
trait ReflectsAbilities
{
    /**
     * @var array<class-string, array<string, AbilityDefinition>>
     */
    private static array $abilities = [];

    /**
     * @return list<AbilityDefinition>
     */
    public function abilities(): array
    {
        return array_values(self::abilitiesByName());
    }

    public function ability(string $name): ?AbilityDefinition
    {
        return self::abilitiesByName()[$name] ?? null;
    }

    /**
     * @return array<string, AbilityDefinition>
     */
    private static function abilitiesByName(): array
    {
        return self::$abilities[static::class] ??= self::readAbilities();
    }

    /**
     * An ability's name is the constant's value, so it is read here. The attribute
     * answers only for the context the ability requires.
     *
     * @return array<string, AbilityDefinition>
     */
    private static function readAbilities(): array
    {
        $abilities = [];

        foreach ((new ReflectionClass(static::class))->getReflectionConstants() as $constant) {
            $attributes = $constant->getAttributes(IDeclaresAbility::class, ReflectionAttribute::IS_INSTANCEOF);

            if ($attributes === []) {
                continue;
            }

            if (count($attributes) > 1) {
                throw new InvalidArgumentException(sprintf(
                    'Schema constant [%s::%s] declares more than one ability attribute; an ability has one set '
                        .'of required context, so only one may be present.',
                    static::class,
                    $constant->getName(),
                ));
            }

            $name = $constant->getValue();

            if (isset($abilities[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Schema [%s] declares ability [%s] more than once.',
                    static::class,
                    $name,
                ));
            }

            $abilities[$name] = new AbilityDefinition($name, $attributes[0]->newInstance()->requiredContext());
        }

        return $abilities;
    }
}
