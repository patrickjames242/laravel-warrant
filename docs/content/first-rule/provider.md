---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 3. Supply the rule
description: Write the provider that hands Warrant the current user's rules.
sidebar:
  order: 3
---

Warrant never invents a rule. It asks your rule provider for them, once per user
and schema, at request time. A provider is optional — a schema can return its own
rules — but it is the usual home for rules that live outside your code.

This one reads the file from step two:

```php
namespace App\Warrant;

use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;

class FileRuleProvider implements IRuleProvider
{
    public function rules(RuleProviderContext $context): BoundSyntax
    {
        return warrant_file(base_path('warrant/documents'));
    }
}
```

The provider is asked once for each schema a check touches. `warrant_file()` reads
the file, and Warrant keeps the `for documents` block when the schema is
`documents` and leaves out every block for another schema. The context tells you
what is being asked about:

```php
$context->schemaKey;  // 'documents'
$context->schema;     // App\Warrant\DocumentSchema
$context->model;      // App\Models\Document, or null for a schema with no model
$context->user;       // the Authenticatable being checked, or null
```

Every user gets the same rules here, which suits most applications. Differences
between users, such as roles, can live inside the rules as conditions rather than
in which rules the provider picks:

```warrant
for documents {
    when has_role('editor') {
        if is_mine or in_my_team they can view, update
    }
}
```

Rules that are the same for everyone can also be asked
[which users hold an ability](/checking/filtering-users/), where there is no one
user to pick rules for. A provider may still pick rules per user when it has to. Rules stored per role in the database keep the same shape,
each role's rules wrapped in its condition:

```php
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
```

A collection of rule text is a valid return. Each row is read as rule text for the
schema you were asked about, and the rows merge into one set. `has_role` is a
condition you write; see [branching in the rules or in
PHP](/supplying-rules/provider/#branching-in-the-rules-or-in-php).

## Wire it up

```php
// config/warrant.php
return [
    'rule_provider' => App\Warrant\FileRuleProvider::class,

    'schemas' => [
        'documents' => App\Warrant\DocumentSchema::class,
    ],

    'register_gate' => true,
];
```

Warrant builds the provider through the container, so constructor dependencies are
injected.

Leave `rule_provider` unset and only the schema's own `rules()` apply, which for
this schema is none, so every check denies.

Next: [ask a question](/first-rule/check/).
