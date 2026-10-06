---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Multi-tenancy
description: Scope everything to the current tenant, and make a missing tenant an error rather than a leak.
sidebar:
  order: 3
---

Tenant scoping is the case where getting it slightly wrong is worst, so it is worth
setting up so that the failure mode is loud.

Three pieces: the tenant in [context](/concepts/context/), a default so ordinary
calls do not have to pass it, and a requirement so an unset tenant throws.

## The schema

```php
use Warrant\Schema\RequiredContext;

class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[RequiredContext] public const TENANT = 'tenant_id';

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';

    #[RowCondition]
    public function inCurrentTenant(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('tenant_id'), $c->context['tenant_id']);
    }

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
    }

    protected function defaultContext(): array
    {
        return ['tenant_id' => app(Tenancy::class)->currentId()];
    }
}
```

The condition reads `$c->context` directly rather than taking a `@context`
argument, because every rule on this resource is tenant-scoped and repeating the
key in each one is noise. See
[reading context in conditions](/concepts/context/in-conditions/).

## The rules

Every grant is `and in_current_tenant`:

```warrant
for documents {
    if in_current_tenant and is_mine they can view, update
    if in_current_tenant and is_team_document they can view
}
```

## Why require the key

`#[RequiredContext]` means every check throws if the tenant is absent:

```text
Schema [App\Warrant\DocumentSchema] requires context key(s) [tenant_id]; supply them
at the check or via defaultContext().
```

Without it, an unset tenant makes the condition compare against `null`, which is
unknown, which grants nothing. Safe, and silent: an entire tenant's users see empty
lists with no error anywhere.

With the default in place, the requirement only ever fires when tenancy genuinely
is not initialized, such as in a queued job that forgot to set it. Which is exactly
when you want to hear about it.

## Queues and console

The default reads from a request-scoped service, so a job has to set it:

```php
class ExportTenantDocuments implements ShouldQueue
{
    public function __construct(public string $tenantId, public int $userId) {}

    public function handle(Tenancy $tenancy): void
    {
        $tenancy->setCurrent($this->tenantId);

        $user = User::findOrFail($this->userId);

        Document::query()->userHasAbility('view', $user)->chunk(500, /* ... */);
    }
}
```

Two things to remember in a worker. Set the tenant before any check, and flush
between jobs if the same worker serves several tenants:

```php
Warrant::flush();
```

Warrant drops its memo on `JobProcessed` and `JobFailed` already, so this is only
needed when you switch tenant within one job.

## Belt and braces

Context scoping is an authorization rule, not a data boundary. It only applies
where a Warrant check runs. A query with no `userHasAbility` on it returns every
tenant's rows.

If the boundary has to be absolute, keep a global scope for the raw isolation and
let Warrant handle who may do what within it:

```php
class Document extends Model
{
    use HasWarrantSchema;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn ($q) =>
            $q->where('documents.tenant_id', app(Tenancy::class)->currentId()));
    }
}
```

Remember that a Warrant condition receives a builder carrying **no** global scopes,
so the schema's own `in_current_tenant` condition is still doing real work inside
the predicate. The two are not redundant.

## Tenant-specific rules

Tenancy usually means the policy differs per tenant too, which is the provider's
job:

```php
public function rules(RuleProviderContext $context): RuleSetNode
{
    $tenantId = app(Tenancy::class)->currentId();

    $texts = DB::table('tenant_role_rules')
        ->where('tenant_id', $tenantId)
        ->whereIn('role', $context->user->roleNamesFor($tenantId))
        ->where('schema_key', $context->schemaKey)
        ->pluck('rules');

    return $texts->isEmpty()
        ? RuleSetNode::fromRules($context->schemaKey)
        : WarrantSyntax::parse($texts->implode("\n"))->scopedTo($context->schemaKey);
}
```

:::caution[The memo is keyed by user, not by tenant]
If one request can switch tenant, flush in between:

```php
$tenancy->setCurrent($other);
Warrant::flush($user);
```
:::

## Crossing a hop

A hop hands the other schema a fresh context bag, holding only that schema's own
defaults. A target with the same `defaultContext()` as `DocumentSchema` gets the
current tenant on its own. The caller's bag never crosses, though, so a check made
with an explicit `tenant_id` does not carry it into the hop. Pass the key when the
two must agree:

```warrant
if can(view for folders(@column folder_id) with tenant_id = @context tenant_id)
they can view
```

A target with no default for the key, and no `with` to supply it, sees it absent.
Since `#[RequiredContext]` is not enforced at a boundary, that shows up as a
condition comparing against `null`, which grants nothing. If you are scoping by
tenant, give every tenant-scoped schema the default, and audit your hops for the
`with` clause wherever a check may name a tenant other than the current one.

## Testing

```php
it('never shows another tenant rows', function () {
    app(Tenancy::class)->setCurrent('tenant-a');

    $ours   = Document::factory()->create(['tenant_id' => 'tenant-a', 'user_id' => $user->id]);
    $theirs = Document::factory()->create(['tenant_id' => 'tenant-b', 'user_id' => $user->id]);

    $ids = Document::query()->userHasAbility('view', $user)->pluck('id');

    expect($ids)->toContain($ours->id)->not->toContain($theirs->id);
});

it('throws when no tenant is set', function () {
    app(Tenancy::class)->clear();

    Warrant::can('view', $document, user: $user);
})->throws(InvalidArgumentException::class, 'requires context key(s) [tenant_id]');
```

That second test is the valuable one.
