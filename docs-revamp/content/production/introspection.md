---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Introspecting the registry
description: Seeing what the system believes, on a running application.
sidebar:
  order: 5
---

When a support ticket says "this user cannot see their documents", three questions
answer it, and all three are available at runtime.

## What rules is this user actually under?

```php
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

$guard = Warrant::forSchema(Document::class, $user);
$set = $guard->resolvedRuleSet();

$set->schemaKey;
$set->entries;                    // rules, ability blocks and @includes, as written
$guard->expandedRuleSet()->rules; // WarrantRuleNode[]: blocks, includes and derived conditions expanded

(new WarrantSyntax([$set]))->toSyntax();   // rendered back to the language
```

`toSyntax()` is the one to reach for. Writing back to text lives on
`WarrantSyntax`, the root of a parse, so wrap the rule set in one to render it. It includes the schema's
[implicit rules](/schemas/schema-policy/), so it shows what actually applies rather
than what your resolver returned:

```warrant
for documents {
    if is_suspended they cannot *

    if is_mine or in_my_team they can view

    if is_locked they cannot update because 'This document is locked.'
}
```

Nine times out of ten the answer is visible in that output.

A rule with a value that has no inline form throws from `toSyntax()`. Use
`toBoundSyntax()` there, which always works:

```php
$bound = (new WarrantSyntax([$set]))->toBoundSyntax();

$bound->syntax;
$bound->bindings;
```

## What can they do, without touching rows?

```php
Warrant::possibleAbilities(Document::class, $user);     // could ever
Warrant::guaranteedAbilities(Document::class, $user);   // whatever the row
Warrant::impossibleAbilities(Document::class, $user);   // never
```

All three run no SQL. `impossibleAbilities` returning the ability in question ends
the investigation immediately: no row could have helped.

## What does the system know about?

```php
Warrant::registry()->registeredSchemas();

Warrant::registry()->resolveSchemaKeyOrFail(Document::class);    // 'documents'
Warrant::registry()->resolveSchemaClassOrFail('documents');      // the class
Warrant::registry()->resolveModelOrFail('documents');            // the model
Warrant::registry()->resolveSchemaClassOrNull('documnets');      // null
```

That last one is worth knowing, because a typo'd key in a stored rule surfaces as
an unresolvable reference rather than as anything about permissions.

## What vocabulary does a schema have?

```php
DocumentSchema::abilityNames();
DocumentSchema::conditionKeys();
DocumentSchema::rowConditionKeys();
DocumentSchema::globalConditionKeys();
DocumentSchema::requiredContextKeys();
DocumentSchema::ruleTemplateKeys();
DocumentSchema::hasRows();
DocumentSchema::hasRowKey();
```

The row and global split is the useful one when diagnosing a no-row check, since
only global and unconditional rules can grant there.

## A support command

Worth having before you need it:

```php
class WarrantExplain extends Command
{
    protected $signature = 'warrant:explain {user} {schema} {ability?}';

    public function handle(): void
    {
        $user = User::findOrFail($this->argument('user'));
        $schema = $this->argument('schema');

        $guard = Warrant::forSchema(
            Warrant::registry()->resolveSchemaClassOrFail($schema),
            $user,
        );

        $this->line('Rules in effect:');
        $this->line((new WarrantSyntax([$guard->resolvedRuleSet()]))->toSyntax());
        $this->newLine();

        $this->table(['Ability', 'Reachability'], collect(
            Warrant::registry()->resolveSchemaClassOrFail($schema)::abilityNames()
        )->map(fn ($a) => [$a, $guard->reachabilityOf($a)->name]));

        if ($ability = $this->argument('ability')) {
            $this->newLine();
            $this->line('Filter SQL for '.$ability.':');
            $this->line($guard->filterQuery($guard->query(), $ability)->toRawSql());
        }
    }
}
```

```bash
php artisan warrant:explain 42 documents view
```

## A debug endpoint

The same thing behind an ability, for staff:

```php
Route::get('/debug/warrant/{user}/{schema}', function (User $user, string $schema) {
    Warrant::authorize('debug', 'settings');

    $guard = Warrant::forSchema(Warrant::registry()->resolveSchemaClassOrFail($schema), $user);

    return [
        'rules'      => (new WarrantSyntax([$guard->resolvedRuleSet()]))->toSyntax(),
        'possible'   => $guard->possibleAbilities(),
        'guaranteed' => $guard->guaranteedAbilities(),
    ];
});
```

Gate it on a real ability rather than an environment check. A rule set is sensitive:
it describes what someone may do and often names the shape of your data.

## Logging the query

```php
DB::listen(function ($query) {
    if (str_contains($query->sql, 'documents')) {
        logger()->debug('warrant', ['sql' => $query->sql, 'bindings' => $query->bindings]);
    }
});
```

Look for the three constants. `1 = 1` means the rules granted without reference to
a row, `1 = 0` means they denied, and `null` means they could not say, which is
almost always a missing context key. See
[where unknown goes in SQL](/sql/unknown/).
