<?php

namespace Warrant\RuleSyntaxTree;

use ArrayIterator;
use Countable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use IteratorAggregate;
use RuntimeException;
use Traversable;
use Warrant\Facades\Warrant;
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;
use Warrant\Schema\WarrantSchema;

/**
 * An ordered collection of {@see WarrantRuleSet}s — one merged set per distinct
 * schema — authored together in a single string or `.warrant` file of
 * `for <schema> { ... }` blocks.
 *
 * Blocks that target the same schema are merged (rules concatenated in source
 * order), so the group holds at most one set per schema key.
 *
 * @implements IteratorAggregate<int, WarrantRuleSet>
 */
readonly class RuleSetGroup implements IteratorAggregate, Countable
{
    /**
     * @param list<WarrantRuleSet> $ruleSets one merged set per distinct schema,
     *   in first-appearance order
     */
    public function __construct(public array $ruleSets)
    {
    }

    /**
     * Parse a group of `for <schema> { ... }` blocks (header and braces mandatory
     * on every block), resolving any :name/? placeholders against $bindings.
     * Same-schema blocks are merged into a single set.
     */
    public static function fromSyntax(string $syntax, array $bindings = []): self
    {
        return self::fromParsedRuleSets(WarrantParser::parseGroup($syntax, $bindings));
    }

    /**
     * Read a `.warrant` file from disk and parse it as a group.
     */
    public static function fromFile(string $path, array $bindings = []): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf('Cannot read Warrant rule file [%s].', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Failed to read Warrant rule file [%s].', $path));
        }

        return self::fromSyntax($contents, $bindings);
    }

    /**
     * Build a group from already-resolved rule sets. Accepts a variadic list or
     * arrays; sets sharing a schema key are merged in first-appearance order.
     *
     * @param WarrantRuleSet|array<int, WarrantRuleSet> ...$ruleSets
     */
    public static function fromRuleSets(WarrantRuleSet|array ...$ruleSets): self
    {
        /** @var array<string, WarrantRuleSet> $byKey */
        $byKey = [];

        foreach ($ruleSets as $ruleSet) {
            foreach (is_array($ruleSet) ? $ruleSet : [$ruleSet] as $one) {
                if (! $one instanceof WarrantRuleSet) {
                    throw new InvalidArgumentException(sprintf(
                        'fromRuleSets expects WarrantRuleSet instances, got %s.',
                        get_debug_type($one),
                    ));
                }

                $byKey[$one->schemaKey] = isset($byKey[$one->schemaKey])
                    ? $byKey[$one->schemaKey]->mergeWith($one)
                    : $one;
            }
        }

        return new self(array_values($byKey));
    }

    /**
     * The single merged rule set for a schema, or null if the group has none.
     *
     * @param Model|WarrantSchema|string $schema
     */
    public function forSchema(Model|WarrantSchema|string $schema): ?WarrantRuleSet
    {
        $schemaKey = Warrant::resolveSchemaKey($schema);

        foreach ($this->ruleSets as $ruleSet) {
            if ($ruleSet->schemaKey === $schemaKey) {
                return $ruleSet;
            }
        }

        return null;
    }

    /**
     * The distinct schema keys in the group, in order.
     *
     * @return list<string>
     */
    public function schemaKeys(): array
    {
        return array_map(static fn (WarrantRuleSet $set): string => $set->schemaKey, $this->ruleSets);
    }

    /**
     * Render the whole group back to the string DSL as `for <schema> { ... }`
     * blocks. Round-trips via {@see fromSyntax()}.
     */
    public function toSyntax(): string
    {
        return RuleSyntaxWriter::groupToSyntax($this);
    }

    /**
     * Render the group to `?`-parameterized syntax plus one flat, left-to-right
     * positional bindings list spanning every block. Round-trips via
     * `RuleSetGroup::fromSyntax($result->syntax, $result->bindings)`.
     */
    public function toBoundSyntax(): BoundSyntax
    {
        return RuleSyntaxWriter::groupToBoundSyntax($this);
    }

    /**
     * Validate every rule set against the schema registered for its schema key.
     * Throws on the first unknown name across the whole group.
     */
    public function validate(): void
    {
        WarrantRuleSet::validateAll($this->ruleSets);
    }

    public function count(): int
    {
        return count($this->ruleSets);
    }

    /**
     * @return Traversable<int, WarrantRuleSet>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->ruleSets);
    }

    /**
     * Fold the parser's blocks into one merged set per schema, in first-appearance
     * order.
     *
     * @param list<\Warrant\RuleSyntaxTree\Parsing\ParsedRuleSet> $blocks
     */
    private static function fromParsedRuleSets(array $blocks): self
    {
        /** @var array<string, WarrantRuleSet> $byKey */
        $byKey = [];

        foreach ($blocks as $block) {
            $set = new WarrantRuleSet($block->schemaKey, $block->rules);

            $byKey[$block->schemaKey] = isset($byKey[$block->schemaKey])
                ? $byKey[$block->schemaKey]->mergeWith($set)
                : $set;
        }

        return new self(array_values($byKey));
    }
}
