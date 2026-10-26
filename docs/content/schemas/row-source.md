---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Where rows come from
description: Model schemas, query schemas, and schemas with no rows at all.
sidebar:
  order: 5
---

Which class a schema extends says where its rows come from: a model's table, a
query's output, or nowhere at all.

| Extend | Rows |
| --- | --- |
| `ModelSchema` | A model's table |
| `QuerySchema` | A query's output |
| `RowlessSchema` | None |

## A model's table

What most schemas want:

```php
use Warrant\Schema\Types\Classes\ModelSchema;

class DocumentSchema extends ModelSchema
{
    public const model = Document::class;
}
```

The model answers for the rows' table, key and connection, and a row condition
gets an Eloquent builder so it can use the model's scopes.

## A query

Extend `QuerySchema` and return a query from `rows()`, and its output becomes the
schema's rows. This is a database view defined in the schema instead of in a
migration, which is what you want when the query cannot be frozen into one:

```php
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Warrant\Schema\Types\Classes\QuerySchema;

class ShiftDaySchema extends QuerySchema
{
    #[Ability] public const VIEW   = 'view';
    #[Ability] public const ASSIGN = 'assign';

    public function rows(): Builder
    {
        return DB::table('teams')
            ->crossJoin('calendar_days')
            ->leftJoin('shifts', fn ($join) => $join
                ->on('shifts.team_id', '=', 'teams.id')
                ->on('shifts.starts_on', '=', 'calendar_days.day'))
            ->groupBy('teams.id', 'calendar_days.day')
            ->select([
                'teams.id as team_id',
                'calendar_days.day',
                DB::raw('count(shifts.id) as shift_count'),
            ]);
    }

    // The columns one row is identified by.
    protected function key(): array
    {
        return ['team_id', 'day'];
    }

    // A column the query computes, read as if it were stored.
    #[RowCondition]
    public function isUnderstaffed(RowConditionContext $c): Builder
    {
        return $c->query->where($c->column('shift_count'), '<', 2);
    }
}
```

Rules over it read exactly as they do over a table, and so do hops into it. A
handle passes one argument per key column, in order:

```warrant
if is_understaffed they can assign
if can(assign for shift_days(@column team_id, @column starts_on)) they can create
```

The compiler selects from the query as a subquery named `warrant_rows`, or under
the name a hop gives it:

```sql
exists (select * from ( … rows() … ) as "warrant_rows" where …)
```

### Naming one row

Every row of a query schema can be named, so a targeted check and a row-bound
reference always work against one. Declare one of these:

- **`key()`**, the column or columns one row is identified by. A row is found by
  each column equal to the argument in the same position.
- **[`matchKey()`](/reference/warrant-schema/#matchkey)**, for a lookup that isn't
  plain equality.

A query schema with neither is refused when it is first constructed.

### What a query schema gives up

Everything a model was buying beyond the rows themselves:

- No Eloquent scopes to spend from a condition. `$c->query` is a plain query
  builder.
- No hydrated `$c->model`, so no [answering in PHP](/schemas/conditions/), and
  `WarrantDenialContext::$target` is null.
- No model to reach it from, so `Model::userHasAbility()` and route-model binding do
  not apply.

So you start from the guard, and name a row by its key columns, in order:

```php
$guard = Warrant::forSchema(ShiftDaySchema::class);

$rows = $guard->filterQuery($guard->query(), 'view')->orderBy('warrant_rows.day')->get();
$guard->can('assign', [$team->id, '2026-09-14']);
```

### It takes no user and no context

Deliberately. `rows()` says what the rows *are*; who may touch them is what the
rules are for. Filtering by the current user here would move an access decision
out of the rule language, where neither the rule text nor reachability analysis can
see it.

The method is called afresh wherever the query is needed rather than memoized, so
nothing downstream can mutate a shared builder.

## No rows at all

Extend `RowlessSchema` to govern a section with no rows, for gating things like
`settings` that only ever answer no-row checks:

```php
use Warrant\Schema\Types\Classes\RowlessSchema;

class SettingsSchema extends RowlessSchema
{
    #[Ability] public const MANAGE = 'manage';

    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }
}
```

```php
Warrant::can('manage', 'settings');
Warrant::abilities(SettingsSchema::class);
```

Only global and derived conditions belong here. A `#[RowCondition]` on a rowless
schema is refused when it is first constructed, since there is no row for it to be
about.

A targeted check against it is refused too:

```text
Schema [SettingsSchema] has no rows and does not support targeted checks; use a
no-target check instead.
```

In a rule, such a schema takes the unbound handle form, with no row selector:

```warrant
if can(manage for settings) they can view
```

## Reading which is which

The engine asks the schema's interfaces:

```php
$schema instanceof IRowSchema;     // a model or query schema: it has rows
$schema instanceof IModelSchema;   // a model schema
```
