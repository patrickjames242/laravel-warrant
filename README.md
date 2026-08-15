<p align="center">
  <img src="art/logo.png" alt="Laravel Warrant" width="140">
</p>

<h1 align="center">Laravel Warrant</h1>

<p align="center">
  Row-level permissions &amp; authorization for Laravel — one rule, compiled straight to SQL.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/status-beta-ff8c2f?style=flat-square" alt="Beta">
  <a href="https://laravel-warrant.dev"><img src="https://img.shields.io/badge/docs-laravel--warrant.dev-ff8c2f?style=flat-square" alt="Documentation"></a>
  <img src="https://img.shields.io/badge/Laravel-11%20%26%2012-FF2D20?style=flat-square&logo=laravel" alt="Laravel 11 & 12">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php" alt="PHP 8.2+">
  <img src="https://img.shields.io/badge/license-MIT-3da639?style=flat-square" alt="MIT License">
</p>

<p align="center">
  <strong><a href="https://laravel-warrant.dev">Read the documentation → laravel-warrant.dev</a></strong>
</p>

> [!WARNING]
> **Laravel Warrant is in beta and still being tested.** It's usable, but the API
> may change between releases — pin your version and check the changelog before
> upgrading. Please
> [report any issues](https://github.com/patrickjames242/laravel-warrant/issues)
> you run into.

---

Schema-based authorization for Laravel that compiles a small, human-readable
rule language **directly into SQL** — so "what can this user do?" and
"which rows can this user touch?" are answered by the database in a single query,
not by loading records and looping in PHP.

```text
if is_self or (is_manager and same_department)
they can view, update
they cannot delete
```

That block is a real, complete Warrant rule. Warrant turns it into a `WHERE`
clause. This README explains the problem Warrant solves, the language above in
full detail, and exactly how you hand rules to the library.

---

## Table of contents

- [The problem](#the-problem)
- [How Warrant thinks about authorization](#how-warrant-thinks-about-authorization)
- [Installation](#installation)
- [A complete example](#a-complete-example)
- [Schemas: the vocabulary of a resource](#schemas-the-vocabulary-of-a-resource)
  - [Abilities](#abilities)
  - [Conditions](#conditions)
  - [Targeted vs. no-target conditions](#targeted-vs-no-target-conditions)
  - [Conditions with parameters](#conditions-with-parameters)
  - [Context keys](#context-keys)
- [The rule language](#the-rule-language)
  - [Anatomy of a rule](#anatomy-of-a-rule)
  - [`can` and `cannot`](#can-and-cannot)
  - [Conditions and boolean logic](#conditions-and-boolean-logic)
  - [Operator precedence](#operator-precedence)
  - [Wildcards](#wildcards)
  - [Passing arguments to conditions](#passing-arguments-to-conditions)
  - [Check-time context (`@context`)](#check-time-context-context)
  - [Whitespace, multiple rules, and reserved words](#whitespace-multiple-rules-and-reserved-words)
  - [Formal grammar](#formal-grammar)
  - [Syntax errors](#syntax-errors)
- [Providing rules to Warrant](#providing-rules-to-warrant)
  - [The `RuleResolver`](#the-ruleresolver)
  - [Building a rule set](#building-a-rule-set)
  - [Building rules programmatically](#building-rules-programmatically)
  - [Implicit rules](#implicit-rules)
  - [Registering the resolver](#registering-the-resolver)
- [Checking access](#checking-access)
  - [On the model](#on-the-model)
  - [Filtering queries](#filtering-queries)
  - [Per-row abilities](#per-row-abilities)
  - [Passing context to a check](#passing-context-to-a-check)
  - [Capability (no-target) checks](#capability-no-target-checks)
  - [Match modes](#match-modes)
  - [Could a user ever…? (reachability)](#could-a-user-ever-reachability)
  - [Route middleware](#route-middleware)
- [Exceptions](#exceptions)
- [How it compiles to SQL](#how-it-compiles-to-sql)
- [Testing](#testing)
- [API cheat sheet](#api-cheat-sheet)

---

## The problem

Say the rule is: *a user can update a timesheet if it's their own, or if it
belongs to a specific department (say, `sales`) — but never once it's locked
(unless they're an admin).*

With a **Laravel Policy** you write that once, for a single object:

```php
class TimesheetPolicy
{
    public function update(User $user, Timesheet $timesheet): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if ($timesheet->locked) {
            return false;
        }

        return $timesheet->user_id === $user->id
            || $timesheet->department_id === 'sales';
    }
}
```

That works for `$user->can('update', $timesheet)`. Now watch what happens with the
two questions every real screen actually asks.

**"Which timesheets can this user update?"** The policy can't answer it — it needs
an object. So you either fetch everything and filter in PHP:

```php
// Loads the whole table into memory. Pagination is now impossible.
$editable = Timesheet::all()->filter(fn ($t) => $user->can('update', $t));
```

…or you re-implement the policy a second time as a query scope:

```php
Timesheet::query()
    ->when(! $user->is_admin, function ($q) use ($user) {
        $q->where('locked', false)
          ->where(fn ($q) => $q
              ->where('user_id', $user->id)
              ->orWhere('department_id', 'sales')); // 'sales' hardcoded a second time
    })
    ->paginate();
```

Now the *same* rule lives in two places, in two different shapes, and they will
drift the first time someone edits one and forgets the other.

**"What can this user do with each row on this page?"** Your table has view /
update / delete / approve buttons. So, per page:

```php
$rows = $timesheets->map(fn ($t) => [
    'timesheet' => $t,
    'can' => [
        'view'    => $user->can('view', $t),
        'update'  => $user->can('update', $t),
        'delete'  => $user->can('delete', $t),
        'approve' => $user->can('approve', $t),
    ],
]);
// 50 rows × 4 abilities = 200 policy evaluations, each possibly hitting the DB.
```

A **flat permission package** (e.g. spatie/laravel-permission) doesn't help here —
its permissions are global strings:

```php
$user->givePermissionTo('update timesheets');
$user->can('update timesheets'); // true or false, for ALL timesheets
```

There's no room in `'update timesheets'` for *"their own"*, *"in the sales
department"*, or *"unless locked."* The moment a permission is conditional on the
record, you're back to writing a Policy — and back to both problems above.

And in every one of these, **the rule is code**. Want managers to approve
timesheets in their department? That's a deploy. You can't store it per role or
per tenant, can't let an admin screen define it, can't audit it as data.

### The same thing in Warrant

Write the rule once, as data:

```text
if is_self or manages_department('sales') they can update
if is_locked and not is_admin they cannot update
if is_admin they can *
```

(Note how the "unless they're an admin" exception is just `and not is_admin` on
the deny — the whole rule is right there, readable, in three lines. And
`manages_department('sales')` shows a condition taking a *parameter*: the same
condition serves every department, and the rule says which one.)

Those condition names aren't magic strings — you define, once, how each one
resolves. A schema declares the vocabulary and teaches every condition how to
emit SQL:

```php
namespace App\Warrant;

use App\Models\Timesheet;
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Ability;
use Warrant\GlobalCondition;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\TargetedConditionContext;
use Warrant\Schema\WarrantSchema;
use Warrant\TargetedCondition;

class TimesheetSchema extends WarrantSchema
{
    public const model = Timesheet::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';

    // Targeted: narrows WHICH timesheet rows the user matches.
    #[TargetedCondition]
    public function isSelf(TargetedConditionContext $c): Builder
    {
        return $c->query->whereRaw('timesheets.user_id = ?', [$c->user->getAuthIdentifier()]);
    }

    // The rule's argument arrives on $c->arguments — here the department the
    // rule named, e.g. manages_department('sales'). One condition, every
    // department; the rule assigned to the user picks which.
    #[TargetedCondition]
    public function managesDepartment(TargetedConditionContext $c): Builder
    {
        [$department] = $c->arguments;

        return $c->query->where('timesheets.department_id', $department);
    }

    #[TargetedCondition]
    public function isLocked(TargetedConditionContext $c): Builder
    {
        return $c->query->where('timesheets.locked', true);
    }

    // Global: a plain yes/no about the user, independent of any row.
    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }
}
```

Each condition is written once, in PHP, and every rule that names it — in any
tenant, role, or admin-defined policy — reuses that same SQL. The rules stay
data; the schema is the only code.

Warrant compiles it to SQL, so one rule set answers all three questions —
consistently, because there's only one source of truth:

```php
// "Which can they update?"  -> a WHERE clause, paginates fine
Timesheet::query()->userHasAbility('update')->paginate();

// "What can they do to each row?"  -> one computed column, one query
Timesheet::query()->selectUserAbilities()->get();   // each row ->abilities = ['view','update']

// "Can they update this one?"  -> a scoped EXISTS
$timesheet->userHasAbility('update');
```

And notice what's *missing*: Warrant never told you where those rules live.
Unlike laravel-permission or Bouncer — which own a set of tables and expect
permissions to be stored their way — Warrant doesn't store anything. It doesn't
care whether you keep rules in a database, generate them on the fly from a
settings screen, read them off a JWT claim, or hardcode them for a plan tier.
The *only* thing Warrant asks is that a **resolver** hand back the rules for the
current request:

```php
class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        if ($context->schemaKey === 'timesheets'){
            // However you want to produce the rules — this is entirely yours:
            if ($context->user->is_manager) {
                return WarrantRuleSet::fromSyntax(
                    $context->schemaKey,
                    'if is_self or manages_department(:dept) they can update
                     if is_locked and not is_admin they cannot update
                     if is_admin they can *',
                    bindings: ['dept' => $context->user->department],
                );
            } else {
                return WarrantRuleSet::fromSyntax(
                    $context->schemaKey,
                    "if is_self they can update",
                );
            }
        }   
        // ...
    }
}
```

Fetch it from a table, build it from config, compose it per tenant — Warrant
picks up wherever your resolver leaves off and compiles the result to SQL.

You point Warrant at your resolver and register your schemas in
`config/warrant.php`:

```php
'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,
'schemas'       => [App\Warrant\TimesheetSchema::class],
```

The rest of this README is how that works.

---

## How Warrant thinks about authorization

Warrant splits authorization into three separate things. Keeping them separate is
the whole idea:

| Piece | What it is | Who writes it |
|---|---|---|
| **Schema** | The *vocabulary* for one resource: the abilities that exist (`view`, `approve`, …) and the conditions a rule may test (`is_self`, `is_manager`, …). Conditions know how to emit SQL. | You, in a PHP class |
| **Rules** | The *policy itself*, written in Warrant's rule language as a plain string (e.g. `if is_self they can view`). Rules reference the schema's vocabulary. | Stored as data — a DB table, config, JWT claims, wherever |
| **Resolver** | The glue that, at request time, produces the rules that apply to *this* user for *this* resource. | You, one small class |

A **schema is not a policy.** It doesn't decide anything — it only declares what
words the language may use. The actual decisions live in the rules, which your
**resolver** supplies. Warrant compiles those rules, validated against the schema,
into SQL.

```text
       your data (roles, grants)                    request-time
                │                                         │
                ▼                                         ▼
        RuleResolver ──▶ WarrantRuleSet ──▶ RuleSetCompiler ──▶ SQL WHERE / column
                                     ▲                    │
                                     │                    │ validated against
                              WarrantSchema ───────────────┘
                          (abilities + conditions)
```

---

## Installation

```bash
composer require patrickhanna/laravel-warrant
```

The service provider auto-registers. Publish the config if you want to edit it in
place:

```bash
php artisan vendor:publish --tag=warrant-config
```

Requirements: PHP 8.2+, Laravel 11 or 12. Supported drivers for the SQL Warrant
generates: PostgreSQL, MySQL/MariaDB, and SQLite.

---

## A complete example

The four pieces end-to-end. Read the rest of the README for the detail behind
each.

**1. The schema** — declares the vocabulary for timesheets:

```php
namespace App\Warrant;

use App\Models\Timesheet;
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Ability;
use Warrant\GlobalCondition;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\TargetedConditionContext;
use Warrant\Schema\WarrantSchema;
use Warrant\TargetedCondition;

class TimesheetSchema extends WarrantSchema
{
    public const model = Timesheet::class;

    #[Ability] public const VIEW    = 'view';
    #[Ability] public const UPDATE  = 'update';
    #[Ability] public const DELETE  = 'delete';
    #[Ability] public const APPROVE = 'approve';

    // Targeted: narrows WHICH timesheet rows the user matches.
    #[TargetedCondition]
    public function isSelf(TargetedConditionContext $c): Builder
    {
        return $c->query->whereRaw('timesheets.user_id = ?', [$c->user->getAuthIdentifier()]);
    }

    #[TargetedCondition]
    public function inDepartment(TargetedConditionContext $c): Builder
    {
        return $c->query->whereIn('timesheets.department_id', $c->arguments);
    }

    // No-target: a plain yes/no about the user, independent of any row.
    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }
}
```

**2. The rules** — as data. Here inline; in practice from your DB:

```text
if is_self they can view, update, delete
if in_department(?, ?) they can view, approve
if is_admin they can *
```

**3. The resolver** — hands those rules to Warrant for the current user:

```php
namespace App\Warrant;

use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        // Look up the raw rule string + any binding values for this user/resource.
        [$syntax, $bindings] = MyRuleStore::for(
            user: $context->user,
            resource: $context->schemaKey, // 'timesheets'
        );

        return WarrantRuleSet::fromSyntax($context->schemaKey, $syntax, $bindings);
    }
}
```

**4. Wire it up** (`config/warrant.php`) and use it:

```php
'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,
'schemas' => [App\Warrant\TimesheetSchema::class],
```

```php
// Which timesheets can the current user update? (one SQL query)
$editable = Timesheet::query()->userHasAbility('update')->get();

// Can this user approve this specific timesheet?
if (Timesheet::userHasAbilities('approve', $timesheet)) { /* ... */ }

// Render buttons: attach the per-row ability list.
$rows = Timesheet::query()->selectUserAbilities()->get();
$rows->first()->abilities; // e.g. ['view', 'update']
```

---

## Schemas: the vocabulary of a resource

A schema is an `abstract class WarrantSchema` subclass, one per resource. It
declares two things: the **abilities** that exist, and the **conditions** a rule
may test. It is registered against a model via the `model` constant.

```php
class TimesheetSchema extends WarrantSchema
{
    public const model = Timesheet::class;   // the Eloquent model this governs
    // public const schemaKey = 'timesheets'; // optional override
}
```

The **schema key** (the resource's identifier in rules and lookups) is
derived from the model's table name by default (`timesheets`). Override it with
the `schemaKey` constant. A schema may also have **no model**
(`public const model = ''`) — a "capability" schema for things like `settings`
that only answer no-target checks (see [capability checks](#capability-no-target-checks)).

### Abilities

Abilities are the verbs a rule can grant or deny. Declare each as a class
constant marked `#[Ability]`. The constant's **value** is the ability name used
in rules; the constant's *name* is irrelevant to Warrant.

```php
#[Ability] public const VIEW    = 'view';
#[Ability] public const APPROVE = 'approve';
```

```php
TimesheetSchema::declaredAbilities(); // ['view', 'approve', ...]
```

A rule that names an ability the schema doesn't declare is rejected at compile
time (see [validation](#how-it-compiles-to-sql)). Warrant ships a
`Warrant\StandardAbilities` helper with common names (`VIEW`, `CREATE`, `UPDATE`,
`DELETE`, `ARCHIVE`) if you want a shared vocabulary.

### Conditions

Conditions are the predicates a rule may test in its `if`. Each is a public
method marked with `#[TargetedCondition]` or `#[GlobalCondition]`. The condition
**name** used in rules is derived from the method name by snake-casing it
(`isSelf` → `is_self`). You can override it: `#[TargetedCondition('is_owner')]`.

Every condition method takes a **single context object** and returns `Builder`
(mutated) or, for a global condition, a `bool`. The context carries the current
user, the query builder, the DSL `arguments`, and — for targeted conditions —
the `targetSqlId`.

A condition's one job is to **emit SQL**. There is no in-memory evaluation path —
even a single-object check runs as a scoped query. This keeps a condition's
behavior identical whether you're filtering a list or checking one row.

### Targeted vs. global conditions

The distinction is: *does this predicate talk about a specific row?*

- **`#[TargetedCondition]`** — the predicate constrains *which rows* match. Its
  context is a `TargetedConditionContext` carrying `targetSqlId`, the qualified
  primary-key SQL id of the entity (`timesheets.id`). Mutate `$c->query` to add
  the `WHERE` fragment:

  ```php
  #[TargetedCondition]
  public function isSelf(TargetedConditionContext $c): Builder
  {
      // $c->targetSqlId === "timesheets.id" (the correlated row under test)
      return $c->query->whereRaw('timesheets.user_id = ?', [$c->user->getAuthIdentifier()]);
  }
  ```

  Your predicate may reference any column of the entity's table; it is evaluated
  correlated to the row under test.

- **`#[GlobalCondition]`** — the predicate is about the *user or the world*, not a
  row (e.g. "is this user an admin?", "is this tenant on the pro plan?"). Its
  context is a `GlobalConditionContext` (no `targetSqlId`). It may mutate
  `$c->query` like a targeted condition, or simply **return a `bool`**:

  ```php
  #[GlobalCondition]
  public function isAdmin(GlobalConditionContext $c): bool
  {
      return (bool) $c->user->is_admin;   // true = holds for this user, false = doesn't
  }
  ```

Why the split matters: some checks (**capability checks** and
`getAbilitiesWithoutTarget`) run with *no row*. In that context a targeted
condition can't be evaluated, so Warrant treats it as **false** (and therefore
`not <targeted>` as **true**). Global conditions still evaluate normally.

> **Values are always bound.** Whatever you pass into `whereRaw`, `whereIn`,
> etc. becomes a bound parameter. Never interpolate a value into the SQL string
> — conditions run against user- and rule-supplied data.

### Conditions with arguments

A condition can take arguments from the rule (`in_department('sales')`). The
resolved arguments arrive on the context as **`$c->arguments`**, in order:

```php
#[TargetedCondition]
public function inDepartment(TargetedConditionContext $c): Builder
{
    // in_department('sales', 'eng')  ->  $c->arguments === ['sales', 'eng']
    return $c->query->whereIn('timesheets.department_id', $c->arguments);
}
```

A condition that ignores arguments simply never reads `$c->arguments`.

### Context keys

Some values a rule needs aren't known when the schema is written *or* when the
resolver builds the rules — they're known only at the moment of the check: the
current tenant, an academic year, an as-of date, an impersonated user. These are
**context keys**. Declare each with `#[ContextKey]`, mirroring `#[Ability]` — the
constant's *value* is the key string; its name is irrelevant to Warrant:

```php
// Required by default: no check on this resource resolves without the frame.
#[ContextKey] public const WORKSPACE = 'workspace_id';

// Opt out for a frame that only gates grants.
#[ContextKey(required: false)] public const AS_OF = 'as_of_date';
```

A rule references a context key with `@context <key>` (see
[Check-time context](#check-time-context-context)); the caller supplies the value
in a `context:` array at check time (see
[Passing context to a check](#passing-context-to-a-check)). The value arrives in
the condition exactly like any other argument, on `$c->arguments`:

```php
#[TargetedCondition]
public function inWorkspace(TargetedConditionContext $c): Builder
{
    [$workspace] = $c->arguments;   // supplied at the check via @context workspace_id
    return $c->query->where('documents.workspace_id', $workspace);
}
```

Every condition **also** receives the full effective context on `$c->context`,
whether or not the rule passed a value via `@context`. Reach into it directly when
a condition is inherently tied to the frame — then the rule needn't mention the
key at all:

```php
#[TargetedCondition]
public function inCurrentWorkspace(TargetedConditionContext $c): Builder
{
    // Rule is just `if in_current_workspace they can view` — no @context needed.
    return $c->query->where('documents.workspace_id', $c->context['workspace_id']);
}
```

Two styles, same value. `@context` threads a key into `$c->arguments` positionally
(and soft-falses the condition when an *optional* key is missing); `$c->context`
hands every condition the whole bag to read however it likes. Pick whichever makes
your rules read the way you want.

**Keys are required by default:** any check on the schema throws unless the key
is present in the effective context. Opt out with
**`#[ContextKey(required: false)]`** only for a frame that never gates a `cannot`
— a missing *optional* key silently *lifts* a deny (see
[Check-time context](#check-time-context-context)), and required-ness is what
forecloses that. When in doubt, leave it required.

**`defaultContext()`** supplies defaults so callers may omit a key — and so
param-less paths (route middleware, the `userHasAbility` / `selectUserAbilities`
query scopes) get a frame with no `context:` argument. Explicit context passed at the check wins over
defaults:

```php
protected function defaultContext(): array
{
    return ['workspace_id' => app('tenant')->id];
}
```

---

## The rule language

This is the heart of Warrant. Rules are written as a plain string. You'll
typically store these strings (per role, per user, per tenant) and load them in
your resolver.

Throughout, **"they" is the current user** — the one your resolver was asked
about for this request. A rule set never describes what *everyone* can do; it
describes what *this* user can do with the resource it's scoped to. So
`they can approve` means "this user may approve every row of this resource,"
not "approval is open to all users."

### Anatomy of a rule

A rule is an optional `if <expression>` followed by one or more `they can` /
`they cannot` clauses:

```text
if is_self
they can view, update
they cannot delete
```

- **`if <expression>`** — optional. When present, the clauses only apply where
  the expression holds. When omitted, the rule is **unconditional** (always
  applies).
- **`they can <abilities>`** — grants the listed abilities.
- **`they cannot <abilities>`** — denies the listed abilities.

Abilities are comma-separated. A rule may have any mix of `can` and `cannot`
clauses.

### `can` and `cannot`

Warrant combines grants and denials with **deny-overrides**. For a given ability,
the compiled predicate is:

```text
( any `can` rule for it matches )  AND  ( no `cannot` rule for it matches )
```

Concretely:

- **A `cannot` is an absolute veto.** `they cannot delete` compiles to "and *not*
  the delete rule's condition." An **unconditional** `they cannot delete` means
  *this user can never delete any row*, full stop — no `can` rule can bring it
  back.
- **An ability with no `can` rule is denied.** Silence is not permission.
- **An unconditional `they can view` grants this user view of every row.**

```text
they can view                 # this user can view every row
if is_locked
they cannot update, delete    # ...but this user can never update or delete a
                              #    locked row, even if another rule grants update
```

Rule *order does not matter* — the deny-overrides combination is commutative.

### Conditions and boolean logic

The `if` expression is a boolean combination of conditions:

```text
if is_self or is_manager
if is_self and not is_locked
if is_manager and (in_department('sales') or in_department('eng'))
```

- **`and`**, **`or`** — binary operators.
- **`not`** — negation. `!` is an accepted synonym (`!is_locked` ≡ `not is_locked`).
  `not` is the canonical spelling.
- **Parentheses** group sub-expressions.

Each bare name (`is_self`, `is_manager`) is a condition declared on the schema.

### Operator precedence

From tightest to loosest binding: **`not` / `!`  >  `and`  >  `or`**. Parentheses
override. So:

```text
if is_self or not is_manager and is_owner
```

parses as `is_self OR ((NOT is_manager) AND is_owner)`. When in doubt,
parenthesize. (`&&` and `||` are **not** supported — use `and` / `or`.)

### Wildcards

`*` stands for **every ability the schema declares**, on both sides:

```text
if is_admin
they can *              # this user gets every ability (when they're an admin)

if is_suspended
they cannot *           # this user loses every ability (a lockout that wins)
```

`they cannot *` combined with deny-overrides is the idiomatic "kill switch."

### Passing arguments to conditions

A condition can take arguments in three ways here — **inline literals**, **named
bindings**, and **positional bindings** — all resolved *before* compilation. A
fourth source, **[check-time context](#check-time-context-context)**, is resolved
later, when the check runs.

**Inline literals** are written directly in the rule. Supported literal types:
`string` (single-quoted), `int`, `float`, `bool`, `null`.

```text
if in_department('sales', 'eng') they can view
if seen_recently(30, true) they can view
```

Strings use single quotes; escape a quote or backslash with `\'` and `\\`.
Lists and other complex values **cannot** be written inline — pass them via a
binding.

**Named bindings** (`:name`) are placeholders filled from a bindings array. The
*name* is what matters: a binding may be reused any number of times, appear
anywhere in the string (even across rules), and the array order is irrelevant.

```php
WarrantRuleSet::fromSyntax('timesheets', <<<'RULES'
    if is_specific_user(:uid) they can view
    if delegated_to(:uid) they can approve
    RULES,
    ['uid' => $currentUserId],   // one value, used twice
);
```

**Positional bindings** (`?`) are filled left-to-right across the *entire*
string from a flat array:

```php
WarrantRuleSet::fromSyntax('timesheets',
    'if in_department(?, ?) they can view',
    ['sales', 'eng'],            // ? ? -> 'sales', 'eng'
);
```

Rules for bindings — all enforced at parse time:

- **A binding value may be any PHP value** — string, int, array, an object,
  anything. (Only *inline* literals are restricted to scalars.) Your condition
  receives it verbatim in `$parameters`.
- **You may not mix** named and positional bindings in one parse.
- **Every placeholder must have a value**, and **every provided value must be
  used**. A missing binding, an unused binding, or a positional count mismatch is
  an error.

### Check-time context (`@context`)

The three sources above are all resolved *before* a rule is compiled — literals
when the rule is authored, bindings when the resolver builds it. Some values are
known only **when the check runs**: the current tenant, an academic year, an
as-of date. Warrant reaches these with a fourth argument form, `@context <key>`,
that stays symbolic in the rule and is filled from a `context:` array at check
time:

```text
if in_workspace(@context workspace_id) they can view, edit
```

The key must be [declared on the schema](#context-keys) with `#[ContextKey]` — an
undeclared `@context` reference is a compile-time error, exactly like an unknown
condition name. Unlike `:name` / `?` bindings, a `@context` reference is **not**
subject to the parse-time "every binding used / no mixing" rules — it carries no
value at parse time. It may sit alongside literals and bindings in one condition,
and never consumes a positional `?`:

```text
if scoped_to('projects', @context project_id, :region) they can view
```

Because the value is pinned once per check, it behaves as an ordinary bound SQL
parameter — per-row `selectUserAbilities` stays a flat list and deny-overrides is
unaffected.

**When the key is absent at check time:**

- If the key is **required** (the default), the check throws before compiling —
  for *every* check on the schema (see [Context keys](#context-keys)).
- If it was declared **`required: false`**, the referencing condition is treated
  as **false** — the same rule Warrant applies to a targeted condition in a
  no-target check. That is safe on a grant (no key, no grant), but on a `cannot`
  it makes the veto *lift* (fail-open), which is why only a grant-only frame
  should be opted out of required.

`@context` is one of two ways a condition gets a context value; the other is
reading the ambient `$c->context` bag directly (see [Context keys](#context-keys)),
which every condition receives regardless of what the rule passes. The
distinguishing behavior of `@context` is the automatic soft-false above — a
condition that reads `$c->context` itself always runs and decides for itself.

Supplying the values is covered under
[Passing context to a check](#passing-context-to-a-check).

### Whitespace, multiple rules, and reserved words

- **Whitespace is insignificant.** Newlines are cosmetic; an entire rule set can
  be one line. These are identical:

  ```text
  if is_self they can view if is_manager they can approve
  ```
  ```text
  if is_self
  they can view

  if is_manager
  they can approve
  ```

- **`if` starts a new rule.** Every `if` begins a new rule; `they can/cannot`
  clauses attach to the most recent `if` above them. Clauses before any `if` form
  a single leading unconditional rule.

- **Reserved words** — `if`, `they`, `can`, `cannot`, `and`, `or`, `not` — cannot
  be used as an *exact* condition or ability name. A name may *contain* or *start
  with* one, though: `canonical`, `cannot_publish`, `is_and_something` are all
  fine.

- **Identifiers** (condition, ability, and binding names) match
  `[A-Za-z_][A-Za-z0-9_-]*`: they start with a letter or underscore and may
  contain letters, digits, underscores, and dashes. No dots.

### Formal grammar

```ebnf
ruleset   = clause* ( "if" expr clause+ )* ;
clause    = "they" ( "can" | "cannot" ) ability ( "," ability )* ;
ability   = IDENTIFIER | "*" ;
expr      = or ;
or        = and ( "or" and )* ;
and       = not ( "and" not )* ;
not       = ( "not" | "!" ) not | primary ;
primary   = "(" expr ")" | condition ;
condition = IDENTIFIER ( "(" ( arg ( "," arg )* )? ")" )? ;
arg       = STRING | INT | FLOAT | BOOL | NULL | NAMED_BINDING | POSITIONAL | CONTEXT_REF ;
CONTEXT_REF = "@context" IDENTIFIER ;
```

### Syntax errors

Malformed syntax throws `Warrant\RuleSyntaxTree\WarrantSyntaxException` eagerly,
with the line, column, and a caret pointing at the offending token — debuggable
even when the whole rule set is one line:

```
Reserved word 'can' cannot be used as a name; expected an ability name. (line 1, column 21)

    if is_self they can can
                        ^
```

Name validation (does this ability/condition actually exist on the schema?)
happens later, at **compile time**, when a rule set is compiled against a schema
— also as a hard error.

---

## Providing rules to Warrant

Rules are data. Warrant never invents them; it asks *your* resolver for them.

### The `RuleResolver`

Implement one interface. Given a context (the user, the resource's schema key, the
schema class, and the model class), return the `WarrantRuleSet` that governs this
user's access to that resource.

```php
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        // $context->user               — the Authenticatable being checked
        // $context->schemaKey — e.g. 'timesheets'
        // $context->schema             — the schema class string
        // $context->model              — the model class string, or null

        $grants = DB::table('role_permissions')
            ->where('role_id', $context->user->role_id)
            ->where('resource', $context->schemaKey)
            ->pluck('rule');                    // ['if is_self they can view', ...]

        return WarrantRuleSet::fromSyntax(
            $context->schemaKey,
            $grants->implode("\n"),             // rules concatenate freely
        );
    }
}
```

The resolver is where *your* access-control model meets Warrant. Store rule strings in
a table, compose them from role flags, read them from JWT claims — whatever fits.
Warrant only cares that you return a `WarrantRuleSet`.

### Building a rule set

Three ways to construct a `WarrantRuleSet`:

**From syntax** (parse a string, resolving bindings inline):

```php
WarrantRuleSet::fromSyntax('timesheets', 'if is_self they can view', $bindings = []);
```

**From already-parsed rules** — build individual `WarrantRule`s and compose them.
`fromRules` takes a variadic list *or* a single array, and accepts no bindings
(the rules are already resolved):

```php
use Warrant\RuleSyntaxTree\WarrantRule;

$own      = WarrantRule::fromSyntax('if is_self they can view, update');
$noDelete = WarrantRule::fromSyntax('they cannot delete');

WarrantRuleSet::fromRules('timesheets', $own, $noDelete);
WarrantRuleSet::fromRules('timesheets', [$own, $noDelete]); // equivalent
```

**Directly with the parser**, if you want the parsed rules without a rule set:

```php
use Warrant\RuleSyntaxTree\Parsing\WarrantParser;

$rules = WarrantParser::parse('if is_self they can view', $bindings = []); // WarrantRule[]
$one   = WarrantParser::parseSingleRule('they cannot delete');            // WarrantRule
```

### Building rules programmatically

When a rule's shape depends on runtime data — a list of department ids, a
feature flag, values that don't belong in a string — a fluent builder is often
clearer than assembling DSL text. `WarrantRule::build()` returns a builder that
produces the **same AST** the parser does, so a built rule flows through the
identical validation and compilation. Nothing is ever serialized to a string,
so arbitrary PHP values in condition parameters survive untouched.

```php
use Warrant\RuleSyntaxTree\WarrantRule;

$rule = WarrantRule::build()
    ->if('is_self')
    ->orIf(fn ($c) => $c->if('is_manager')->andIf('in_region'))
    ->theyCan('view', 'update')
    ->theyCannot('delete')
    ->toRule();
```

That builds the same rule as:

```
if is_self or (is_manager and in_region) they can view, update; they cannot delete
```

**Conditions.** Each connective has a plain and a negated form, mirroring
Laravel's `where`/`orWhere`/`whereNot`:

| Method | DSL equivalent |
| --- | --- |
| `if` / `andIf` | `and` (both are aliases; the first term's connective is ignored) |
| `orIf` | `or` |
| `ifNot` / `andIfNot` | `and not` |
| `orIfNot` | `or not` |

Each takes a condition name (with optional parameters) **or** a closure:

```php
->if('in_department', ['sales', 'eng'])   // condition with parameters
->orIf(fn ($c) => $c->if('a')->orIf('b')) // closure = a parenthesized group
```

A **closure is a parenthesized group**. It receives a bare condition builder —
it has the `if`/`orIf`/… methods but no `theyCan`/`theyCannot`, because a group
is only ever a condition, never a whole rule.

**Clauses.** `theyCan(...$abilities)` and `theyCannot(...$abilities)` are
variadic and additive. A rule needs at least one clause: `toRule()` throws if
you call neither, exactly as the DSL rejects a bare `if` with no `they can` /
`they cannot` line. A rule with a `theyCannot` clause can also carry a denial
message via `->withDenialMessage(...)` — see [Exceptions](#exceptions).

**Precedence is identical to the DSL** — `not` > `and` > `or` — so the two
front-ends produce byte-for-byte identical trees. `->if('a')->andIf('b')->orIf('c')`
is `(a and b) or c`, not `a and (b or c)`.

**Composing dynamically.** The builder shines when the tree is data-driven.
Fold a list inside a group, or branch with `when()`:

```php
$rule = WarrantRule::build()
    ->if('is_self')
    ->orIf(function ($c) use ($departmentIds) {
        foreach ($departmentIds as $id) {
            $c->orIf('in_department', [$id]);
        }
    })
    ->when($includeManagers, fn ($c) => $c->orIf('is_manager'))
    ->theyCan('view')
    ->toRule();
```

An empty group folds to `false`, so it contributes nothing to an `or` and vetoes
an `and` — folding an empty list is a safe no-op.

**Splicing in DSL text.** `ifRaw()` / `orIfRaw()` parse a DSL fragment and splice
it in as one group — author the readable part as text, compose the rest
structurally:

```php
->ifRaw('is_admin or is_owner', $bindings = [])->andIf('in_region')
```

**Dropping into a rule set.** `fromRules` accepts builders directly (it finalizes
each via `toRule()`), so you don't have to call `toRule()` yourself:

```php
WarrantRuleSet::fromRules(
    'timesheets',
    WarrantRule::build()->if('is_self')->theyCan('view', 'update'),
    WarrantRule::build()->theyCannot('delete'),
);
```

### Implicit rules

A schema can declare rules that are **always merged into the rule set**,
regardless of what the resolver returns, by overriding `implicitRules()`. They're
added to every resolved rule set before compilation, so they're validated and
obey deny-overrides exactly like resolver rules — and, like every rule, they're
still evaluated against the *current* user via their conditions. Ideal for
baseline guarantees — an admin escape hatch, or a suspension lockout:

```php
use Warrant\RuleSyntaxTree\WarrantRule;

class TimesheetSchema extends WarrantSchema
{
    protected function implicitRules(): array
    {
        return [
            WarrantRule::fromSyntax('if is_admin they can *'),
            WarrantRule::fromSyntax('if is_suspended they cannot *'),
        ];
    }
}
```

Because deny-overrides is order-independent, an implicit `cannot` beats any
resolver-supplied `can`.

### Registering the resolver

Warrant ships **no** default resolver — you must configure one in
`config/warrant.php`, plus the list of schemas Warrant should know about:

```php
return [
    'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,

    'schemas' => [
        App\Warrant\TimesheetSchema::class,
        App\Warrant\ProjectSchema::class,
    ],
];
```

---

## Checking access

Once the schema, resolver, and rules are in place, you never touch the compiler
directly. You ask questions through the model, query scopes, the schema's static
helpers, or middleware.

### On the model

Add the `HasWarrantSchema` trait and point it at the schema:

```php
use Illuminate\Database\Eloquent\Model;
use Warrant\HasWarrantSchema;

class Timesheet extends Model
{
    use HasWarrantSchema;

    public function warrantSchema(): string
    {
        return App\Warrant\TimesheetSchema::class;
    }
}
```

That unlocks:

```php
// Boolean checks (run as a scoped EXISTS query):
Timesheet::userHasAbilities('update', $timesheet);           // for a model instance
Timesheet::userHasAbilities('update', $timesheetId);         // for a key
Timesheet::userHasAbilities(['view', 'update'], $timesheet); // several at once
Timesheet::userHasAbilities('create');                       // no-target / capability

// The ability list for one record:
Timesheet::getUserAbilities($timesheet);                     // ['view', 'update']

// Attach abilities onto a loaded model:
$timesheet->loadUserAbilities();                                 // sets $timesheet->abilities
```

Each accepts an optional `$user` (defaults to `auth()->user()`) and, for
`userHasAbilities`, an `AbilityMatchMode`.

### Filtering queries

The `userHasAbility` scope restricts a query to the rows the user may act on — the
"which records?" question, answered in SQL:

```php
// Timesheets the current user can update:
Timesheet::query()->userHasAbility('update')->paginate();

// Rows they can BOTH view and approve (see match modes):
Timesheet::query()->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ALL)->get();

// For a specific user:
Timesheet::query()->userHasAbility('delete', $user)->get();
```

### Per-row abilities

The `selectUserAbilities` scope attaches a computed `abilities` column — a JSON array
of what the user can do to *that* row — so your UI can render controls without
N extra checks:

```php
$rows = Timesheet::query()->selectUserAbilities()->get();

$rows->first()->abilities; // ['view', 'update']
```

On a list endpoint you often only care about a subset (say, just `update` to show
an Edit button). Narrowing it is a real cost saving — the attached subquery grows
one branch per ability:

```php
Timesheet::query()->selectUserAbilities(onlyAbilities: ['update'])->get();
```

### Passing context to a check

Every check API takes an optional `context:` array — the values for any
[`@context` keys](#check-time-context-context) the rules reference. It threads
through the model helpers, the query scopes, and the capability checks alike:

```php
// Boolean check:
Timesheet::userHasAbilities('update', $timesheet, context: ['workspace_id' => $id]);

// Row filtering:
Timesheet::query()->userHasAbility('update', context: ['workspace_id' => $id])->paginate();

// Per-row abilities, evaluated in one fixed frame:
Timesheet::query()->selectUserAbilities(context: ['workspace_id' => $id])->get();
```

Whatever you pass is merged *over* the schema's
[`defaultContext()`](#context-keys), with explicit values winning. A schema with a
`required` context key rejects a check that ends up without it — a loud error, so
a required frame is never silently skipped.

### Capability (no-target) checks

Not every check is about a row. "Can this user *create* timesheets?" or "can
they access *settings*?" have no target. Pass `null` as the target (or omit it):

```php
Timesheet::userHasAbilities('create');                 // target defaults to null
TimesheetSchema::getUserAbilities();                   // all no-target abilities
```

For section-level capabilities with no model at all, define a schema with
`public const model = ''` and only `#[GlobalCondition]` conditions. In a
no-target check, targeted conditions are treated as false, so only global
logic contributes.

### Match modes

When you check several abilities at once, `AbilityMatchMode` decides how they
combine:

- **`AbilityMatchMode::ALL`** (default) — the row/user must satisfy *every* listed
  ability.
- **`AbilityMatchMode::ANY`** — *any* one is enough.

```php
use Warrant\AbilityMatchMode;

Timesheet::query()->userHasAbility(['view', 'approve'], matchMode: AbilityMatchMode::ANY)->get();
```

### Could a user ever…? (reachability)

Every check so far asks about a concrete row (or the global capability frame).
A different, cheaper question is *"could this user **ever** update a timesheet —
is it even worth showing the button, or building the section?"* That's
**reachability**: a purely **structural** look at the rules the resolver hands
this user. It evaluates **no conditions** and runs **no SQL** — it only asks
whether a grant is *conceivable*.

The rule of thumb is *unconditionality*. A rule with an `if` is a "maybe" (whether
it fires depends on a condition we don't evaluate here); only unconditional rules
make us certain. Each ability lands in one of three states:

| `Warrant\Reachability` | meaning | typical UI use |
|---|---|---|
| `NEVER` | no rule grants it, or an unconditional `cannot` forbids it | hide the control entirely |
| `MAYBE` | a condition decides — they might or might not | show it, but check per row |
| `ALWAYS` | unconditionally granted, no unconditional deny | show it enabled |

The decision table, resolved top to bottom for one ability:

1. an unconditional `cannot` → `NEVER` (an undodgeable deny wins);
2. no `can` rule lists it → `NEVER` (no grant path at all);
3. an unconditional `can` and no *conditional* `cannot` → `ALWAYS`;
4. otherwise → `MAYBE`.

A *conditional* `cannot` is intentionally ignored: a different row/state can dodge
it, so it never lowers certainty. (This mirrors the compiler's own hard edges —
see [How it compiles to SQL](#how-it-compiles-to-sql).) Because `ALWAYS` ignores
conditional denies, it means "granted by the rules' shape," **not** a guarantee
every row passes — the per-row check is still the source of truth.

```php
use Warrant\Reachability;

// One ability, three-valued:
Timesheet::abilityReachability('update');            // Reachability::NEVER | MAYBE | ALWAYS

// The boolean questions:
Timesheet::userCouldEverHave('update');              // reachability !== NEVER
Timesheet::userAlwaysHas('view');                    // reachability === ALWAYS
Timesheet::userNeverHas('delete');                   // reachability === NEVER

// Whole-schema lists (over every declared ability):
Timesheet::getUserPossibleAbilities();               // ['view', 'update', 'approve']
Timesheet::getUserGuaranteedAbilities();             // ['view']
Timesheet::getUserImpossibleAbilities();             // ['delete']
```

Every method takes an optional `$user` (defaults to `auth()->user()`), and the
boolean forms take an `AbilityMatchMode` — `ALL` (default) needs every listed
ability to qualify, `ANY` needs one. There is **no** `context:` argument:
`@context` only ever feeds condition evaluation, which reachability never does.
The user is still needed, because the resolver may hand a different rule set to
each user, role, or tenant.

The same three booleans and the classifier are on the schema and the `Warrant`
facade too:

```php
TimesheetSchema::userCouldEverHave('update', $user);
Warrant::userCouldEverHave('timesheets', 'update', $user);   // by schema key or class
```

```php
// Rendering a nav without a query per link:
match (Timesheet::abilityReachability('update')) {
    Reachability::NEVER  => /* omit the Edit link */,
    Reachability::ALWAYS => /* show it, enabled */,
    Reachability::MAYBE  => /* show it; the row check decides per timesheet */,
};
```

### Route middleware

Warrant registers a `warrant` route middleware. Build the middleware string with
`WarrantMiddleware`:

```php
use Warrant\WarrantMiddleware;

// Capability (no-target) — gate a create route by schema key:
Route::post('/timesheets', ...)->middleware(WarrantMiddleware::canCreate('timesheets'));

// Targeted — gate by a route-model-bound parameter:
Route::get('/timesheets/{timesheet}', ...)
    ->middleware(WarrantMiddleware::string('timesheet', 'view'));

// Group helper:
WarrantMiddleware::guard('timesheets', 'view', function () {
    Route::get('/timesheets', ...);
});
```

There are `canView`, `canCreate`, `canUpdate`, `canDelete`, `canArchive`, and
`canManage` shortcuts. Under the hood the middleware resolves the target to a
schema (by schema key or by the route-bound model's class) and calls `authorize`,
so a `403` on a model-bound route surfaces the responsible rule's denial message
(see [Exceptions](#exceptions)) instead of a bare status.

Every builder is **dual-mode**: call it with no closure to get the middleware
string, or hand it a closure to wrap a route group — one method, no `*Guard`
twin. `guard` is the generic form of `string`:

```php
WarrantMiddleware::guard('timesheet', 'view');                       // returns the string
WarrantMiddleware::guard('timesheet', 'view', fn () => Route::get(...));  // groups the routes
```

#### Reachability guards

The reachability questions have matching guards — gate a section by whether the
user *could ever* act, or short-circuit a route to those who provably never can:

```php
// Only reachable if the user could ever view a timesheet — otherwise 403:
Route::get('/timesheets', ...)->middleware(WarrantMiddleware::couldEver('timesheets', 'view'));

// Only when the ability is guaranteed:
WarrantMiddleware::always('timesheets', 'create', fn () => Route::post('/timesheets', ...));

// Only when the user provably can never (e.g. an upsell page):
Route::get('/upgrade', ...)->middleware(WarrantMiddleware::never('timesheets', 'approve'));
```

These are target-free, so the first argument is always a schema key (or a
schema/model class), never a route parameter. They're dual-mode like the rest.

Under the hood the mode and match mode live in the **alias**, not in the
parameters — so everything after the colon is just the schema key and abilities,
and an ability may safely be named `any` or `all`:

```
warrant.could-ever:timesheets,view          warrant.could-ever.any:timesheets,view,approve
warrant.always:timesheets,view              warrant.always.any:timesheets,view,approve
warrant.never:timesheets,view               warrant.never.any:timesheets,view,approve
```

(The row-check `warrant:` alias keeps its own grammar, where an `any`/`all` token
after the schema key selects the match mode.)

---

## Exceptions

`userHasAbilities` answers yes/no. When a denial should **say why**, reach for
`authorize` — its throwing sibling — and attach a message to the rule that does
the forbidding.

```php
Timesheet::authorize('update', $timesheet); // returns void, or throws on denial
```

On success it returns; on failure it throws a `WarrantAuthorizationException`,
which extends Laravel's `Illuminate\Auth\Access\AuthorizationException` — so the
framework renders it as a **403** carrying the message, with no handler wiring.

**Attaching a message.** Only a `cannot` rule can carry one, because only a
`cannot` actively forbids — a missing `can` is the *absence* of a grant, which
names no single rule. `withDenialMessage` lives on `WarrantRule` itself, so you
can attach one to any rule regardless of how it was authored — including a rule
parsed from the string DSL:

```php
// On a rule parsed from syntax:
WarrantRule::fromSyntax('if is_locked they cannot update')
    ->withDenialMessage('This timesheet is locked and can no longer be edited.');

// Or mid-chain on the fluent builder:
WarrantRule::build()
    ->if('is_locked')->theyCannot('update')
    ->withDenialMessage('This timesheet is locked and can no longer be edited.')
    ->toRule();
```

`WarrantRule` is immutable, so `withDenialMessage` returns a *copy* carrying the
message. The message may also be a **closure**, receiving a `WarrantDenialContext`
and returning either a string, or a `Throwable` to throw as-is:

```php
->withDenialMessage(fn (WarrantDenialContext $c) => "You cannot edit {$c->target->title} while it is locked.")
->withDenialMessage(fn (WarrantDenialContext $c) => new TimesheetLockedException($c->target))
```

The context carries the subject and object (`$c->user`, `$c->target`), the schema
and effective `context` bag, the `$c->gate` that was checked (`$c->gate->abilities`
+ `$c->gate->matchMode`), the responsible `$c->rule`, and `$c->deniedAbilities` —
the concrete gate abilities this rule blocked, with any `*` already resolved so
you never expand a wildcard yourself. Returning your own exception opts out of the
automatic 403 — its own rendering applies.

**How the rule is chosen.** After a denial, Warrant walks the rules in resolver
order (implicit rules first) and surfaces the **first message-bearing `cannot`
whose condition actually matched**. If several forbid, the earliest one carrying a
message wins. Diagnosis runs the same condition SQL as the check, so it can never
blame a rule that didn't fire. It also works for **no-target** checks — there only
global or unconditional `cannot` rules can be the cause, since a targeted condition
can't fire without a row.

Messages are attached in PHP via `withDenialMessage`, never written *inside* DSL
text — the language has no syntax for them (a closure couldn't be expressed
anyway), and `toSyntax()` drops any attached message. A `withDenialMessage` on a
rule with no `theyCannot` clause is rejected at validation — it could never fire.

**When no rule grants access.** A `cannot` message explains being *forbidden*.
The other way a check fails is that nothing granted it — no `cannot` forbade the
user, but no `can` allowed them either. There's no rule to point at, so that
message lives on the schema, in `ungrantedDenialMessage`:

```php
class TimesheetSchema extends WarrantSchema
{
    protected function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null
    {
        return match (true) {
            in_array('approve', $c->ungrantedAbilities, true) => 'You need an approver role.',
            default => null, // keep the generic 403
        };
    }
}
```

It receives a `WarrantUngrantedContext` — like the denial context but with **no
rule** (there is none) and an `$c->ungrantedAbilities` list instead of
`deniedAbilities`: the concrete gate abilities that had no grant. Under `ANY` that
is the whole gate (so you can say "you need at least one of …"); under `ALL` it is
just the missing subset. Return a string (wrapped in a 403), a `Throwable`, or
null to keep the generic default.

A message-less `cannot` is still a deliberate forbid, not a lack of grant — so
rather than fall through to `ungrantedDenialMessage`, it has its own schema-level
catch, `forbiddenDenialMessage`, for when you want one default message across many
message-less `cannot` rules:

```php
protected function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null
{
    return "You cannot {$c->deniedAbilities[0]} this timesheet.";
}
```

It receives the full `WarrantDenialContext` — the responsible `$c->rule` and the
`$c->deniedAbilities` it blocked — since there *is* a rule, it just carried no
message of its own. Warrant tries the message sources in priority order and takes
the first that returns non-null:

| Cause of the denial | Message used |
| --- | --- |
| a matching `cannot` **with** a message | that rule's `withDenialMessage` |
| a matching `cannot` **without** a message | schema `forbiddenDenialMessage()` |
| nothing granted the ability | schema `ungrantedDenialMessage()` |
| none of the above returned a message | generic 403 |

Forbid sources are tried before the ungranted source, so when abilities fail for
mixed reasons (one forbidden, one merely ungranted) the forbid wins — being
actively blocked, and by what, is the more specific answer. The ungranted hook is
reached only if no forbid is present, or the forbidden hook declines (returns
null).

---

## How it compiles to SQL

You don't need this section to use Warrant, but it explains *why* the semantics
are what they are.

For each requested ability, the compiler assembles one predicate from all the
rules that mention it (or `*`):

```text
predicate(ability) =
    ( OR of each `can` rule's if-expression )
    AND ( AND of NOT(each `cannot` rule's if-expression) )
```

with these hard edges:

- An **unconditional `cannot`** → `AND NOT(true)` → `1 = 0`: this user can never
  have the ability, on any row.
- **No `can` rule** for the ability → `1 = 0`: denied to this user by default.
- An **unconditional `can`** → an always-true `1 = 1` term: this user has the
  ability on every row.

Every condition leaf is wrapped as an **`EXISTS`** subquery, which makes it a
strict boolean: a condition that touches a `NULL` column yields `false`, not SQL's
"unknown," and negation via `NOT EXISTS` is exact. This is why `not`/`cannot`
behave predictably — no three-valued-logic surprises leak into your
authorization results. Boolean structure (`and`/`or`/`not`) becomes nested
`WHERE` groups, with negation pushed to the leaves via De Morgan.

### Worked examples

The examples below use a `timesheets` schema whose conditions emit this SQL (the
body of each `#[Condition]` method — see [Conditions](#conditions)):

| Condition | SQL it adds |
| --- | --- |
| `is_self` (targeted) | `timesheets.user_id = ?` |
| `manages_department(:dept)` (targeted) | `timesheets.department_id = ?` |
| `is_locked` (targeted) | `timesheets.locked = 1` |
| `is_admin` (global) | `? = ?`  (the string `admin` vs the user's role) |

Each ability compiles to one predicate that Warrant drops into your query's
`WHERE`. The SQL below is real compiler output with the redundant nested
parentheses trimmed and `?` placeholders annotated with their bound values.

**An unconditional grant** — `they can view` — is an always-true term:

```sql
select * from timesheets where (1 = 1)
```

**A single targeted condition** — `if is_self they can view` — becomes one
`EXISTS`. The condition's `timesheets.user_id` correlates to the outer row, so the
subquery is true exactly for the rows the user owns:

```sql
select * from timesheets
where exists (
    select 1 from (select 1) as warrant_exists
    where timesheets.user_id = ?          -- ? → the current user's id
)
```

**A global condition** — `if is_admin they can delete` — compiles the same way,
but its `EXISTS` doesn't reference the row (it's true or false for the whole
request):

```sql
select * from timesheets
where exists (
    select 1 from (select 1) as warrant_exists
    where ? = ?                            -- 'admin' = the user's role
)
```

**No `can` rule** for the requested ability is a hard `1 = 0` — denied to everyone
by default, so the query returns no rows:

```sql
select * from timesheets where (1 = 0)
```

**An unconditional `cannot`** collapses to `1 = 0` too, even alongside a grant —
`they can view` + `they cannot view` — because deny always wins:

```sql
select * from timesheets where (1 = 0)
```

**The full opener from the top of this README** — three rules for `update`:

```text
if is_self or manages_department('sales') they can update
if is_locked and not is_admin they cannot update
if is_admin they can *
```

compiles to one predicate: an OR of every `can` source, `AND`ed with the negated
`cannot`:

```sql
select * from timesheets where
  (
    -- grant side: the two-part first rule, OR the wildcard `is_admin` rule
    exists (select 1 from (select 1) as warrant_exists where timesheets.user_id = ?)        -- is_self
    or exists (select 1 from (select 1) as warrant_exists where timesheets.department_id = ?) -- manages_department('sales')
    or exists (select 1 from (select 1) as warrant_exists where ? = ?)                         -- is_admin (from `they can *`)
  )
  and (
    -- deny side: NOT(is_locked and not is_admin), De-Morgan'd onto the leaves
    not exists (select 1 from (select 1) as warrant_exists where timesheets.locked = 1)      -- not is_locked
    or exists (select 1 from (select 1) as warrant_exists where ? = ?)                         -- or is_admin
  )
-- bindings: [user_id, 'sales', 'admin', role, 'admin', role]
```

Note the deny clause: `cannot update` when `is_locked and not is_admin` becomes
`NOT(is_locked AND NOT is_admin)`, which De Morgan turns into
`(NOT is_locked OR is_admin)` — negation always lands on the `EXISTS` leaves, never
on a group, so it stays a strict two-valued boolean.

**Several abilities** combine per the match mode. `userHasAbility(['view', 'update'],
matchMode: ALL)` `AND`s the two per-ability predicates (ANY would `OR` them):

```sql
select * from timesheets where
      exists (select 1 from (select 1) as warrant_exists where timesheets.user_id = ?)  -- view: is_self
  and exists (select 1 from (select 1) as warrant_exists where ? = ?)                     -- update: is_admin
```

**Per-row abilities** (`selectUserAbilities`) run the same per-ability predicates as a
correlated subquery per row, one `SELECT ? as ability WHERE <predicate>` UNION-ALL
branch per requested ability, aggregated into a JSON array:

```sql
select *, (
    select coalesce(json_group_array(ability), json_array())
    from (
              select ? as ability where exists (select 1 from (select 1) as warrant_exists where timesheets.user_id = ?)  -- view
        union all
              select ? as ability where exists (select 1 from (select 1) as warrant_exists where ? = ?)                     -- delete
    ) as available_abilities
) as abilities
from timesheets
```

Each row's `abilities` column ends up holding just the abilities whose predicate
held for that row — e.g. `["view"]` for a timesheet the user owns but can't delete.
(The JSON aggregate differs by driver: `json_group_array` on SQLite, `json_agg` on
Postgres, `json_arrayagg` on MySQL/MariaDB.)

Because it's all one compiler, row filtering applies these predicates to your
query's `WHERE`, per-row selection runs them as correlated subqueries, and the
"which rows?", "what can they do?", and "can they?" questions can never disagree.

Compilation validates every ability and condition name against the schema; an
unknown name is a hard error, so a typo in a stored rule fails loudly rather than
silently granting or denying.

---

## Testing

Warrant's own suite drives real SQLite and asserts on rows and ability lists
rather than SQL strings. The same approach works for your schemas: register a
fake resolver that returns a fixed `WarrantRuleSet`, seed a table, and assert what
comes back.

```php
app()->instance(RuleResolver::class, new class implements RuleResolver {
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        return WarrantRuleSet::fromSyntax($context->schemaKey, 'if is_self they can view');
    }
});

$visible = Timesheet::query()->userHasAbility('view', $user)->pluck('id');
expect($visible)->toContain($ownTimesheet->id)->not->toContain($othersTimesheet->id);
```

---

## API cheat sheet

**Define a schema** — `extends Warrant\Schema\WarrantSchema`
- `const model` — managed Eloquent model (or `''` for a capability schema)
- `const schemaKey` — optional schema-key override
- `#[Ability] const X = '...'` — declare an ability
- `#[ContextKey] const X = '...'` — declare a check-time context key (required by default; `required: false` to opt out)
- `#[TargetedCondition]` / `#[GlobalCondition]` methods — declare conditions
- `protected function implicitRules(): array` — always-on rules
- `protected function defaultContext(): array` — default check-time context
- `protected function forbiddenDenialMessage(WarrantDenialContext $c): string|Throwable|null` — schema fallback for a message-less `cannot` forbid
- `protected function ungrantedDenialMessage(WarrantUngrantedContext $c): string|Throwable|null` — message when a check fails for lack of a grant (no `cannot` forbade, no `can` allowed)

**Build rules**
- `WarrantRuleSet::fromSyntax(string $entity, string $syntax, array $bindings = [])`
- `WarrantRuleSet::fromRules(string $entity, WarrantRule|WarrantRuleBuilder|array ...$rules)`
- `WarrantRule::fromSyntax(string $syntax, array $bindings = [])`
- `WarrantParser::parse(string $source, array $bindings = []): WarrantRule[]`
- `WarrantParser::parseSingleRule(string $source, array $bindings = []): WarrantRule`
- `WarrantRule::build()` — fluent builder: `->if/andIf/orIf/ifNot/…`, `->theyCan/theyCannot`, `->toRule()`
- `->withDenialMessage(string|Closure $message)` — denial message on a `cannot` rule (string, or `fn (WarrantDenialContext) => string|Throwable`); available on the builder mid-chain **and** on any `WarrantRule` (e.g. `WarrantRule::fromSyntax(...)->withDenialMessage(...)`)

**Provide rules** — implement `Warrant\RuleResolver`
- `resolve(RuleResolutionContext $context): WarrantRuleSet`
- context: `->user`, `->schemaKey`, `->schema`, `->model`
- register in `config/warrant.php` → `rule_resolver`, `schemas`

**Check access** — `use Warrant\HasWarrantSchema` on the model
- `Model::userHasAbilities($abilities, $target = null, $user = null, $matchMode = ALL, $context = []): bool`
- `Model::authorize($abilities, $target = null, $user = null, $matchMode = ALL, $context = []): void` — throwing sibling; throws `Warrant\WarrantAuthorizationException` (403). Message priority: rule `withDenialMessage` → schema `forbiddenDenialMessage` → schema `ungrantedDenialMessage` → generic
  - denial-message closures receive `WarrantDenialContext` (`user, target, schema, context, gate, rule, deniedAbilities`); the ungranted hook receives `WarrantUngrantedContext` (`… gate, ungrantedAbilities`, no rule); `gate` is a `WarrantGate` (`abilities`, `matchMode`)
- `Model::getUserAbilities($target = null, $user = null, $context = []): array`
- `->userHasAbility($abilities, $user = null, $matchMode = ALL, $context = [])` — query scope
- `->selectUserAbilities($user = null, $key = 'abilities', ?array $onlyAbilities = null, $context = [])` — query scope
- `$model->loadUserAbilities($user = null, $key = 'abilities', $context = [])` — attach the ability list to an instance
- `context:` — values for the rules' `@context` keys, merged over `defaultContext()`
- `Warrant\AbilityMatchMode::ALL | ANY`

**Reachability** — structural "could they ever?", no conditions evaluated, no SQL, no `context:`
- `Model::abilityReachability($ability, $user = null): Warrant\Reachability` — `NEVER | MAYBE | ALWAYS`
- `Model::userCouldEverHave($abilities, $user = null, $matchMode = ALL): bool` — `!== NEVER`
- `Model::userAlwaysHas($abilities, $user = null, $matchMode = ALL): bool` — `=== ALWAYS`
- `Model::userNeverHas($abilities, $user = null, $matchMode = ALL): bool` — `=== NEVER`
- `Model::getUserPossibleAbilities / getUserGuaranteedAbilities / getUserImpossibleAbilities($user = null): array`
- also on the schema and the `Warrant` facade (`Warrant::userCouldEverHave($schemaKeyOrClass, …)`)

**Middleware** — `Warrant\WarrantMiddleware` (all builders are dual-mode: string, or group when given a closure)
- `::string($target, $abilities, $matchMode = ALL)`
- `::guard($target, $abilities, ?Closure $routes = null, $matchMode = ALL)`
- `::canView / canCreate / canUpdate / canDelete / canArchive / canManage($target, ?Closure)`
- `::couldEver / always / never($target, $abilities, ?Closure $routes = null, $matchMode = ALL)` — reachability guards

## License

MIT.
