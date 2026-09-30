---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Where rows come from
description: Model tables, virtual tables, and schemas with no rows at all.
sidebar:
  order: 5
---

A schema draws its rows from one source or the other, never both: a model's table,
or a query. It may also have no rows at all.

## A model's table

The default, and what most schemas want:

```php
public const model = Document::class;
```

## A query

Return a query from `virtualTable()` and it becomes the schema's rows. This is a
database view defined in the schema instead of in DDL, which is what you want when
the query cannot be frozen into a migration:

```php
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ShiftDaySchema extends WarrantSchema
{
    #[Ability] public const VIEW   = 'view';
    #[Ability] public const ASSIGN = 'assign';

    public static function virtualTable(): ?Builder
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

    // A product-shaped view has no single identifying column, so the schema
    // says how a row is addressed.
    public function matchKey(RowConditionContext $c, mixed $teamId, mixed $day): ?Builder
    {
        return $c->query
            ->where($c->row('team_id'), '=', $teamId)
            ->where($c->row('day'), '=', $day);
    }

    // A column the query computes, read as if it were stored.
    #[RowCondition]
    public function isUnderstaffed(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('shift_count'), '<', 2);
    }
}
```

Rules over it read exactly as they do over a table, and so do hops into it:

```warrant
if is_understaffed they can assign
if can(assign for shift_days(@column team_id, @column starts_on)) they can create
```

The compiler selects from the query as a subquery aliased to the schema's key:

```sql
exists (select * from ( … virtualTable … ) as shift_days where …)
```

### What a virtual table gives up

Everything the model was buying beyond the rows themselves:

- No Eloquent scopes to spend from a condition. `$c->query` is a plain query
  builder.
- No hydrated `$c->model`, so no [answering in PHP](/schemas/conditions/), and
  `WarrantDenialContext::$target` is null.
- No key of its own. Declare `const key = '<column>'` for the column its rows are
  identified by, or a `matchKey()`. Declare neither and it can be filtered but not
  asked about one row.
- No model to reach it from, so `Model::userHasAbility()` and route-model binding do
  not apply.

So you start from the guard:

```php
$guard = Warrant::forSchema(ShiftDaySchema::class);

$rows = $guard->filterQuery($guard->query(), 'view')->get();
$guard->can('assign', [$team->id, '2026-09-14']);
```

### It takes no user and no context

Deliberately. A virtual table says what its rows *are*; who may touch them is what
the rules are for. Filtering by the current user here would move an access decision
out of the rule language, where neither the rule text nor reachability analysis can
see it.

The method is called afresh wherever the query is needed rather than memoized, so
nothing downstream can mutate a shared builder.

### Declaring both is an error

```text
Schema [X] names model [Y] and also defines a virtualTable(); a schema draws its
rows from one or the other. Drop the model to make it a virtual table, or drop
virtualTable() to keep the model's own table.
```

```text
Schema [X] names model [Y] and also declares a key [z]; a model answers for its own
key, so drop the constant. It is for a virtual table, whose rows have no key of
their own.
```

## No rows at all

A schema may govern a section with no model, for gating things like `settings` that
only ever answer no-row checks:

```php
class SettingsSchema extends WarrantSchema
{
    public const model = '';

    #[Ability] public const MANAGE = 'manage';

    // Only global conditions make sense here: a row condition has no row.
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

A targeted check against it is refused up front:

```text
Schema [settings] has no rows and does not support targeted checks; use a no-target
check instead.
```

In a rule, such a schema takes the unbound handle form, with no row selector:

```warrant
if can(manage for settings) they can view
```

## Reading which is which

```php
DocumentSchema::hasRows();     // true for a model or a virtual table
DocumentSchema::hasRowKey();   // true when one row can be named
```

`hasRows()` is read by reflection rather than by calling `virtualTable()`, so
asking whether a schema has rows never builds a query.
