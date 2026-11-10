<?php

namespace Warrant\Support;

use Illuminate\Database\Query\Expression;
use Stringable;

/**
 * A query expression that is also a string, for SQL that has to work both
 * inside a builder and inside raw SQL.
 *
 * A builder writes it as written wherever it is put, never quoting it again and
 * never binding it as a value. So a column reference made into one compares
 * columns even in a where's value position, where a plain string would be bound
 * as text and match nothing:
 *
 *     ->where('team_members.team_id', $c->column('team_id'))
 *
 * As a string it is the same SQL, for raw SQL:
 *
 *     ->whereRaw("{$c->column('owner_id')} = ?", [$owner])
 *
 * @extends Expression<string>
 */
final class StringableExpression extends Expression implements Stringable
{
    public function __construct(string $sql)
    {
        parent::__construct($sql);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
