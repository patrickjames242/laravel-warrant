<?php

namespace Warrant\Schema\Constants;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use Warrant\DSL\Lexing\Lexer;
use Warrant\Schema\Abilities\IDeclaresAbility;

/**
 * Implements {@see IProvidesConstants} over the schema's class constants marked
 * `#[Constant]`, read once per class.
 */
trait ReflectsConstants
{
    /**
     * @var array<class-string, list<ConstantDefinition>>
     */
    private static array $constants = [];

    /**
     * @return list<ConstantDefinition>
     */
    public function constants(): array
    {
        return self::$constants[static::class] ??= self::readConstants();
    }

    /**
     * @return list<ConstantDefinition>
     */
    private static function readConstants(): array
    {
        $constants = [];

        foreach ((new ReflectionClass(static::class))->getReflectionConstants() as $constant) {
            $attributes = $constant->getAttributes(Constant::class);

            if ($attributes === []) {
                continue;
            }

            /* An ability is already a constant, named by its value, so marking it
               `#[Constant]` would give it a second name. */
            if ($constant->getAttributes(IDeclaresAbility::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                throw new InvalidArgumentException(sprintf(
                    'Schema constant [%s::%s] cannot be both an ability and a #[Constant]; an ability is already '
                        .'a constant, named by its value.',
                    static::class,
                    $constant->getName(),
                ));
            }

            $name = ConstantName::of($constant->getName(), $attributes[0]->newInstance()->name);

            if (Lexer::isReservedWord($name)) {
                throw new InvalidArgumentException(sprintf(
                    'Schema constant [%s::%s] is named [%s], which rule text reads as a keyword or a literal. '
                        .'Rename it, or give it a name with #[Constant(\'...\')].',
                    static::class,
                    $constant->getName(),
                    $name,
                ));
            }

            if (isset($constants[$name])) {
                throw new InvalidArgumentException(sprintf(
                    'Schema [%s] declares constant [%s] more than once.',
                    static::class,
                    $name,
                ));
            }

            $constants[$name] = new ConstantDefinition($name, $constant->getValue());
        }

        return array_values($constants);
    }
}
