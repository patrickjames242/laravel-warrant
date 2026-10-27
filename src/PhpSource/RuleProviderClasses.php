<?php

namespace Warrant\PhpSource;

use Warrant\Rules\IRuleProvider;
use Warrant\Schema\Rules\IProvidesRules;

/**
 * Which classes provide rules: a schema, which implements {@see IProvidesRules},
 * or a rule provider, which implements {@see IRuleProvider}, directly or through
 * the classes and interfaces it extends.
 *
 * A project's classes are read from source, without loading them: a class's
 * parent and interfaces are found through the project's Composer autoload maps
 * and read in turn. This library's own classes are asked of PHP, which loads
 * them wherever this code runs. A class whose declaration cannot be found
 * provides no rules.
 */
final class RuleProviderClasses
{
    /** The interfaces a class providing rules implements. */
    private const PROVIDERS = [IProvidesRules::class, IRuleProvider::class];

    /** How many classes up a chain of parents is followed. */
    private const MOST_ANCESTORS = 32;

    /** @var array<string, PhpClass|null> Each class read, by its lower-cased name; null when not found. */
    private array $classes = [];

    /** @var array<string, bool> Each answer given, by lower-cased class name. */
    private array $answers = [];

    public function __construct(private readonly ClassFileLocator $locator = new ClassFileLocator)
    {
    }

    /**
     * The classes of the project at $root, through the maps Composer generated
     * there; none of the project's when $root is null.
     */
    public static function ofProject(?string $root): self
    {
        return new self($root === null ? new ClassFileLocator : ClassFileLocator::forProject($root));
    }

    /**
     * Read the classes $code declares as the ones at $path, ahead of the copy on
     * disk, which may be older: the text of a file being edited or formatted.
     */
    public function knowing(string $path, string $code): self
    {
        foreach (PhpSourceReader::read($path, $code) as $class) {
            $this->classes[strtolower($class->name)] = $class;
        }

        $this->answers = [];

        return $this;
    }

    /**
     * Whether $className provides rules.
     */
    public function providesRules(string $className): bool
    {
        $className = ltrim($className, '\\');

        return $this->answers[strtolower($className)] ??= $this->ancestryProvidesRules($className, 0);
    }

    /**
     * Whether $className, or a class or interface it extends or implements,
     * is one of {@see PROVIDERS}. Names keep their case, which autoloading and
     * the Composer maps go by.
     */
    private function ancestryProvidesRules(string $className, int $depth): bool
    {
        if ($depth > self::MOST_ANCESTORS) {
            return false;
        }

        if (str_starts_with(strtolower($className), 'warrant\\')) {
            foreach (self::PROVIDERS as $provider) {
                if (is_a($className, $provider, true)) {
                    return true;
                }
            }

            return false;
        }

        $class = $this->classNamed($className);

        if ($class === null) {
            return false;
        }

        foreach ([...($class->parent === null ? [] : [$class->parent]), ...$class->interfaces] as $ancestor) {
            if ($this->ancestryProvidesRules(ltrim($ancestor, '\\'), $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function classNamed(string $className): ?PhpClass
    {
        $lower = strtolower($className);

        if (array_key_exists($lower, $this->classes)) {
            return $this->classes[$lower];
        }

        $this->classes[$lower] = null;
        $file = $this->locator->fileOf($className);

        if ($file === null || ! is_file($file)) {
            return null;
        }

        foreach (PhpSourceReader::read($file, (string) file_get_contents($file)) as $class) {
            $this->classes[strtolower($class->name)] ??= $class;
        }

        return $this->classes[$lower];
    }
}
