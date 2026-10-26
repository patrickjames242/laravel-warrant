<?php

namespace Warrant\Schema\Types\Classes;

use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Warrant\Schema\Abilities\IDeclaresAbility;
use Warrant\Schema\Abilities\IProvidesAbilities;
use Warrant\Schema\Conditions\Derived\ReflectsDerivedConditions;
use Warrant\Schema\Conditions\Global\ReflectsGlobalConditions;
use Warrant\Schema\Conditions\Row\IProvidesRowConditions;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Context\IProvidesContext;
use Warrant\Schema\Context\RequiredContext;
use Warrant\Schema\RuleTemplates\ReflectsRuleTemplates;
use Warrant\Schema\Types\Interfaces\ISchema;

/**
 * What every schema shares: rule templates, global conditions and derived
 * conditions, each read from the class's attributes.
 *
 * Nothing extends this directly. A schema extends {@see GlobalSchema},
 * {@see RowlessSchema}, {@see ModelSchema} or {@see QuerySchema}.
 *
 * Constructing a schema checks what its class declares, once per class: a
 * construct its type does not provide, such as a `#[RowCondition]` on a schema
 * with no rows, fails here rather than when a rule first reaches it. A subclass
 * that declares a constructor of its own calls this one.
 */
abstract class Schema implements ISchema
{
    use ReflectsDerivedConditions;
    use ReflectsGlobalConditions;
    use ReflectsRuleTemplates;

    /**
     * @var array<class-string, true>
     */
    private static array $verified = [];

    public function __construct()
    {
        if (! isset(self::$verified[static::class])) {
            $this->verifyDeclarations();
            self::$verified[static::class] = true;
        }
    }

    /**
     * Refuses any construct the schema's type does not provide, then reads every
     * construct it does, which throws on any declared wrongly. Each schema type
     * adds the checks its own constructs need.
     */
    protected function verifyDeclarations(): void
    {
        $this->refuseUnprovidedConstructs();
        $this->conditions();
        $this->ruleTemplates();
    }

    /**
     * Which constructs a schema provides is decided by the interfaces it
     * implements, so the refusals are too: a class declaring a construct its
     * interfaces leave out would otherwise have it silently ignored.
     */
    private function refuseUnprovidedConstructs(): void
    {
        $class = new ReflectionClass(static::class);

        foreach ($class->getReflectionConstants() as $constant) {
            if (
                ! $this instanceof IProvidesAbilities
                && $constant->getAttributes(IDeclaresAbility::class, ReflectionAttribute::IS_INSTANCEOF) !== []
            ) {
                throw $this->unprovided(
                    sprintf('Constant [%s::%s] declares an ability', static::class, $constant->getName()),
                    'abilities',
                    'RowlessSchema, ModelSchema or QuerySchema',
                );
            }

            if (! $this instanceof IProvidesContext && $constant->getAttributes(RequiredContext::class) !== []) {
                throw $this->unprovided(
                    sprintf('Constant [%s::%s] declares required context', static::class, $constant->getName()),
                    'context policy',
                    'RowlessSchema, ModelSchema or QuerySchema',
                );
            }
        }

        if (! $this instanceof IProvidesRowConditions) {
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getAttributes(RowCondition::class) !== []) {
                    throw $this->unprovided(
                        sprintf('Method [%s::%s] is a #[RowCondition]', static::class, $method->getName()),
                        'rows',
                        'ModelSchema or QuerySchema',
                    );
                }
            }
        }
    }

    /**
     * @param string $declaration What the class declares.
     * @param string $missing What the schema's type does not provide.
     * @param string $extend The classes that provide it.
     */
    private function unprovided(string $declaration, string $missing, string $extend): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            '%s, but schema [%s] has no %s. Extend %s for a schema that does.',
            $declaration,
            static::class,
            $missing,
            $extend,
        ));
    }
}
