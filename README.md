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

> [!NOTE]
> **Not an official Laravel package.** Laravel Warrant is an independent,
> open-source project. It is not affiliated with, maintained by, or endorsed by
> the Laravel team.

---

Schema-based authorization for Laravel that compiles a small, human-readable
rule language **directly into SQL** — so "what can this user do?" and
"which rows can this user touch?" are answered by the database in a single query,
not by loading records into memory.

```text
if is_self or (is_manager and same_department)
they can view, update
they cannot delete
```

That block is a real, complete Warrant rule. Warrant turns it into a `WHERE`
clause.

## Installation

```bash
composer require patrickhanna/laravel-warrant
```

The service provider auto-registers. Publish the config to edit it in place:

```bash
php artisan vendor:publish --tag=warrant-config
```

**Requirements:** PHP 8.2+, Laravel 11 or 12. The SQL Warrant generates is
supported on PostgreSQL, MySQL/MariaDB, and SQLite.

## Documentation

Full docs live at **[laravel-warrant.dev](https://laravel-warrant.dev)**:

- [Quick start](https://laravel-warrant.dev/getting-started/quick-start/) · [Core concepts](https://laravel-warrant.dev/getting-started/core-concepts/) · [vs. spatie/laravel-permission](https://laravel-warrant.dev/getting-started/vs-spatie-laravel-permission/)
- [Schemas](https://laravel-warrant.dev/guides/schemas/) · [Conditions](https://laravel-warrant.dev/guides/conditions/) · [Check-time context](https://laravel-warrant.dev/guides/context/)
- [The rule language](https://laravel-warrant.dev/guides/rule-language/) · [Building rules](https://laravel-warrant.dev/guides/rule-builder/) · [Providing rules](https://laravel-warrant.dev/guides/resolvers/)
- [Checking access](https://laravel-warrant.dev/guides/checking-access/) · [Reachability](https://laravel-warrant.dev/guides/reachability/) · [Route middleware](https://laravel-warrant.dev/guides/middleware/) · [Denial messages](https://laravel-warrant.dev/guides/denial-messages/)
- [How it compiles to SQL](https://laravel-warrant.dev/guides/how-it-compiles/) · [Testing](https://laravel-warrant.dev/guides/testing/)
- API reference: [Checking](https://laravel-warrant.dev/reference/checking-api/) · [Schema](https://laravel-warrant.dev/reference/schema-api/) · [Rule building](https://laravel-warrant.dev/reference/rule-building-api/) · [Middleware](https://laravel-warrant.dev/reference/middleware-api/) · [Errors](https://laravel-warrant.dev/reference/errors/) · [Cheat sheet](https://laravel-warrant.dev/reference/api-cheat-sheet/)

## Why Warrant

- **One source of truth.** The same rule set answers a single check, filters a
  list, and reports the per-row ability list — no permission logic duplicated
  between a policy and a query.
- **Compiles to SQL.** Rules never run in PHP or pull your models into memory;
  they become a `WHERE` clause the database evaluates.
- **Rules are data.** Store them in the database, generate them from a GUI, or
  hard-code them — you decide where they come from and change them without
  touching app code.
- **Readable language.** Rules are written in a small `if … they can/cannot …`
  language that non-authors can follow.
- **Integrates with Laravel's Gate.** `$user->can()`, `@can`, `Gate::authorize`,
  and the `can:` route middleware resolve Warrant abilities, while abilities no
  schema declares fall through to your existing policies.

See [Why Warrant](https://laravel-warrant.dev/getting-started/why-warrant/) for
the full rationale and a comparison with the policy/query approach.

## A quick example

**1. The model** uses the trait and names its schema:

```php
use Illuminate\Database\Eloquent\Model;
use Warrant\HasWarrantSchema;

class Timesheet extends Model
{
    use HasWarrantSchema;

    public function warrantSchema(): string
    {
        return \App\Warrant\TimesheetSchema::class;
    }
}
```

**2. The schema** declares the vocabulary — abilities and conditions:

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

    // Global: a plain yes/no about the user, independent of any row.
    #[GlobalCondition]
    public function isAdmin(GlobalConditionContext $c): bool
    {
        return (bool) $c->user->is_admin;
    }
}
```

**3. The resolver** hands rules (as data) to Warrant for the current user:

```php
namespace App\Warrant;

use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRuleSet;

class DatabaseRuleResolver implements RuleResolver
{
    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        // Load the raw rule string + any binding values for this user/resource.
        [$syntax, $bindings] = MyRuleStore::for(
            user: $context->user,
            resource: $context->schemaKey, // 'timesheets'
        );

        return WarrantRuleSet::fromSyntax($context->schemaKey, $syntax, $bindings);
    }
}
```

The rules themselves are just text:

```text
if is_self they can view, update, delete
if in_department(?, ?) they can view, approve
if is_admin they can *
```

**4. Register** the resolver and schema in `config/warrant.php`:

```php
'rule_resolver' => App\Warrant\DatabaseRuleResolver::class,
'schemas' => [App\Warrant\TimesheetSchema::class],
```

**5. Check access** — every call is a single SQL query:

```php
// Which timesheets can the current user update?
$editable = Timesheet::query()->userHasAbility('update')->get();

// Can this user approve this specific timesheet?
if (Timesheet::userHasAbilities('approve', $timesheet)) { /* ... */ }

// Attach the per-row ability list, e.g. for rendering buttons.
$rows = Timesheet::query()->selectUserAbilities()->get();
$rows->first()->abilities; // e.g. ['view', 'update']

// Or go through Laravel's Gate — Warrant resolves these too:
$user->can('approve', $timesheet);
```

The [Quick start](https://laravel-warrant.dev/getting-started/quick-start/) walks
through this end to end.

## License

Laravel Warrant is open-source software licensed under the
[MIT license](LICENSE).
