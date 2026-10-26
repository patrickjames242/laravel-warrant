<?php

namespace Warrant\Schema\RuleTemplates;

use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Row\RowCondition;

/**
 * Implements {@see \Warrant\Schema\RuleTemplates\IProvidesRuleTemplates} over the
 * schema's `#[RuleTemplate]` methods, read once per class.
 *
 * A template is handed argument values and answers with rule text, reading no row
 * and no query. It may ask for the abilities its rules will take by declaring a
 * {@see RuleTemplateContext} first; that parameter is not a DSL argument, so it is
 * left out of the required count.
 */
trait ReflectsRuleTemplates
{
    /**
     * @var array<class-string, array<string, RuleTemplateDefinition>>
     */
    private static array $ruleTemplates = [];

    /**
     * @return list<RuleTemplateDefinition>
     */
    public function ruleTemplates(): array
    {
        return array_values(self::ruleTemplatesByKey());
    }

    public function ruleTemplate(string $key): ?RuleTemplateDefinition
    {
        return self::ruleTemplatesByKey()[$key] ?? null;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function invokeRuleTemplate(RuleTemplateDefinition $template, ?RuleTemplateContext $context, array $arguments): mixed
    {
        return $template->takesContext
            ? $this->{$template->methodName}($context, ...$arguments)
            : $this->{$template->methodName}(...$arguments);
    }

    /**
     * @return array<string, RuleTemplateDefinition>
     */
    private static function ruleTemplatesByKey(): array
    {
        return self::$ruleTemplates[static::class] ??= self::readRuleTemplates();
    }

    /**
     * @return array<string, RuleTemplateDefinition>
     */
    private static function readRuleTemplates(): array
    {
        $templates = [];

        foreach ((new ReflectionClass(static::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || ($definition = self::readRuleTemplate($method)) === null) {
                continue;
            }

            if (isset($templates[$definition->key])) {
                throw new InvalidArgumentException(sprintf(
                    'Schema [%s] declares rule template [%s] more than once.',
                    static::class,
                    $definition->key,
                ));
            }

            $templates[$definition->key] = $definition;
        }

        return $templates;
    }

    private static function readRuleTemplate(ReflectionMethod $method): ?RuleTemplateDefinition
    {
        $attributes = $method->getAttributes(RuleTemplate::class);

        if ($attributes === []) {
            return null;
        }

        if (count($attributes) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Rule template method [%s::%s] must not declare duplicate #[RuleTemplate] attributes.',
                static::class,
                $method->getName(),
            ));
        }

        /* A condition answers about a row or a user; a template answers with rule
           text about none. A method wearing both attributes has no single meaning
           to pick. */
        if (
            $method->getAttributes(RowCondition::class) !== []
            || $method->getAttributes(GlobalCondition::class) !== []
            || $method->getAttributes(DerivedCondition::class) !== []
        ) {
            throw new InvalidArgumentException(sprintf(
                'Method [%s::%s] cannot be both a condition and a rule template.',
                static::class,
                $method->getName(),
            ));
        }

        $attribute = $attributes[0]->newInstance();
        $firstType = ($method->getParameters()[0] ?? null)?->getType();
        $takesContext = $firstType instanceof ReflectionNamedType && $firstType->getName() === RuleTemplateContext::class;

        /* Called once per ability, a template that cannot see which one answers the
           same rules every time, so the flag would only split them into one rule
           per ability. */
        if ($attribute->callOncePerAbility && ! $takesContext) {
            throw new InvalidArgumentException(sprintf(
                'Rule template [%s::%s] is declared callOncePerAbility, so it must accept a [%s] as its first '
                    .'parameter to be told which ability each call is for.',
                static::class,
                $method->getName(),
                RuleTemplateContext::class,
            ));
        }

        return new RuleTemplateDefinition(
            $attribute->key ?? Str::snake($method->getName()),
            $method->getName(),
            max(0, $method->getNumberOfRequiredParameters() - ($takesContext ? 1 : 0)),
            $takesContext,
            $attribute->callOncePerAbility,
        );
    }
}
