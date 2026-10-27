<?php

namespace Warrant\LanguageServer\Vocabulary;

use PhpToken;
use Warrant\LanguageServer\Php\PhpAttribute;
use Warrant\LanguageServer\Php\PhpClass;
use Warrant\LanguageServer\Php\PhpMember;
use Warrant\LanguageServer\Php\PhpSourceReader;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Abilities\AbilityDefinition;
use Warrant\Schema\Abilities\IDeclaresAbility;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Context\RequiredContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\RuleTemplates\RuleTemplateContext;
use Warrant\Schema\RuleTemplates\RuleTemplateDefinition;

/**
 * Reads a project's schemas from its source, without running any of it.
 *
 * The schemas are the ones `config/warrant.php` registers: those keyed under
 * `schemas`, which is the only place a schema key is declared, and the global
 * schemas listed under `global`, in order. Each schema class is read from the file
 * Composer's autoload maps place it in, along with the classes it extends and the
 * traits it uses, and its vocabulary is read from its attributes as the schema
 * class reads it by reflection: abilities from constants carrying an ability
 * attribute, conditions and templates from public methods carrying theirs.
 *
 * What only running the code can tell is left out: an ability whose name is a
 * computed constant, the required context of an ability attribute of the
 * project's own, how many columns a query schema's `key()` returns, and a schema
 * whose class cannot be found.
 */
final class StaticSchemaScanner
{
    /**
     * The library's schema classes, the ones a project's schema extends: which one
     * it reaches says whether, and how, it has rows. They declare no vocabulary of
     * their own, so their members are never read.
     */
    private const SCHEMA_TYPES = [
        'warrant\\schema\\types\\classes\\modelschema' => 'model',
        'warrant\\schema\\types\\classes\\queryschema' => 'query',
        'warrant\\schema\\types\\classes\\rowlessschema' => 'rowless',
        'warrant\\schema\\types\\classes\\globalschema' => 'global',
    ];

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
     * Every schema the project registers that can be read. A class listed under
     * `global` is indexed only when its source shows it extends GlobalSchema, so
     * every global the index holds is one.
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

        $globals = [];

        foreach ($this->globalSchemas() as $className) {
            $class = $this->classNamed($className);

            if ($class !== null && $this->schemaTypeOf($class) === 'global') {
                $globals[] = $this->schemaOf(null, $class);
            }
        }

