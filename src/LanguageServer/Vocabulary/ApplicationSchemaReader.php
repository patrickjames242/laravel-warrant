<?php

namespace Warrant\LanguageServer\Vocabulary;

use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ConditionDefinition;
use Warrant\Schema\ConditionKind;
use Warrant\Schema\DeclaresAbility;
use Warrant\Schema\RuleTemplateDefinition;
use Warrant\Schema\WarrantSchema;

/**
 * Reads a project's schemas from the running application, by reflection, as the
 * schemas read themselves.
 *
 * This is the exact reading: inheritance, traits, computed constants and the
 * project's own ability attributes all resolve as they do at run time. It runs
 * the project's code, so it is only ever done in a child process of the server
 * ({@see ApplicationVocabularyProcess}), whose output the server reads as JSON:
 * a broken application, or one that exits, takes only the child with it.
 */
final class ApplicationSchemaReader
{
    /** Marks the start of the JSON in the child's output, after anything booting printed. */
    public const BEGIN = '--- warrant-vocabulary ---';

    /**
     * Boot the Laravel application at $root and write its schemas to standard
     * output as JSON, after {@see BEGIN}. An application that cannot boot is
     * written as `{"error": "..."}`.
     *
     * @param string $root Absolute path of the project.
     */
    public static function dump(string $root): int
    {
        try {
            $app = require $root.'/bootstrap/app.php';
            $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

            $schemas = [];

            foreach ((array) $app['config']->get('warrant.schemas', []) as $key => $className) {
                if (is_string($key) && is_string($className) && is_subclass_of($className, WarrantSchema::class)) {
                    try {
                        $schemas[$key] = self::describe($key, $className);
                    } catch (Throwable) {
                        // A schema that cannot describe itself is left to the reading from source.
                    }
                }
            }

            $output = ['schemas' => $schemas];
            $status = 0;
        } catch (Throwable $error) {
            $output = ['error' => $error->getMessage()];
            $status = 1;
        }

        fwrite(STDOUT, "\n".self::BEGIN."\n".json_encode($output, JSON_INVALID_UTF8_SUBSTITUTE)."\n");

        return $status;
    }

    /**
     * The index the JSON {@see dump()} wrote describes.
     *
     * @param array<string, mixed> $data The decoded JSON's `schemas`.
     */
    public static function indexFrom(array $data): VocabularyIndex
    {
        $schemas = [];

        foreach ($data as $key => $schema) {
            if (! is_array($schema)) {
                continue;
            }

            $declarations = static function (string $kind, Closure $definition) use ($schema): array {
                $read = [];

                foreach ((array) ($schema[$kind] ?? []) as $item) {
                    $read[(string) $item['name']] = new Declaration(
                        (string) $item['name'],
                        $definition($item),
                        $item['file'] ?? null,
                        $item['line'] ?? null,
                        $item['description'] ?? null,
                        array_values((array) ($item['parameters'] ?? [])),
                    );
                }

                return $read;
            };

            $schemas[(string) $key] = new IndexedSchema(
                (string) $key,
                (string) $schema['class'],
                $schema['file'] ?? null,
                $schema['line'] ?? null,
                $schema['description'] ?? null,
                (bool) $schema['hasRows'],
                (bool) $schema['hasRowKey'],
                (int) $schema['keyArgumentCount'],
                $declarations('abilities', static fn (array $item): AbilityDefinition => new AbilityDefinition($item['name'], array_values((array) $item['requiredContext']))),
                $declarations('conditions', static fn (array $item): ConditionDefinition => new ConditionDefinition(
                    $item['name'],
                    $item['method'],
                    constant(ConditionKind::class.'::'.$item['kind']),
                    (int) $item['requiredArgumentCount'],
                )),
                $declarations('templates', static fn (array $item): RuleTemplateDefinition => new RuleTemplateDefinition(
                    $item['name'],
                    $item['method'],
                    (int) $item['requiredArgumentCount'],
                )),
                array_values((array) ($schema['requiredContextKeys'] ?? [])),
            );
        }

        return new VocabularyIndex($schemas);
    }

