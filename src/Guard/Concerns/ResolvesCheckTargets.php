<?php

namespace Warrant\Guard\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Warrant\Schema\WarrantSchema;

/**
 * Turning the caller's `$target` argument into the forms the engine works with.
 *
 * A target arrives as whatever was convenient at the call site — a model, a key,
 * a class-string standing in for "no target" — and two questions get asked of it
 * repeatedly: *which row is this?* ({@see resolveCheckTarget}) and *may we
 * believe the instance?* ({@see trustedTargetModel}).
 *
 * They live together, and here rather than on one caller, because the answers
 * have to be the same everywhere. {@see ChecksAbilities} decides a check with
 * them; {@see DiagnosesDenials} then re-derives that decision to explain it, and
 * would explain the wrong one if it judged a target by a different rule.
 */
trait ResolvesCheckTargets
{
    /**
     * The target as a model whose row is known to be there — the only form worth
     * trusting without a query, and so the only one handed to a row condition or
     * accepted as proof of existence.
     *
     * A bare key names a row nobody has looked up; an unsaved model describes one
     * that may never have been written; a deleted one describes a row that is
     * gone. {@see \Illuminate\Database\Eloquent\Model::$exists} is Eloquent's own
     * record of the difference.
     */
    protected function trustedTargetModel(Model|string|int|null $target): ?Model
    {
        return $target instanceof Model && $target->exists ? $target : null;
    }

    /**
     * Normalize the `$target` argument of a check into either a concrete row
     * (a `Model` instance or a key) or `null` (a no-target check).
     *
     * An **int** is always a row key — there is nothing else it could name — so it
     * returns as-is and takes the row path. It is deliberately *not* stringified:
     * the key reaches `whereKey()` as the caller wrote it, so an integer primary
     * key is compared as an integer rather than relying on the database to coerce
     * a bound string. Returning early also keeps an int away from the `is_a()`
     * class-string checks below, which expect an object or a string.
     *
     * A **class-string** is not a row: naming this schema's own model class — or the
     * schema class itself — is how a no-target check is expressed positionally; it
     * resolves to `null`. A class-string for a *different* `Model`/`WarrantSchema`
     * is a mistake — the ability belongs to another schema — and throws. Any other
     * string is a target key and is left untouched for the row path.
     *
     * `null` and a `Model` instance pass straight through.
     */
    protected function resolveCheckTarget(Model|string|int|null $target): Model|string|int|null
    {
        if ($target === null || $target instanceof Model || is_int($target)) {
            return $target;
        }

        $model = $this->schema::model;

        if (($model !== '' && is_a($target, $model, true)) || is_a($target, $this->schema::class, true)) {
            return null;
        }

        if (is_a($target, Model::class, true) || is_a($target, WarrantSchema::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Target [%s] does not belong to schema [%s]%s; pass this schema\'s model or schema class for a no-target check, or an instance/key for a row check.',
                $target,
                $this->schema::class,
                $model !== '' ? sprintf(' (model [%s])', $model) : '',
            ));
        }

        return $target;
    }
}
