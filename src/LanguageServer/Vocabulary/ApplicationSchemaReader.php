<?php

namespace Warrant\LanguageServer\Vocabulary;

use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use Warrant\Schema\Abilities\AbilityDefinition;
use Warrant\Schema\Abilities\IDeclaresAbility;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\RuleTemplates\RuleTemplateDefinition;
use Warrant\Schema\Abilities\IProvidesAbilities;
use Warrant\Schema\Context\IProvidesContext;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;
use Warrant\Schema\Types\Interfaces\ISchema;

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
                if (is_string($key) && is_string($className) && is_subclass_of($className, ISchema::class)) {
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
                isset($schema['model']) ? (string) $schema['model'] : null,
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
                    (bool) ($item['takesContext'] ?? false),
                    (bool) ($item['callOncePerAbility'] ?? false),
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
     * @param class-string<ISchema> $className Its class.
     * @return array<string, mixed>
     */
    private static function describe(string $key, string $className): array
    {
        $class = new ReflectionClass($className);
        $schema = new $className;

        return [
            'class' => $className,
            'file' => $class->getFileName() ?: null,
            'line' => $class->getStartLine() ?: null,
            'description' => StaticSchemaScanner::summary($class->getDocComment() ?: null),
            'hasRows' => $schema instanceof IRowSchema,
            'model' => $schema instanceof IModelSchema ? $schema::model() : null,
            'keyArgumentCount' => $schema instanceof IRowSchema ? $schema->rowMatcher()->requiredArgumentCount : 0,
            'abilities' => array_map(
                static fn (AbilityDefinition $ability): array => [
                    'name' => $ability->name,
                    'requiredContext' => $ability->requiredContext,
                    ...self::constantLocation($class, $ability->name),
                ],
                $schema instanceof IProvidesAbilities ? $schema->abilities() : [],
            ),
            'conditions' => array_map(
                static fn (ConditionDefinition $condition): array => [
                    'name' => $condition->key,
                    'method' => $condition->methodName,
                    'kind' => $condition->kind->name,
                    'requiredArgumentCount' => $condition->requiredArgumentCount,
                    ...self::methodLocation($class, $condition->methodName, $condition->kind === ConditionKind::Derived ? 0 : 1),
                ],
                $schema->conditions(),
            ),
            'templates' => array_map(
                static fn (RuleTemplateDefinition $template): array => [
                    'name' => $template->key,
                    'method' => $template->methodName,
                    'requiredArgumentCount' => $template->requiredArgumentCount,
                    'takesContext' => $template->takesContext,
                    'callOncePerAbility' => $template->callOncePerAbility,
                    ...self::methodLocation($class, $template->methodName, $template->takesContext ? 1 : 0),
                ],
                $schema->ruleTemplates(),
            ),
            'requiredContextKeys' => $schema instanceof IProvidesContext ? $schema->requiredContextKeys() : [],
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
     * line is found in the file of the class that declares it.
     *
     * @param ReflectionClass<object> $class The schema class.
     * @param string $ability The ability's name.
     * @return array{file?: ?string, line?: ?int, description?: ?string}
     */
    private static function constantLocation(ReflectionClass $class, string $ability): array
    {
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getValue() !== $ability || $constant->getAttributes(IDeclaresAbility::class, ReflectionAttribute::IS_INSTANCEOF) === []) {
                continue;
            }

            $pattern = '/\bconst\s+(?:[\w\\\\|?]+\s+)?'.preg_quote($constant->getName(), '/').'\b/';
            $file = $constant->getDeclaringClass()->getFileName() ?: null;
            $line = null;

            foreach (self::declaringCandidates($constant->getDeclaringClass()) as $candidate) {
                $line = self::lineMatching($candidate->getFileName() ?: null, $pattern);

                if ($line !== null) {
                    $file = $candidate->getFileName() ?: null;
                    break;
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

    /**
     * $class and every trait it uses, its own traits' traits included, in the
     * order a constant's declaration is looked for. Reflection names the class
     * using a trait as the declaring class of the trait's constants, so the
     * declaration itself may be in any of these files.
     *
     * @param ReflectionClass<object> $class The constant's declaring class.
     * @return list<ReflectionClass<object>>
     */
    private static function declaringCandidates(ReflectionClass $class): array
    {
        $candidates = [$class];

        foreach ($class->getTraits() as $trait) {
            array_push($candidates, ...self::declaringCandidates($trait));
        }

        return $candidates;
    }

    /**
     * The 1-based number of the first line of $file that $pattern matches, or
     * null when none does or there is no file.
     *
     * @param string|null $file Absolute path.
     * @param string $pattern A regular expression.
     */
    private static function lineMatching(?string $file, string $pattern): ?int
    {
        foreach ($file === null ? [] : (file($file) ?: []) as $number => $text) {
            if (preg_match($pattern, $text) === 1) {
                return $number + 1;
            }
        }

        return null;
    }
}
