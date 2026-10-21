<?php

namespace Warrant\DSL\Expanding\Concerns;

use Illuminate\Support\Str;
use ReflectionMethod;
use Warrant\DSL\Expanding\Expander;
use Warrant\DSL\Lexing\Lexer;
use Warrant\DSL\Lexing\Token;
use Warrant\DSL\Lexing\TokenType;
use Warrant\DSL\Parsing\BoundSyntax;

/**
 * How {@see Expander} gives the rule text a template or derived condition
 * answered with as a string the values of the method's parameters, each as a
 * binding named for its parameter.
 *
 * @internal
 */
trait BindsParametersByName
{
    /**
     * The binding values a template or derived condition's rule text may take
     * from the method's parameters: each parameter's value, under its name as
     * declared and in snake case, `$teamId` as `:teamId` and `:team_id`. A
     * parameter's value is what it was called with: its argument, its default
     * when the rule gave none, or for a variadic one every argument from there
     * on. A name as declared wins over another parameter's in snake case.
     *
     * @param list<mixed> $values The values the method was called with.
     * @return array<string, mixed>
     */
    private static function parametersUsableAsBindings(object $schema, string $methodName, array $values): array
    {
        $declared = [];

        foreach ((new ReflectionMethod($schema, $methodName))->getParameters() as $parameter) {
            $position = $parameter->getPosition();

            if ($parameter->isVariadic()) {
                $declared[$parameter->getName()] = array_slice($values, $position);
            } elseif (array_key_exists($position, $values)) {
                $declared[$parameter->getName()] = $values[$position];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $declared[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        $bindings = $declared;

        foreach ($declared as $name => $value) {
            if (! array_key_exists(Str::snake($name), $bindings)) {
                $bindings[Str::snake($name)] = $value;
            }
        }

        return $bindings;
    }

    /**
     * Rule text a template or derived condition answered with as a string, its
     * bindings given values from $parametersUsableAsBindings: each one the text
     * writes. Only those, since a value given and never used is a mistake, and a
     * text need not write every parameter.
     *
     * @param array<string, mixed> $parametersUsableAsBindings
     */
    private static function withParameterBindings(string $text, array $parametersUsableAsBindings): BoundSyntax
    {
        $written = array_map(
            static fn (Token $token): string => $token->value,
            array_filter(
                (new Lexer($text))->scan()->tokens,
                static fn (Token $token): bool => $token->type === TokenType::NAMED_BINDING,
            ),
        );

        return new BoundSyntax($text, array_intersect_key($parametersUsableAsBindings, array_flip($written)));
    }
}
