---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: The provider contract
description: The global rule provider, what it is handed, and every form it may return.
sidebar:
  order: 1
---

Rules are data, and Warrant never invents them. It asks two places, at request
time, for the rules governing one user's access to one resource: the global rule
provider, if you configured one, and the schema's own
[`rules()`](/schemas/schema-policy/#schema-rules). Both are handed the same
context, and their rules are merged into one set.

The global provider is the seam where your access model meets Warrant:

```php
namespace App\Warrant;

use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;

class DatabaseRuleProvider implements RuleProvider
{
    public function rules(RuleProviderContext $context): iterable
    {
        return DB::table('role_rules')
            ->where('role_id', $context->user->role_id)
            ->where('schema_key', $context->schemaKey)
            ->pluck('rule');            // a collection of rule text
    }
}
```

## What you are handed

```php
$context->user;       // the Authenticatable being checked, nullable
$context->schemaKey;  // 'documents'
$context->schema;     // App\Warrant\DocumentSchema
$context->model;      // App\Models\Document, or null for a schema with no model
```

You are asked once per user and schema, so the provider is called with one schema
key at a time rather than being asked for everything.

## What you may return

Whatever form you have the rules in. Warrant reads each into the rule set for the
schema you were asked about:

| Return | Read as |
| --- | --- |
| `string` | rule text — unscoped rules, or `for <schema>` rule sets |
| `WarrantSyntax` | already-parsed rule text, read the same way |
| `RuleSetNode` | its rules |
| a rule entry — a rule, an ability block, an `@include` | that one entry |
| an `array`, `Collection`, or any iterable | each element read by these same rules, in order |

The forms nest and mix freely, so a list of strings, rule sets and single rules is
fine. Every rule set among them — a `RuleSetNode`, or a `for <schema>` header in
rule text — has to target the schema you were asked about. One for a different
schema is caught:

```text
The rule provider was asked for schema [documents] but returned a rule set
targeting [folders].
```

Returning nothing — `''`, `[]`, an empty collection or an empty set — is legitimate
and means this user has no rules for this resource, which denies everything. That is the right answer for an anonymous or
unprivileged user.

## Registering it

```php
// config/warrant.php
'rule_provider' => App\Warrant\DatabaseRuleProvider::class,
```

Warrant builds it through the container, so constructor dependencies are injected:

```php
public function __construct(
    private RuleRepository $rules,
    private Tenancy $tenancy,
) {}
```

It is optional. Leave it unset and each schema's own `rules()` governs access to
that schema alone; with neither, a schema's rule set is empty and every check
denies.

## Shapes that work

**Rules stored as text, per role.** The example above. Each row is read as rule
text, and the rows merge into one set.

**Rules built in code, per role name.** No storage at all, and the policy lives in
your repository where it can be reviewed:

```php
public function rules(RuleProviderContext $context): string|iterable
{
    return match ($context->user->role) {
        'admin'  => 'they can *',
        'editor' => $this->editorRules($context),
        default  => [],
    };
}
```

**Rules from a file on disk**, which is what [`.warrant`
files](/supplying-rules/where-rules-live/) are for:

```php
public function rules(RuleProviderContext $context): RuleSetNode|iterable
{
    $file = WarrantSyntax::parseFile(base_path("warrant/{$context->user->role}.warrant"));

    return $file->forSchema($context->schemaKey) ?? [];
}
```

**Several sources merged**, which is the shape most real applications end up with.
That has [its own page](/supplying-rules/composing/).

## Where the user is null

`$context->user` is nullable, because a schema-bound guard can be constructed
before a check runs. In practice every check path requires a user, so a provider
may safely treat null as "no rules":

```php
if ($context->user === null) {
    return [];
}
```

## It is called once per request

Not once per check. Warrant memoizes a guard per user for the life of the request,
and each guard memoizes the rule set it resolved, so your provider and the
schema's `rules()` each run at most once per user and schema. See [memoization and
flushing](/supplying-rules/memoization/), which also covers what to do after a
permission change.
