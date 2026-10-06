<?php

namespace Warrant\DSL\Compiling;

/**
 * One of the three truth values a compiled condition can take for a row: SQL's
 * true, false and unknown (NULL), combined under Kleene logic, as SQL combines
 * them.
 */
enum TruthValue: string
{
    case TRUE = 'true';
    case FALSE = 'false';
    case UNKNOWN = 'unknown';

    /**
     * A bool, or null for unknown.
     */
    public static function of(?bool $value): self
    {
        return match ($value) {
            true => self::TRUE,
            false => self::FALSE,
            null => self::UNKNOWN,
        };
    }

    /**
     * False if either is false — whatever the other — true if both are true,
     * unknown otherwise.
     */
    public function and(self $other): self
    {
        return match (true) {
            $this === self::FALSE || $other === self::FALSE => self::FALSE,
            $this === self::TRUE && $other === self::TRUE => self::TRUE,
            default => self::UNKNOWN,
        };
    }

    /**
     * True if either is true — whatever the other — false if both are false,
     * unknown otherwise.
     */
    public function or(self $other): self
    {
        return match (true) {
            $this === self::TRUE || $other === self::TRUE => self::TRUE,
            $this === self::FALSE && $other === self::FALSE => self::FALSE,
            default => self::UNKNOWN,
        };
    }

    /**
     * Unknown negates to itself.
     */
    public function not(): self
    {
        return match ($this) {
            self::TRUE => self::FALSE,
            self::FALSE => self::TRUE,
            self::UNKNOWN => self::UNKNOWN,
        };
    }
}
