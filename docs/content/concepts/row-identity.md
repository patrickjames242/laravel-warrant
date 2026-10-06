---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Row identity
description: How Warrant names one row — keys, natural keys, composite keys, matchKey, and targets.
sidebar:
  order: 2
---

Several parts of Warrant have to name one specific row. A targeted check does. A
cross-schema hop does. They all go through the same mechanism, so it is worth
learning once.

## The default: the model's key

A model answers for its own key, so most schemas need nothing:

```php
Warrant::can('view', $document);                  // a loaded model
Warrant::can('view', [Document::class, 'doc-1']); // by key
DocumentSchema::guard($user)->can('view', 42);    // schema bound, bare key
```

In a rule, a hop names one row the same way, with one argument:

```warrant
if can(view for folders(@context folder_id)) they can view
```

That compiles to `where "folders"."id" = ?`.

## When the key is not enough

Two cases break the default, and they are common.

**A natural key.** A tenant is addressed by slug, not by id, and every rule and
route already carries the slug. **A composite key.** A schedule row is identified
by a team and a date together, and no single column is unique.

Both are answered by declaring `matchKey()` on the schema. Its parameters are the
key:

```php
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Conditions\RowConditionContext;

public function matchKey(RowConditionContext $c, mixed $team, mixed $day): ?Builder
{
    return $c->query
        ->where($c->row('team_id'), '=', $team)
        ->where($c->row('day'), '=', $day);
}
```

Now a hop supplies two arguments, and validation rejects one:

```warrant
if can(assign for shift_days(@column team_id, @column starts_on)) they can create
```

```php
$guard->can('assign', [$team->id, '2026-09-14']);
```

Three rules about the parameters.

Arity comes from the signature. A parameter without a default is required, and a
handle supplying too few is caught when the rule is validated:

```text
A can(...) reference to schema [shift_days] supplies 1 row-key argument(s), but
that schema's row key requires at least 2.
```

Type them `mixed`. A `@column` or `@sql` argument arrives as an
`Illuminate\Database\Query\Expression`, so a `string` parameter would reject
exactly the references a correlated hop is built from.

Returning `null` answers unknown, which neither grants nor lifts a denial. The
built-in key does this for a null key, because an absent `@context` value names no
row.

`matchKey()` is deliberately not declared on the base class. PHP forbids an
override from adding required parameters, so an inherited signature would make a
two-part key impossible to write.

:::caution[A key must identify at most one row]
Nothing enforces it. A key matching several rows turns an `EXISTS` from "this row
grants it" into "some row grants it", which is a quiet and serious difference.
:::

## Rows that are not a model's table

A schema may draw its rows from a query rather than a table. Such rows have no
primary key of their own, so the schema says how one is named: either `const key`
for the identifying column, or a `matchKey()`.

```php
class ShiftDaySchema extends WarrantSchema
{
    public const key = 'team_id';   // a view over one spine table

    public static function virtualTable(): ?Builder
    {
        return DB::table('teams')->crossJoin('calendar_days')->select([
            'teams.id as team_id',
            'calendar_days.day',
        ]);
    }
}
```

Declaring `const key` on a model-backed schema is rejected, because the model
already answers for it.

## Rows you cannot name, and no rows at all

Two capabilities are separate, and Warrant reads them separately:

```php
DocumentSchema::hasRows();     // are there rows at all?
DocumentSchema::hasRowKey();   // can one of them be named?
```

A schema with `const model = ''` and no virtual table has no rows. It is a
capability schema, good for gating a section such as `settings`, and it answers
only [no-row checks](/checking/no-row/).

A virtual table with neither `const key` nor `matchKey()` has rows but cannot name
one. That is legitimate: such a schema can still be filtered and can still carry a
per-row ability column, because both correlate against rows the surrounding query
already produced. What it cannot do is answer about one row, and it says so up
front:

```text
Schema [reports] has rows but no way to name one, so it does not support targeted
checks; declare `const key` for the column its rows are identified by, or a
matchKey() of its own. Filtering a query and selecting per-row abilities need
neither.
```

## Every form a target may take

On the facade, the target names the schema and optionally the row:

```php
Warrant::can('update', $document);                  // model instance
Warrant::can('update', [Document::class, $id]);     // [class, id]
Warrant::can('create', Document::class);            // model class, no row
Warrant::can('create', DocumentSchema::class);      // schema class, no row
Warrant::can('create', 'documents');                // schema key, no row
```

A bare scalar on the facade names a schema, never a row, which is why there is no
bare-int form: an int could not name a schema. On a schema-bound guard the schema
is already fixed, so a bare key is unambiguous and may be an int:

```php
$guard = DocumentSchema::guard($user);

$guard->can('update', $document);
$guard->can('update', 42);
$guard->can('update', 'doc-9');
$guard->can('create');           // no row
```

## What a hop may pass as a row

Inside `can(... for schema(<row>))` the value has to be something a database can
compare against a column:

| Value | What happens |
| --- | --- |
| string, int, float, null | bound as written |
| the referenced schema's own model | its key is bound, and a hydrated one also reaches that schema's row conditions as `$c->model` |
| a `BackedEnum` | Laravel unwraps it to its scalar value |
| a `DateTimeInterface` | Laravel formats it for the connection |
| `@column` / `@sql` | spliced as SQL rather than bound |

A model of the wrong schema is rejected rather than compared against the wrong
table:

```text
The row selector for schema [folders] is a [App\Models\Team], which is not that
schema's model [App\Models\Folder]; pass that schema's own model or a row key.
```

An explicit `null` row selector is rejected too, so a `$folder?->id` that came back
null fails loudly instead of quietly widening a question about one row into a
question about the whole schema.
