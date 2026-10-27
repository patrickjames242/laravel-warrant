<?php

namespace Warrant\LanguageServer\Php;

/**
 * A constant or method of a class, read from source.
 */
final readonly class PhpMember
{
    /**
     * @param string $name The constant's or method's name.
     * @param int $line 1-based line its name is on.
     * @param string|null $docComment Its docblock, as written.
     * @param list<PhpAttribute> $attributes Its attributes.
     * @param mixed $value For a constant, its value as {@see PhpSourceReader::literal()}
     *   reads it; null for a method.
     * @param bool $isPublic Whether it is public, as a member with no visibility is.
     * @param bool $isStatic Whether a method is static.
     * @param list<PhpParameter> $parameters A method's parameters; empty for a
     *   constant.
     */
    public function __construct(
        public string $name,
        public int $line,
        public ?string $docComment = null,
        public array $attributes = [],
        public mixed $value = null,
        public bool $isPublic = true,
        public bool $isStatic = false,
        public array $parameters = [],
    ) {
    }

    /**
     * The first attribute of one of the $names classes, or null.
     *
     * @param list<string> $names Attribute class names, without a leading backslash.
     */
    public function attribute(array $names): ?PhpAttribute
    {
        foreach ($this->attributes as $attribute) {
            if (in_array($attribute->name, $names, true)) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * How many parameters a call must pass: those before the first that is
     * optional or variadic.
     */
    public function requiredParameterCount(): int
    {
        $count = 0;

        foreach ($this->parameters as $parameter) {
            if ($parameter->isOptional) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * How many parameters a call may pass at most: all of them, or null when the
     * last is variadic and takes any number.
     */
    public function maxParameterCount(): ?int
    {
        $last = $this->parameters[array_key_last($this->parameters) ?? 0] ?? null;

        return $last?->isVariadic === true ? null : count($this->parameters);
    }
}
