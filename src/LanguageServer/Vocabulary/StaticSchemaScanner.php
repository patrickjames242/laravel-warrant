<?php

namespace Warrant\LanguageServer\Vocabulary;

use PhpToken;
use Warrant\LanguageServer\Php\PhpAttribute;
use Warrant\LanguageServer\Php\PhpClass;
use Warrant\LanguageServer\Php\PhpMember;
use Warrant\LanguageServer\Php\PhpSourceReader;
use Warrant\Schema\Ability;
use Warrant\Schema\AbilityDefinition;
use Warrant\Schema\ConditionDefinition;
use Warrant\Schema\ConditionKind;
use Warrant\Schema\DeclaresAbility;
use Warrant\Schema\DerivedCondition;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RequiredContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\RuleTemplate;
use Warrant\Schema\RuleTemplateDefinition;
use Warrant\Schema\WarrantSchema;

/**
 * Reads a project's schemas from its source, without running any of it.
 *
 * The schemas are the ones `config/warrant.php` registers, which is the only
 * place a schema key is declared. Each schema class is read from the file
 * Composer's autoload maps place it in, along with the classes it extends and the
 * traits it uses, and its vocabulary is read from its attributes as the schema
 * class reads it by reflection: abilities from constants carrying an ability
 * attribute, conditions and templates from public methods carrying theirs.
 *
 * What only running the code can tell is left out: an ability whose name is a
 * computed constant, the required context of an ability attribute of the
 * project's own, and a schema whose class cannot be found.
 */
final class StaticSchemaScanner
{
    /** @var array<string, ?PhpClass> Classes read so far, by lower-cased name. */
    private array $classes = [];

    /**
     * @param string $root Absolute path of the project.
     * @param ClassFileLocator $locator Where the project's classes are declared.
     */
    public function __construct(
        private readonly string $root,
        private readonly ClassFileLocator $locator,
    ) {
    }

    /**
     * Every schema the project registers that can be read.
     */
    public function scan(): VocabularyIndex
    {
        $schemas = [];

        foreach ($this->registeredSchemas() as $key => $className) {
            $class = $this->classNamed($className);

            if ($class !== null) {
                $schemas[$key] = $this->schemaOf($key, $class);
            }
        }

        return new VocabularyIndex($schemas);
    }

    /**
     * The `schemas` map of the project's `config/warrant.php`: each key, and the
     * class registered under it.
     *
     * @return array<string, string>
     */
    public function registeredSchemas(): array
    {
        $path = $this->root.'/config/warrant.php';

        if (! is_file($path)) {
            return [];
        }

        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($path)),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $imports = [];

        foreach ($tokens as $index => $token) {
            if ($token->is(T_USE) && isset($tokens[$index + 1]) && $tokens[$index + 1]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
                $name = ltrim($tokens[$index + 1]->text, '\\');
                $alias = ($tokens[$index + 2]->text ?? null) === 'as' ? $tokens[$index + 3]->text : substr((string) strrchr('\\'.$name, '\\'), 1);
                $imports[strtolower($alias)] = $name;
            }
        }

        $start = null;

