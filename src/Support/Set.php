<?php

namespace Warrant\Support;

/**
 * A set of strings or integers, held as the keys of an array so that adding,
 * removing and looking one up each take constant time.
 *
 * @template T of int|string
 */
final class Set
{
    /** @var array<T, true> */
    private array $members = [];

    /**
     * @param T ...$members
     */
    public function __construct(int|string ...$members)
    {
        foreach ($members as $member) {
            $this->add($member);
        }
    }

    /**
     * @param T $member
     */
    public function add(int|string $member): void
    {
        $this->members[$member] = true;
    }

    /**
     * @param T $member
     */
    public function remove(int|string $member): void
    {
        unset($this->members[$member]);
    }

    /**
     * @param T $member
     */
    public function has(int|string $member): bool
    {
        return isset($this->members[$member]);
    }

    public function size(): int
    {
        return count($this->members);
    }

    /**
     * @return list<T>
     */
    public function values(): array
    {
        return array_keys($this->members);
    }
}
