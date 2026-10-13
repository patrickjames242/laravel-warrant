<?php

namespace Warrant\LanguageServer\Php;

/**
 * A class, trait or interface, read from source without being loaded.
 */
final readonly class PhpClass
{
    /**
     * @param string $name Its fully qualified name, without a leading backslash.
     * @param string $file Absolute path of the file it is declared in.
     * @param int $line 1-based line its name is on.
     * @param string|null $docComment Its docblock, as written.
     * @param string|null $parent The class it extends, fully qualified.
     * @param list<string> $interfaces The interfaces it implements, fully qualified.
     * @param list<string> $traits The traits it uses, fully qualified.
     * @param array<string, PhpMember> $constants By name.
     * @param array<string, PhpMember> $methods By name.
     */
    public function __construct(
        public string $name,
        public string $file,
        public int $line,
        public ?string $docComment = null,
        public ?string $parent = null,
        public array $interfaces = [],
        public array $traits = [],
        public array $constants = [],
        public array $methods = [],
    ) {
    }
}
