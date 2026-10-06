---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Octane and long-lived processes
description: What Warrant drops between requests and jobs, and what is yours to reset.
sidebar:
  order: 3
---

A long-lived worker is the environment where a stale rule set does real damage,
answering from permissions resolved for a different request. Warrant handles its
own state; the question is what else is holding on.

## What Warrant drops, and when

| Runtime | Dropped on |
| --- | --- |
| PHP-FPM | the process ending, so the memo never outlives one request |
| Octane | `RequestTerminated`, `TaskTerminated` |
| Queue workers | `JobProcessed`, `JobFailed` |

The listeners are registered by the service provider. There is nothing to configure
and nothing to add to `octane.php`.

So under Octane, request N never sees request N-1's rule sets, and a worker never
carries one job's user into the next.

## What is yours

**A provider holding state.** Warrant builds your provider through the container.
If you registered it as a singleton and it caches anything in a property, that
property survives requests under Octane:

```php
// Dangerous under Octane.
class CachingRuleProvider implements RuleProvider
{
    private array $cache = [];

    public function rules(RuleProviderContext $context): RuleSetNode
    {
        return $this->cache[$context->user->id] ??= $this->build($context);
    }
}
```

Either do not cache in a property, or bind the provider as a scoped instance:

```php
$this->app->scoped(RuleProvider::class, CachingRuleProvider::class);
```

Or use the cache, which has explicit invalidation.

**A schema holding state.** Schemas are resolved per use and hold no user, so this
is rare. It stops being rare if you add a static property:

```php
// Dangerous: leaks between requests.
class DocumentSchema extends WarrantSchema
{
    private static array $teamIds = [];
}
```

**Tenancy.** `defaultContext()` usually reads a request-scoped service. Under
Octane, confirm that service is scoped rather than singleton, or every request
after the first gets the first request's tenant. That is the highest-severity
Octane bug available in a tenant-scoped application, and it is not Warrant's to
prevent.

## Queue workers

Warrant flushes between jobs, so the risk is within one job.

Set the frame before any check:

```php
class ExportTenantDocuments implements ShouldQueue
{
    public function __construct(public string $tenantId, public int $userId) {}

    public function handle(Tenancy $tenancy): void
    {
        $tenancy->setCurrent($this->tenantId);

        $user = User::findOrFail($this->userId);

        Document::query()
            ->userHasAbility('view', $user)
            ->chunk(500, fn ($rows) => $this->write($rows));
    }
}
```

Two habits worth keeping.

**Serialize the user id, not the user.** A serialized model carries a snapshot of
attributes, and a role change between queueing and running would be missed.

**Flush when a job switches user or tenant:**

```php
foreach ($tenants as $tenant) {
    $tenancy->setCurrent($tenant->id);
    Warrant::flush();

    // ...
}
```

## Scheduled commands and console

A console command has no authenticated user, so every check needs an explicit one:

```php
public function handle(): void
{
    $user = User::findOrFail($this->argument('user'));

    $count = Document::query()->userHasAbility('view', $user)->count();

    $this->info("{$user->email} can see {$count} documents.");
}
```

Omitting it throws rather than denying silently:

```text
Warrant requires an authenticated user or an explicit user instance.
```

A long-running command that iterates users should flush between them, since nothing
in a single `artisan` process drops the memo on its own:

```php
User::query()->chunkById(100, function ($users) {
    foreach ($users as $user) {
        $this->report($user);
        Warrant::flush($user);
    }
});
```

Without that, a command over a hundred thousand users accumulates a guard and a
rule set for each one.

## Horizon and long jobs

Nothing special, with one exception. A job running for minutes and checking
permissions throughout holds the rule set it resolved at the start. If a permission
change during that window should take effect, flush periodically. Usually it should
not, and a job seeing a consistent policy for its whole run is the better
behaviour.