        return new VocabularyIndex($schemas, $globals);
    }

    /**
     * The `schemas` map of the project's `config/warrant.php`: each key, and the
     * class registered under it.
     *
     * @return array<string, string>
     */
    public function registeredSchemas(): array
    {
        $schemas = [];

        foreach ($this->configEntries('schemas') as [$key, $className]) {
            if ($key !== null) {
                $schemas[$key] = $className;
            }
        }

        return $schemas;
    }

    /**
     * The `global` list of the project's `config/warrant.php`: each class, in the
     * order it is listed.
     *
     * @return list<string>
     */
    public function globalSchemas(): array
    {
        return array_map(static fn (array $entry): string => $entry[1], $this->configEntries('global'));
    }

    /**
     * The `Class::class` entries of the array the project's `config/warrant.php`
     * gives under $name, in order: each with the key it is written under, or null
     * for an entry written with none.
     *
     * @param string $name The config key, `schemas` or `global`.
     * @return list<array{0: ?string, 1: string}>
     */
    private function configEntries(string $name): array
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
                $imported = ltrim($tokens[$index + 1]->text, '\\');
                $alias = ($tokens[$index + 2]->text ?? null) === 'as' ? $tokens[$index + 3]->text : substr((string) strrchr('\\'.$imported, '\\'), 1);
                $imports[strtolower($alias)] = $imported;
            }
        }

        $start = null;

        foreach ($tokens as $index => $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING)
                && PhpSourceReader::unquote($token->text) === $name
                && ($tokens[$index + 1]->text ?? null) === '=>'
            ) {
                $start = $index + 2;
                break;
            }
        }

        if ($start === null) {
            return [];
        }

        $entries = [];
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
                && ($tokens[$i + 1]->text ?? null) === '::'
                && strtolower($tokens[$i + 2]->text ?? '') === 'class'
                && $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            ) {
                $keyed = ($tokens[$i - 1]->text ?? null) === '=>' && ($tokens[$i - 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING);
                $written = $tokens[$i]->text;
                $segments = explode('\\', ltrim($written, '\\'), 2);
                $imported = str_starts_with($written, '\\') ? null : ($imports[strtolower($segments[0])] ?? null);

                $entries[] = [
                    $keyed ? PhpSourceReader::unquote($tokens[$i - 2]->text) : null,
                    $imported === null ? ltrim($written, '\\') : $imported.(isset($segments[1]) ? '\\'.$segments[1] : ''),
                ];
            }
        }

        return $entries;
    }

    /**
     * The schema $class declares, registered under $key.
     *
     * @param string|null $key The key it is registered under; null for a global
     *   schema, which has none.
     * @param PhpClass $class The schema class.
     */
    private function schemaOf(?string $key, PhpClass $class): IndexedSchema
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

                /* A template may take a context first, which rule text does not
                   pass. */
                $takesContext = ($method->parameters[0] ?? null)?->type === RuleTemplateContext::class;
                $dslParameters = $takesContext ? array_slice($method->parameters, 1) : $method->parameters;

                $templates[$templateKey] = new Declaration(
                    $templateKey,
                    new RuleTemplateDefinition(
                        $templateKey,
                        $method->name,
                        max(0, $method->requiredParameterCount() - ($takesContext ? 1 : 0)),
                        $takesContext,
                        $template->argument('callOncePerAbility', 1) === true,
                        self::dslArgumentLimit($method->maxParameterCount(), $takesContext ? 1 : 0),
                    ),
                    $owner->file,
                    $method->line,
                    self::summary($method->docComment),
                    array_map(static fn ($parameter): string => $parameter->name, $dslParameters),
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
                    self::dslArgumentLimit($method->maxParameterCount(), $kind === ConditionKind::Derived ? 0 : 1),
                ),
                $owner->file,
                $method->line,
                self::summary($method->docComment),
                array_map(static fn ($parameter): string => $parameter->name, $dslParameters),
            );
        }

        $type = $this->schemaTypeOf($class);
        $model = $type === 'model' ? $this->constantValueNamed($class, 'model') : null;
        $matchKey = $methods['matchkey'][1] ?? null;

        /* A row is found by matchKey() when the schema declares one, and otherwise
           by its key: a model's is one column, and a query schema's is however many
           its key() returns, which only running it can tell, so none is required
           here rather than a guess. */
        $keyArgumentCount = match (true) {
            $matchKey !== null => max(0, $matchKey->requiredParameterCount() - 1),
            $type === 'model' => 1,
            default => 0,
        };

        return new IndexedSchema(
            $key,
            $class->name,
            $class->file,
            $class->line,
            self::summary($class->docComment),
            $type === 'model' || $type === 'query',
            is_string($model) && $model !== '' ? $model : null,
            $keyArgumentCount,
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
     * read from the library's schema classes, which declare no vocabulary.
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
            if (str_starts_with(strtolower(ltrim($ancestorName, '\\')), 'warrant\\schema\\')) {
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
     * Which of the library's schema classes $class extends, following its parents
     * through the project's source: 'model', 'query', 'rowless' or 'global'. Null
     * when the chain leaves the project's source before reaching one.
     *
     * @param PhpClass $class The schema class.
     * @param int $depth How many parents have been followed, so a cycle ends.
     */
    private function schemaTypeOf(PhpClass $class, int $depth = 0): ?string
    {
        if ($class->parent === null || $depth > 16) {
            return null;
        }

        $type = self::SCHEMA_TYPES[strtolower(ltrim($class->parent, '\\'))] ?? null;

        if ($type !== null) {
            return $type;
        }

        $parent = $this->classNamed($class->parent);

        return $parent === null ? null : $this->schemaTypeOf($parent, $depth + 1);
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
     * attribute of the project's own that implements {@see IDeclaresAbility}.
     *
     * @param PhpMember $constant A class constant.
     */
    private function abilityAttribute(PhpMember $constant): ?PhpAttribute
    {
        foreach ($constant->attributes as $attribute) {
            if ($attribute->name === Ability::class || $this->implements($attribute->name, IDeclaresAbility::class)) {
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
     * The most DSL arguments a method takes, given the most parameters it does
     * and how many of those come before rule text's arguments; null when it
     * takes any number.
     *
     * @param int|null $maxParameters The method's parameters, or null when the
     *   last is variadic.
     * @param int $leading How many parameters rule text does not pass.
     */
    private static function dslArgumentLimit(?int $maxParameters, int $leading): ?int
    {
        return $maxParameters === null ? null : max(0, $maxParameters - $leading);
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