        foreach ($tokens as $index => $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)
                && PhpSourceReader::unquote($token->text) === 'schemas'
                && ($tokens[$index + 1]->text ?? null) === '=>'
            ) {
                $start = $index + 2;
                break;
            }
        }

        if ($start === null) {
            return [];
        }

        $schemas = [];
        $depth = 0;

        for ($i = $start; $i < count($tokens); $i++) {
            $text = $tokens[$i]->text;

            if ($text === '[' || $text === '(') {
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($depth === 1
                && $tokens[$i]->is(T_CONSTANT_ENCAPSED_STRING)
                && ($tokens[$i + 1]->text ?? null) === '=>'
                && ($tokens[$i + 3]->text ?? null) === '::'
                && strtolower($tokens[$i + 4]->text ?? '') === 'class'
            ) {
                $written = $tokens[$i + 2]->text;
                $segments = explode('\\', ltrim($written, '\\'), 2);
                $imported = str_starts_with($written, '\\') ? null : ($imports[strtolower($segments[0])] ?? null);

                $schemas[PhpSourceReader::unquote($tokens[$i]->text)] = $imported === null
                    ? ltrim($written, '\\')
                    : $imported.(isset($segments[1]) ? '\\'.$segments[1] : '');
            }
        }

        return $schemas;
    }

    /**
     * The schema $class declares, registered under $key.
     *
     * @param string $key The key it is registered under.
     * @param PhpClass $class The schema class.
     */
    private function schemaOf(string $key, PhpClass $class): IndexedSchema
    {
        $constants = $this->members($class, 'constants');
        $methods = $this->members($class, 'methods');

        $abilities = [];
        $requiredContext = [];

        foreach ($constants as [$owner, $constant]) {
            $value = $this->constantValue($owner, $constant);

            if (! is_string($value)) {
                continue;
            }

            if ($constant->attribute([RequiredContext::class]) !== null) {
                $requiredContext[] = $value;
            }

            $attribute = $this->abilityAttribute($constant);

            if ($attribute !== null) {
                $context = $attribute->name === Ability::class ? $attribute->argument('requiredContext', 0) : [];

                $abilities[$value] = new Declaration(
                    $value,
                    new AbilityDefinition($value, is_array($context) ? array_values(array_filter($context, 'is_string')) : []),
                    $owner->file,
                    $constant->line,
                    self::summary($constant->docComment),
                );
            }
        }

        $conditions = [];
        $templates = [];

        foreach ($methods as [$owner, $method]) {
            if (! $method->isPublic || $method->isStatic) {
                continue;
            }

            $template = $method->attribute([RuleTemplate::class]);

            if ($template !== null) {
                $templateKey = self::keyOf($template->argument('key', 0), $method->name);
                $templates[$templateKey] = new Declaration(
                    $templateKey,
                    new RuleTemplateDefinition($templateKey, $method->name, $method->requiredParameterCount()),
                    $owner->file,
                    $method->line,
                    self::summary($method->docComment),
                    array_map(static fn ($parameter): string => $parameter->name, $method->parameters),
                );

                continue;
            }

            $kind = match (true) {
                $method->attribute([RowCondition::class]) !== null => ConditionKind::Row,
                $method->attribute([GlobalCondition::class]) !== null => ConditionKind::Global,
                $method->attribute([DerivedCondition::class]) !== null => ConditionKind::Derived,
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            $attribute = $method->attribute([RowCondition::class, GlobalCondition::class, DerivedCondition::class]);
            $conditionKey = self::keyOf($attribute?->argument('key', 0), $method->name);

            /* A row or global condition is handed its context object first, and rule
               text passes the parameters after it; a derived condition is handed
               rule text's arguments alone. */
            $dslParameters = $kind === ConditionKind::Derived ? $method->parameters : array_slice($method->parameters, 1);

            $conditions[$conditionKey] = new Declaration(
                $conditionKey,
                new ConditionDefinition(
                    $conditionKey,
                    $method->name,
                    $kind,
                    $kind === ConditionKind::Derived ? $method->requiredParameterCount() : max(0, $method->requiredParameterCount() - 1),
                ),
                $owner->file,
                $method->line,
                self::summary($method->docComment),
                array_map(static fn ($parameter): string => $parameter->name, $dslParameters),
            );
        }

        $model = $this->constantValueNamed($class, 'model');
        $hasVirtualTable = isset($methods['virtualtable']);
        $hasRows = (is_string($model) && $model !== '') || $hasVirtualTable;
        $rowKey = $this->constantValueNamed($class, 'key');
        $matchKey = $methods['matchkey'][1] ?? null;

        return new IndexedSchema(
            $key,
            $class->name,
            $class->file,
            $class->line,
            self::summary($class->docComment),
            $hasRows,
            (is_string($model) && $model !== '') || (is_string($rowKey) && $rowKey !== '') || $matchKey !== null,
            $matchKey === null ? 1 : max(0, $matchKey->requiredParameterCount() - 1),
            $abilities,
            $conditions,
            $templates,
            array_values(array_unique($requiredContext)),
        );
    }

    /**
     * Every constant or method $class has, its own and those of the classes it
     * extends and the traits it uses, each with the class that declares it. A
     * class's own member hides one of the same name further up, and nothing is
     * read from {@see WarrantSchema} itself, which declares no vocabulary.
     *
     * @param PhpClass $class The class to read.
     * @param 'constants'|'methods' $kind Which members.
     * @param array<string, true> $seen The classes already read, so a cycle in
     *   broken code ends.
     * @return array<string, array{0: PhpClass, 1: PhpMember}> By lower-cased name
     *   for methods, by name for constants.
     */
    private function members(PhpClass $class, string $kind, array $seen = []): array
    {
        if (isset($seen[strtolower($class->name)])) {
            return [];
        }

        $seen[strtolower($class->name)] = true;
        $members = [];

        $ancestors = [...($class->parent === null ? [] : [$class->parent]), ...$class->traits];

        foreach ($ancestors as $ancestorName) {
            if (strcasecmp($ancestorName, WarrantSchema::class) === 0) {
                continue;
            }

            $ancestor = $this->classNamed($ancestorName);

            if ($ancestor !== null) {
                $members = [...$members, ...$this->members($ancestor, $kind, $seen)];
            }
        }

        foreach ($class->{$kind} as $name => $member) {
            $members[$kind === 'methods' ? strtolower($name) : $name] = [$class, $member];
        }

        return $members;
    }

    /**
     * The value of $constant, declared on $owner, following a reference to
     * another class's constant.
     *
     * @param PhpClass $owner The class that declares it.
     * @param PhpMember $constant The constant.
     * @param int $depth How many references have been followed, so a cycle ends.
     */
    private function constantValue(PhpClass $owner, PhpMember $constant, int $depth = 0): mixed
    {
        $value = $constant->value;

        if (! is_array($value) || ! isset($value['class'], $value['constant']) || $depth > 8) {
            return $value;
        }

        $target = in_array($value['class'], ['self', 'static'], true) ? $owner : $this->classNamed($value['class']);

        if ($target === null) {
            return null;
        }

        $found = $this->members($target, 'constants')[$value['constant']] ?? null;

        return $found === null ? null : $this->constantValue($found[0], $found[1], $depth + 1);
    }

    /**
     * The value of the constant $name as $class sees it.
     *
     * @param PhpClass $class The class.
     * @param string $name The constant's name.
     */
    private function constantValueNamed(PhpClass $class, string $name): mixed
    {
        $found = $this->members($class, 'constants')[$name] ?? null;

        return $found === null ? null : $this->constantValue($found[0], $found[1]);
    }

    /**
     * The attribute declaring $constant an ability: {@see Ability}, or an
     * attribute of the project's own that implements {@see DeclaresAbility}.
     *
     * @param PhpMember $constant A class constant.
     */
    private function abilityAttribute(PhpMember $constant): ?PhpAttribute
    {
        foreach ($constant->attributes as $attribute) {
            if ($attribute->name === Ability::class || $this->implements($attribute->name, DeclaresAbility::class)) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Whether the class $className implements $interface, as far as its source
     * can be read.
     *
     * @param string $className The class.
     * @param string $interface The interface, fully qualified.
     */
    private function implements(string $className, string $interface): bool
    {
        $class = $this->classNamed($className);

        if ($class === null) {
            return false;
        }

        foreach ($class->interfaces as $implemented) {
            if (strcasecmp($implemented, $interface) === 0) {
                return true;
            }
        }

        return $class->parent !== null && $this->implements($class->parent, $interface);
    }

    /**
     * The class $className, read from the file the project's autoload maps place
     * it in; null when it cannot be found or read.
     *
     * @param string $className A fully qualified class name.
     */
    private function classNamed(string $className): ?PhpClass
    {
        $lower = strtolower(ltrim($className, '\\'));

        if (array_key_exists($lower, $this->classes)) {
            return $this->classes[$lower];
        }

        $this->classes[$lower] = null;
        $file = $this->locator->fileOf($className);

        if ($file === null) {
            return null;
        }

        $file = realpath($file) ?: $file;

        foreach (PhpSourceReader::read($file, (string) @file_get_contents($file)) as $class) {
            $this->classes[strtolower($class->name)] = $class;
        }

        return $this->classes[$lower];
    }

    /**
     * The key an attribute gives, or else the one the method's name gives: its
     * name in snake case, as the schema class derives it.
     *
     * @param mixed $given The attribute's `key` argument.
     * @param string $methodName The method's name.
     */
    private static function keyOf(mixed $given, string $methodName): string
    {
        if (is_string($given) && $given !== '') {
            return $given;
        }

        return strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $methodName));
    }

    /**
     * The text of a docblock before its first tag, without its comment markers.
     *
     * @param string|null $docComment The docblock as written.
     */
    public static function summary(?string $docComment): ?string
    {
        if ($docComment === null) {
            return null;
        }

        $lines = [];

        foreach (preg_split('/\R/', $docComment) ?: [] as $line) {
            $line = (string) preg_replace('#^\s*/\*\*\s?|\s*\*/\s*$|^\s*\*\s?#', '', $line);

            if (str_starts_with(ltrim($line), '@')) {
                break;
            }

            $lines[] = rtrim($line);
        }

        $summary = trim(implode("\n", $lines));

        return $summary === '' ? null : $summary;
    }
}
