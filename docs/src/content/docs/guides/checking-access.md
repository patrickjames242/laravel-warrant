---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Checking access
description: Model helpers, query scopes, per-row abilities, match modes, and no-target checks.
sidebar:
  order: 6
---

Once schema, resolver, and rules are in place, you never touch the compiler
directly. You ask questions through the authorization engine, query scopes, or
[middleware](/guides/middleware/).

## Set up the model

Add the `HasWarrantSchema` trait and point it at the schema:

```php
use Illuminate\Database\Eloquent\Model;
use Warrant\HasWarrantSchema;

class Document extends Model
{
    use HasWarrantSchema;

    public function warrantSchema(): string
    {
        return \App\Warrant\DocumentSchema::class;
    }
}
```

:::caution
The schema's `const model` must match the model that returns it. If
`Document::warrantSchema()` returns a schema whose `model` is `Invoice::class`,
Warrant throws `Schema [...] must manage model [...]`.
:::

## The authorization engine

Every yes/no check runs through the same engine, reached three ways. Pick
whichever reads best where you are:

- **The `Warrant` facade** — schema-less; the target names the schema:

  ```php
  Warrant::can('update', $document);
  ```
- **A user-bound guard** — `Warrant::guard($user)`, or `$user->warrant()` when the
  user model uses the [`AuthorizesWithWarrant`](#the-authorizeswithwarrant-trait)
  trait:

  ```php
  $user->warrant()->can('update', $document);
  ```
- **A schema-bound guard** — `Warrant::forSchema($schemaOrModel, $user)`, or the
  `guard()` static every schema inherits:

  ```php
  DocumentSchema::guard($user)->can('update', $document);
  ```

`$user` is always optional and defaults to `auth()->user()`.

:::tip[Reaching for a plain check?]
For an everyday yes/no check you don't need any of these — Laravel's Gate resolves
Warrant abilities, so `$user->can('view', $document)` just works (see
[Laravel's Gate](#laravels-gate)). Reach for the engine directly when you need to
pass `context:`, use `canAny`, enumerate `abilities()`, or check no-target access.
:::

:::note
If no user is authenticated and none is passed, these throw
*"requires an authenticated user or an explicit user instance."* — a loud failure
rather than a silent deny.
:::

### The `AuthorizesWithWarrant` trait

Add the `AuthorizesWithWarrant` trait to your user model for the `->warrant()`
shortcut — it returns the same user-bound guard as `Warrant::guard($this)`:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Warrant\AuthorizesWithWarrant;

class User extends Authenticatable
{
    use AuthorizesWithWarrant;
}

$user->warrant()->can('approve', $document, context: ['region' => 'us']);
$user->warrant()->forSchema(Document::class)->abilities();
```

For a plain yes/no check with no context, prefer Laravel's Gate —
`$user->can('view', $document)` resolves the same ability (see
[Laravel's Gate](#laravels-gate)). Reach for `$user->warrant()` when you need to
pass `context:`, check with `canAny`, or ask the same schema repeatedly.

## Boolean checks

Checks run as a scoped `EXISTS` query — no records are loaded. On the facade, the
target names both the schema and, optionally, the row:

```php
Warrant::can('update', $document);                    // a model instance (row)
Warrant::can('update', [Document::class, $documentId]); // a row by key
Warrant::can(['view', 'update'], $document);          // ALL of several at once
Warrant::canAny(['view', 'update'], $document);       // ANY of several
Warrant::can('create', Document::class);              // no-target
Warrant::cannot('delete', $document);                 // the negation

// Same checks through a bound guard — the target is just the row:
DocumentSchema::guard($user)->can('update', $document);
$user->warrant()->forSchema(Document::class)->can('create');
```

There is no `matchMode:` argument on these helpers: `can` requires **all** listed
abilities, `canAny` requires **any** one.

:::note[Throwing variants]
`Warrant::authorize($abilities, $target)` and `Warrant::authorizeAny(...)` mirror
`can`/`canAny` but throw a `403` carrying the responsible rule's message instead of
returning `false`. See [Denial messages](/guides/denial-messages/).
:::

## Laravel's Gate

Warrant fully integrates with Laravel's Gate. Every native authorization surface
resolves Warrant abilities, so the calls you already write keep working:

```php
$user->can('view', $document);          // and $user->cannot(), canAny()
Gate::authorize('view', $document);     // throws Warrant's denial message
Route::get('/documents/{document}', ...)->middleware('can:view,document');
```

```blade
@can('view', $document)
    <a href="...">Edit</a>
@endcan
```

This works through a `Gate::before` hook. When you call any of the surfaces above,
that hook runs first, checks whether the ability belongs to one of your Warrant
schemas, and if so resolves it through Warrant. If the ability is not declared by
any registered Warrant schema, the hook returns `null` and Laravel falls through
to whatever would have handled it otherwise — your own policies, gate closures, or
`can:` routes. So Warrant and your existing policies coexist, and you can move
abilities over one at a time rather than all at once.

The Gate call maps to Warrant like this:

```php
$user->can('view', $document);                 // targeted row check
$user->can('approve', [$document, ['region' => 'us']]); // targeted + context
$user->can('create', Document::class);         // no-target via model class
$user->can('create', [Document::class, ['region' => 'us']]); // no-target + context
```

ALL/ANY across several abilities is native Laravel — `can([...])` vs `canAny([...])`.

The hook is registered by default. Set `register_gate` to `false` in
`config/warrant.php` to skip it.

## Filtering queries

The `userHasAbility` scope restricts a query to the rows the user may act on — the
"which records?" question, answered in SQL:

```php
// Documents the current user can update:
Document::query()->userHasAbility('update')->paginate();

// Rows they can BOTH view and approve:
Document::query()->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ALL)->get();

// For a specific user:
Document::query()->userHasAbility('delete', $user)->get();
```

:::tip[Empty ability list is a no-op]
`userHasAbility([])` leaves the query's SQL unchanged rather than filtering everything
out — handy when the ability list is computed and might be empty.
:::

## Per-row abilities

The `selectUserAbilities` scope attaches a computed `abilities` column — a JSON array
of what the user can do to *that* row — so your UI renders controls without N
extra checks:

```php
$rows = Document::query()->selectUserAbilities()->get();

$rows->first()->abilities; // ['view', 'update']
```

The list is ordered by [ability declaration order](/guides/schemas/#abilities) in
the schema.

On a list endpoint you often only care about a subset (say, just `update` for an
Edit button). Narrowing it is a real saving — the attached subquery grows one
`UNION ALL` branch per ability:

```php
Document::query()->selectUserAbilities(onlyAbilities: ['update'])->get();
```

You can also change the column name and attach abilities to an already-loaded
model:

```php
Document::query()->selectUserAbilities(selectedAbilitiesKey: 'perms')->get();

$document->loadUserAbilities();      // sets $document->abilities
Warrant::abilities($document);       // ['view', 'update'] — via the engine
```

:::caution[Driver support]
The per-row column uses a database-native JSON aggregate, implemented for
**PostgreSQL, MySQL/MariaDB, and SQLite**. Any other driver throws
*"Warrant ability selection does not support the [...] database driver."*
:::

:::note[The `SelectUserAbilitiesScope` is not auto-registered]
Warrant provides `Warrant\SelectUserAbilitiesScope` as a global scope, but the trait
does **not** attach it for you — add it yourself if you want the column applied
globally. It safely no-ops when there's no authenticated user (unauthenticated
requests simply get no abilities column).
:::

## Match modes

When you check several abilities at once, the two modes decide how they combine:

- **ALL** — the row/user must satisfy *every* listed ability.
- **ANY** — *any* one is enough.

The engine's boolean helpers pick the mode by method name — `can` is ALL,
`canAny` is ANY — so they take no match-mode argument. Query scopes and
[middleware](/guides/middleware/), by contrast, take an explicit
`AbilityMatchMode`:

```php
use Warrant\AbilityMatchMode;

Warrant::canAny(['view', 'approve'], $document);   // engine: ANY via the method name

Document::query()->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ANY)->get();
```

:::caution[Default differs on `getAbilitiesWithoutTarget`]
Most entry points default to `ALL`. The lower-level
`getAbilitiesWithoutTarget()` defaults to **`ANY`**. If you call it directly, pass
the match mode explicitly to avoid surprises.
:::

## No-target checks

Not every check is about a row. "Can this user *create* documents?" or "can they
access *settings*?" name no target. Nothing about the ability itself is
target-free — a no-target check just asks whether the user holds it without naming
a row, so only rules whose conditions don't need a row (global or unconditional)
can grant it. On the facade, name the schema class as the target; on a bound
guard, omit the row:

```php
Warrant::can('create', Document::class);     // no-target boolean check
Warrant::abilities(Document::class);         // all no-target abilities

DocumentSchema::guard($user)->can('create'); // bound guard: omit the row
```

For a section with no model at all, define a
[schema with no model](/guides/schemas/#schemas-with-no-model) with
`const model = ''` and only `#[GlobalCondition]` conditions. In a no-target check,
row conditions are treated as false, so only global logic contributes.

## Passing context

Every check API takes an optional `context:` array — values for any `@context`
keys the rules reference. See [Check-time context](/guides/context/#passing-context-to-a-check).

## API summary

| Call | Question |
|---|---|
| `Warrant::can($abilities, $target, $context, $user)` | Can they? — every ability (bool) |
| `Warrant::canAny($abilities, $target, $context, $user)` | Can they? — any one ability (bool) |
| `Warrant::cannot($abilities, $target, $context, $user)` | The negation of `can` (bool) |
| `Warrant::abilities($target, $context, $user)` | What can they do to this? (array) |
| `Warrant::authorize($abilities, $target, $context, $user)` | Can they? — throws a 403 with a message |
| `Warrant::authorizeAny($abilities, $target, $context, $user)` | Any one? — throws a 403 with a message |
| `->userHasAbility(...)` scope | Which rows? |
| `->selectUserAbilities(...)` scope | What per row? |
| `$model->loadUserAbilities(...)` | Attach abilities to an instance |
| `Warrant::couldEverHave($schema, $abilities, $user)` | Could they *ever*? (bool) |

Full signatures are in the [Checking API reference](/reference/checking-api/).

Two questions this guide doesn't cover live on their own pages: when a denial
should **explain itself**, [`authorize`](/guides/denial-messages/) throws a 403
carrying the responsible rule's message; and when you only need to know whether an
ability is *conceivable* — to render a nav or gate a section without a query —
[reachability](/guides/reachability/) answers structurally, no SQL.
