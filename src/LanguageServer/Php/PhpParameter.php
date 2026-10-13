<?php

namespace Warrant\LanguageServer\Php;

/**
 * One parameter of a method, read from source.
 */
final readonly class PhpParameter
{
    /**
     * @param string $name Its name, without the `$`.
     * @param string|null $type Its declared type as written, a class name resolved
     *   against the file's namespace and imports; null when it declares none.
     * @param bool $isOptional Whether a call may leave it out: it has a default,
     *   or is variadic.
     */
    public function __construct(
        public string $name,
        public ?string $type = null,
        public bool $isOptional = false,
    ) {
    }
}
