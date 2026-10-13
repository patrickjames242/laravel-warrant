<?php

namespace Warrant\LanguageServer\Positions;

/**
 * The names in scope at one place in rule text: the schema whose conditions,
 * abilities and context keys it names, and the frames a `@column` there can name.
 *
 * Frames follow {@see \Warrant\DSL\Compiling\AliasScope}: a rule's own schema is
 * one frame, under its own key, and a `check(...)` predicate keeps the frames
 * around it and adds its target's on top, under the handle's alias when it has
 * one, else under the target's key, shadowing any frame of that name further out.
 * A schema with no rows is no frame at all.
 */
final readonly class CursorScope
{
    /**
     * @param string|null $schemaKey The schema the text here is about; null when
     *   that is not known, as in rules with no `for` header read with no schema
     *   given, or in a `check(...)` whose handle names no schema.
     * @param array<string, string> $frames Each name a `@column` here may give
     *   before its `.`, mapped to the key of the schema whose rows it names,
     *   innermost last.
     * @param string|null $rowSchemaKey The schema whose rows a `@column` with no
     *   frame name is about; null when the text here is about no rows.
     */
    public function __construct(
        public ?string $schemaKey = null,
        public array $frames = [],
        public ?string $rowSchemaKey = null,
    ) {
    }

    /**
     * The scope of text written directly in a body about $schemaKey: that
     * schema's own frame and nothing else.
     *
     * @param string|null $schemaKey The schema the body is about, or null when
     *   that is not known.
     * @param bool $hasRows Whether that schema has rows to be a frame.
     */
    public static function root(?string $schemaKey, bool $hasRows): self
    {
        if ($schemaKey === null || ! $hasRows) {
            return new self($schemaKey);
        }

        return new self($schemaKey, [$schemaKey => $schemaKey], $schemaKey);
    }

    /**
     * The scope of a `check(...)` predicate written here, over $schemaKey: this
     * scope's frames, with the target's added on top.
     *
     * @param string|null $schemaKey The schema the handle names, or null when it
     *   names none.
     * @param string|null $alias The name the handle gives its rows after `as`.
     * @param bool $hasRows Whether the target has rows to be a frame.
     */
    public function enteringPredicate(?string $schemaKey, ?string $alias, bool $hasRows): self
    {
        if ($schemaKey === null || ! $hasRows) {
            return new self($schemaKey, $this->frames);
        }

        $frames = $this->frames;
        $name = $alias ?? $schemaKey;
        unset($frames[$name]);
        $frames[$name] = $schemaKey;

        return new self($schemaKey, $frames, $schemaKey);
    }
}