    /**
     * What {@see dump()} writes for one schema.
     *
     * @param string $key The key it is registered under.
     * @param class-string<WarrantSchema> $className Its class.
     * @return array<string, mixed>
     */
    private static function describe(string $key, string $className): array
    {
        $class = new ReflectionClass($className);

        /* The definition lists are the schema's own reflection, which validation
           reads through its lookups; listing them reaches the protected methods
           that build them. */
        $conditionDefinitions = Closure::bind(static fn (): array => $className::conditionDefinitions(), null, $className)();
        $templateDefinitions = Closure::bind(static fn (): array => $className::ruleTemplateDefinitions(), null, $className)();

        return [
            'class' => $className,
            'file' => $class->getFileName() ?: null,
            'line' => $class->getStartLine() ?: null,
            'description' => StaticSchemaScanner::summary($class->getDocComment() ?: null),
            'hasRows' => $className::hasRows(),
            'hasRowKey' => $className::hasRowKey(),
            'keyArgumentCount' => $className::keyDefinition()->requiredArgumentCount,
            'abilities' => array_map(
                static fn (AbilityDefinition $ability): array => [
                    'name' => $ability->name,
                    'requiredContext' => $ability->requiredContext,
                    ...self::constantLocation($class, $ability->name),
                ],
                $className::abilityDefinitions(),
            ),
            'conditions' => array_map(
                static fn (ConditionDefinition $condition): array => [
                    'name' => $condition->key,
                    'method' => $condition->methodName,
                    'kind' => $condition->kind->name,
                    'requiredArgumentCount' => $condition->requiredArgumentCount,
                    ...self::methodLocation($class, $condition->methodName, $condition->kind === ConditionKind::Derived ? 0 : 1),
                ],
                $conditionDefinitions,
            ),
            'templates' => array_map(
                static fn (RuleTemplateDefinition $template): array => [
                    'name' => $template->key,
                    'method' => $template->methodName,
                    'requiredArgumentCount' => $template->requiredArgumentCount,
                    ...self::methodLocation($class, $template->methodName, 0),
                ],
                $templateDefinitions,
            ),
            'requiredContextKeys' => $className::requiredContextKeys(),
        ];
    }

    /**
     * Where the method $name is declared, what its docblock says, and the names
     * of the parameters rule text passes it.
     *
     * @param ReflectionClass<object> $class The schema class.
     * @param string $name The method's name.
     * @param int $skip How many leading parameters rule text does not pass.
     * @return array{file: ?string, line: ?int, description: ?string, parameters: list<string>}
     */
    private static function methodLocation(ReflectionClass $class, string $name, int $skip): array
    {
        $method = new ReflectionMethod($class->getName(), $name);

        return [
            'file' => $method->getFileName() ?: null,
            'line' => $method->getStartLine() ?: null,
            'description' => StaticSchemaScanner::summary($method->getDocComment() ?: null),
            'parameters' => array_values(array_map(
                static fn ($parameter): string => $parameter->getName(),
                array_slice($method->getParameters(), $skip),
            )),
        ];
    }

    /**
     * Where the ability constant whose value is $ability is declared, and what
     * its docblock says. A constant has no line of its own in reflection, so the
     * line is found in its declaring class's file.
     *
     * @param ReflectionClass<object> $class The schema class.
     * @param string $ability The ability's name.
     * @return array{file?: ?string, line?: ?int, description?: ?string}
     */
    private static function constantLocation(ReflectionClass $class, string $ability): array
    {
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getValue() !== $ability || $constant->getAttributes(DeclaresAbility::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
                continue;
            }

            $file = $constant->getDeclaringClass()->getFileName() ?: null;
            $line = null;

            if ($file !== null) {
                foreach (file($file) ?: [] as $number => $text) {
                    if (preg_match('/\bconst\s+(?:[\w\\\\|?]+\s+)?'.preg_quote($constant->getName(), '/').'\b/', $text) === 1) {
                        $line = $number + 1;
                        break;
                    }
                }
            }

            return [
                'file' => $file,
                'line' => $line,
                'description' => StaticSchemaScanner::summary($constant->getDocComment() ?: null),
            ];
        }

        return [];
    }
}
