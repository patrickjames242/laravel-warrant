<?php

namespace Warrant\Schema\Types\Classes;

use Warrant\Schema\Conditions\Derived\ReflectsDerivedConditions;
use Warrant\Schema\Conditions\Global\ReflectsGlobalConditions;
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
     * Reads every construct this schema provides, which throws on any it declares
     * wrongly. Each schema type adds the checks its own constructs need.
     */
    protected function verifyDeclarations(): void
    {
        $this->conditions();
        $this->ruleTemplates();
    }
}
