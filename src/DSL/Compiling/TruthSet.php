<?php

namespace Warrant\DSL\Compiling;

use Closure;
use Warrant\Reachability;
use Warrant\Support\Set;

/**
 * The truth values an expression could take, over every row, context and state
 * the analysis does not look at: a set of {@see TruthValue}s.
 *
 * The compiler answers each condition with one of SQL's three truth values and
 * combines them under Kleene logic, granting only on true. Reachability asks the
 * same question without a row, so where the compiler has one value it has the
 * set of values that are still possible, and combines sets by applying the same
 * operator to every pair of their members.
 *
 * Combining that way forgets that two operands may be the same question asked
 * twice — `a or not a` comes out as all three values, not true or unknown — so a
 * set may hold a value that can never actually occur. It never misses one that
 * can, which is the direction reachability has to err in.
 */
final readonly class TruthSet
{
    /**
     * @param Set<string> $values The {@see TruthValue} backing values; never
     *   changed after construction.
     */
    private function __construct(private Set $values)
    {
    }

    /**
     * A single known value: a bool, or null for unknown.
     */
    public static function of(?bool $value): self
    {
        return new self(new Set(TruthValue::of($value)->value));
    }

    /**
     * Any of the three: a question the analysis does not answer.
     */
    public static function any(): self
    {
        return new self(new Set(...array_column(TruthValue::cases(), 'value')));
    }

    public function and(self $other): self
    {
        return $this->combine($other, static fn (TruthValue $a, TruthValue $b): TruthValue => $a->and($b));
    }

    public function or(self $other): self
    {
        return $this->combine($other, static fn (TruthValue $a, TruthValue $b): TruthValue => $a->or($b));
    }

    public function not(): self
    {
        $values = new Set;

        foreach ($this->members() as $value) {
            $values->add($value->not()->value);
        }

        return new self($values);
    }

    public function union(self $other): self
    {
        return new self(new Set(...$this->values->values(), ...$other->values->values()));
    }

    public function canBeTrue(): bool
    {
        return $this->values->has(TruthValue::TRUE->value);
    }

    /**
     * A grant is made only on true, so an ability whose outcome can never be true
     * is never held, and one that can only be true is always held.
     */
    public function toReachability(): Reachability
    {
        return match (true) {
            ! $this->canBeTrue() => Reachability::NEVER,
            $this->values->size() === 1 => Reachability::ALWAYS,
            default => Reachability::MAYBE,
        };
    }

    /**
     * Every value $operator answers for a member of this set and one of $other.
     *
     * @param Closure(TruthValue, TruthValue): TruthValue $operator
     */
    private function combine(self $other, Closure $operator): self
    {
        $values = new Set;

        foreach ($this->members() as $a) {
            foreach ($other->members() as $b) {
                $values->add($operator($a, $b)->value);
            }
        }

        return new self($values);
    }

    /**
     * @return list<TruthValue>
     */
    private function members(): array
    {
        return array_map(TruthValue::from(...), $this->values->values());
    }
}
