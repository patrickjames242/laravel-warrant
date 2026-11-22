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

use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;

class DatabaseRuleProvider implements IRuleProvider
{
    public function rules(RuleProviderContext $context): iterable
    {
        return DB::table('role_rules')
            ->where('schema_key', $context->schemaKey)
            ->get()
            ->map(fn ($row) => warrant(
                "when has_role(:role) {\n{$row->rules}\n}",
                ['role' => $row->role],
            ));
    }
}
```

Rules are stored per role, but every role's rules are returned, each inside a
`when` block on that role. The provider picks rules by schema rather than by user,
so the same rules answer every question, including
[which users hold an ability](/checking/filtering-users/). See
[branching in the rules or in PHP](#branching-in-the-rules-or-in-php).

## What you are handed

```php
$context->user;       // the Authenticatable being checked, or null
$context->schemaKey;  // 'documents'
$context->schema;     // App\Warrant\DocumentSchema
$context->model;      // App\Models\Document, or null for a schema with no model
```

You are asked once per user and schema, and once per schema for no user at all, so
the provider is called with one schema key at a time rather than being asked for
everything.

## What you may return

Whatever form you have the rules in. Warrant reads each into the rules for the
schema you were asked about:

| Return | Read as |
| --- | --- |
| `string` | rule text — unscoped rules, or `for <schema>` rule sets |
| `BoundSyntax` | rule text with its bindings, as `warrant()` and `warrant_file()` make |
| `WarrantSyntax` | already-parsed rule text, read the same way |
| `RuleSetNode` | its rules |
| a rule entry — a rule, an ability block, an `@include` | that one entry |
| a rule builder | the rule it builds |
| an `array`, `Collection`, or any iterable | each element read by these same rules, in order |

The forms nest and mix freely, so a list of strings, rule sets and single rules is
fine. A rule set for another schema — a `RuleSetNode`, or a `for <schema>` header
in rule text — is left out, so a provider can return a whole file covering every
schema. A schema's own [`rules()`](/schemas/schema-policy/) is stricter: every
rule set it returns must be for that schema.

Returning nothing — `''`, `[]`, an empty collection or an empty set — is legitimate
and means there are no rules for this resource, which denies everything.

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
text inside its role's `when` block, and the rows merge into one set.

**Rules written in code.** No storage at all, and the policy lives in your
repository where it can be reviewed:

```php
public function rules(RuleProviderContext $context): string
{
    return <<<'WARRANT'
        when has_role('admin') {
            they can *
        }

        when has_role('editor') {
            if is_mine or in_my_team they can view, update
        }
    WARRANT;
}
```

**Rules from a file on disk**, which is what [`.warrant`
files](/supplying-rules/where-rules-live/) are for. One file can hold every role:

```php
public function rules(RuleProviderContext $context): BoundSyntax
{
    return warrant_file(base_path('warrant/policy'));
}
```

**Several sources merged**, which is the shape most real applications end up with.
That has [its own page](/supplying-rules/composing/).

## Branching in the rules or in PHP

`$context->user` is null when Warrant asks which users hold an ability, as
[filtering users](/checking/filtering-users/) does. One query answers for every
user at once, so the rules are resolved once, for no particular user.

Rules that differ between users can be written two ways: branch inside the rule
text, with conditions, or branch in the provider's PHP, picking different rules
for different users. For most applications the first is the better default,
because the same rules then answer every question, including which users hold an
ability. The role becomes something the rules test rather than something that
picks them:

```warrant
when has_role('editor') {
    if is_mine or in_my_team they can view, update
}
```

```php
#[GlobalCondition]
public function hasRole(GlobalConditionContext $c, string $role): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('role_user')
        ->join('roles', 'roles.id', '=', 'role_user.role_id')
        ->whereColumn('role_user.user_id', $c->userColumn('id'))
        ->where('roles.name', $role));
}
```

For a check about one user, `userColumn('id')` is that user's key. For a filter
over users, it is each user's own column. The same rule answers both questions.
See [the user's row](/schemas/conditions/#the-users-row).

Branching in PHP is still a reasonable choice, and some applications need it. If
every user can be given a permission set of their own, there may be thousands of
distinct rule sets, and folding all of them into one rule text that answers for
every user at once would be impractical. Pick the rules per user in the provider
then:

```php
public function rules(RuleProviderContext $context): iterable
{
    if ($context->user === null) {
        // No one user: the rules that hold for everyone, if there are any.
        return $this->sharedRules($context->schemaKey);
    }

    return $this->permissionSetFor($context->user, $context->schemaKey);
}
```

Checks about one user work exactly as before. What changes is filtering users:
with no user to pick for, it sees only the rules the provider returns for null.
The two approaches mix, too, with shared rules branching in the text and the
per-user parts picked in PHP.

## It is called once per request

Not once per check. Warrant memoizes the rule set it resolved per user and schema
for the life of the request, so your provider and the schema's `rules()` each run
at most once per user and schema. See [memoization and
flushing](/supplying-rules/memoization/), which also covers what to do after a
permission change.
