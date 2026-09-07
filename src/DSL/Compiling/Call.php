<?php

namespace Warrant\DSL\Compiling;

use Throwable;
use Warrant\DSL\Parsing\ASTNodes\ColumnRef;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\Facades\Warrant;

/**
 * One entry on the compiler's {@see CallStack} — a layer the compile descended
 * through on its way to the point it is at now.
 *
 * A rule string shows an author one hop (`if can(view for folders(...)) they can
 * view`), but reaching that ability may run through a condition that expands into
 * an expression, which contains a `check(...)` into a third schema, whose rules
 * reference a fourth ability. Those intermediate layers are invisible in the rules
 * and are exactly what an author needs to see when a compile cycles or runs away,
 * which is why the stack records them all in call order rather than only the
 * ability hops.
 *
 * Identity is the schema *class*, not its key: the class names the schema without
 * a registry lookup, so the hot path stays free of one. The key is resolved only
 * when a call is rendered into an exception message ({@see schemaLabel()}).
 *
 * Arguments are display strings rendered from the *unresolved* DSL argument nodes
 * ({@see describeArgument()}), so `@column folders.parent_id` reads as itself
 * rather than as the wrapped SQL it resolves to. They exist for the message and
 * for {@see repeats()}, which the depth renderer uses to spot — and report — a
 * repeating segment the compiler declined to reject.
 */
final readonly class Call
{
    /**
     * @param class-string $schemaClass The schema (its {@see \Warrant\DSL\ConditionResolver}) this call was made against.
     * @param string $name The ability, condition key, or '' for a cross-schema check.
     * @param list<string> $arguments Rendered DSL arguments, display-only.
     */
    private function __construct(
        public CallKind $kind,
        public string $schemaClass,
        public string $name,
        public array $arguments = [],
    ) {
    }

    /** @param class-string $schemaClass */
    public static function ability(string $schemaClass, string $ability): self
    {
        return new self(CallKind::Ability, $schemaClass, $ability);
    }

    /** @param class-string $schemaClass */
    public static function check(string $schemaClass): self
    {
        return new self(CallKind::Check, $schemaClass, '');
    }

    /**
     * @param class-string $schemaClass
     * @param array<int, mixed> $arguments The condition's *unresolved* DSL arguments.
     */
    public static function condition(string $schemaClass, string $condition, array $arguments = []): self
    {
        return new self(
            CallKind::Condition,
            $schemaClass,
            $condition,
            array_map(self::describeArgument(...), array_values($arguments)),
        );
    }

    /**
     * Whether this call is the same call as $other — the same kind, against the
     * same schema, with the same name and the same rendered arguments.
     *
     * Used two ways: as the cycle test for an {@see CallKind::Ability} (where it is
     * enforced), and to spot a repeating segment when rendering a depth trace
     * (where it is only reported).
     */
    public function repeats(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->schemaClass === $other->schemaClass
            && $this->name === $other->name
            && $this->arguments === $other->arguments;
    }

    /**
     * The call as it reads in a stack trace, e.g. `folders:view`,
     * `check folders`, `folders.is_visible(@column folders.parent_id)`.
     */
    public function signature(): string
    {
        $schema = $this->schemaLabel();

        $call = match ($this->kind) {
            CallKind::Ability => "{$schema}:{$this->name}",
            CallKind::Check => "check {$schema}",
            CallKind::Condition => "{$schema}.{$this->name}",
        };

        return $this->arguments === []
            ? $call
            : $call.'('.implode(', ', $this->arguments).')';
    }

    /**
     * The schema's registered key, falling back to its class.
     *
     * The lookup needs a booted application and throws for an unregistered
     * schema, which is why it happens here — while building an error message —
     * and never on the compile path. Rendering an exception must not itself
     * fail, so any failure degrades to the class string.
     */
    private function schemaLabel(): string
    {
        try {
            return Warrant::registry()->resolveSchemaKeyOrFail($this->schemaClass);
        } catch (Throwable) {
            return $this->schemaClass;
        }
    }

    /**
     * Render one DSL argument for display: a reference as the reference an author
     * wrote, anything else as its literal value, truncated.
     */
    private static function describeArgument(mixed $value): string
    {
        if ($value instanceof ContextRef) {
            return '@context '.$value->key;
        }

        if ($value instanceof ColumnRef) {
            return '@column '.$value->schemaKey.'.'.$value->column;
        }

        if ($value instanceof SqlRef) {
            return '@sql';
        }

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'".self::truncate($value)."'",
            is_object($value) => $value::class,
            default => get_debug_type($value),
        };
    }

    private static function truncate(string $value, int $max = 40): string
    {
        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 1).'…';
    }
}
