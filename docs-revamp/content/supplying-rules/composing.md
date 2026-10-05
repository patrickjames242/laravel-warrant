---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Composing from several sources
description: merge and mergeWith, and the role-plus-team-plus-override pattern they exist for.
sidebar:
  order: 3
---

Real policies come from more than one place. A user has a role. They belong to
teams, each with its own grants. Someone granted them access to one specific
record. An administrator revoked one thing.

Warrant has no roles table and no notion of any of those. What it has is merging,
and because [rule order never matters](/concepts/grants-and-denials/), merging is
safe in any order.

```php
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;

$a->mergeWith($b);              // one into another
RuleSetNode::merge($a, $b, $c); // several, in argument order
```

Both return a new set with the entries concatenated. The schema keys have to match:

```text
Cannot merge rule sets for different schemas: [documents] and [folders].
```

## The shape most applications end up with

```php
class CompositeRuleResolver implements RuleResolver
{
    public function __construct(private RoleRules $roles, private TeamRules $teams) {}

    public function resolve(RuleResolutionContext $context): RuleSetNode
    {
        $key = $context->schemaKey;

        $sets = [
            // 1. Baseline for everyone.
            Warrant::parse("for {$key} { they can view }")->ruleSet(),

            // 2. Whatever the user's role grants.
            $this->roles->forRole($context->user->role, $key),
        ];

        // 3. One set per team the user belongs to.
        foreach ($context->user->teams as $team) {
            $sets[] = $this->teams->forTeam($team, $key);
        }

        // 4. Per-user overrides, including revocations.
        $sets[] = $this->overridesFor($context->user, $key);

        return RuleSetNode::merge(...$sets);
    }
}
```

Each source is written and tested on its own, and none of them has to know the
others exist.

## What merging can and cannot express

**Grants accumulate.** A role granting `view` and a team granting `update` gives
the user both. That is an `OR`, and it is the whole reason adding a source is
safe.

**Denials win, absolutely.** A `cannot` from any source beats a `can` from every
other source:

```php
$sets[] = Warrant::parse("for {$key} { if is_suspended they cannot * }")->ruleSet();
```

**A later source cannot carve an exception out of an earlier denial.** There is no
order, so there is no later. If role rules say `if is_locked they cannot update`,
no team's rules can restore `update` on a locked row. The exception has to be
written into the denial itself:

```warrant
if is_locked and not is_admin they cannot update
```

That is the [ceiling of the flat model](/concepts/what-rules-cannot-do/), and it is
worth knowing before you design a permission hierarchy around merging. Locally
overridable denials are [on the roadmap](/roadmap/planned/).

## Roles, expressed as merging

A role is a named rule set. Store one per role per schema:

```php
class RoleRules
{
    public function forRole(string $role, string $schemaKey): RuleSetNode
    {
        $text = DB::table('role_rules')
            ->where('role', $role)
            ->where('schema_key', $schemaKey)
            ->value('rules');

        return $text === null
            ? RuleSetNode::fromRules($schemaKey)
            : Warrant::parse($text)->scopedTo($schemaKey);
    }
}
```

```warrant
# role: editor, schema: documents
if is_mine or in_my_team they can view, update
if is_locked they cannot update because 'This document is locked.'
```

A user with several roles merges several sets. Nothing else changes.
[Roles](/recipes/roles/) works this through as a complete recipe.

## Merging files

When each source is a `.warrant` file covering several schemas, parse each file,
pull out the schema you were asked for, and merge what is left:

```php
public function resolve(RuleResolutionContext $context): RuleSetNode
{
    $files = array_map(
        fn (string $role) => Warrant::parseFile(base_path("warrant/{$role}.warrant")),
        $context->user->roleNames(),
    );

    $sets = array_values(array_filter(array_map(
        fn (WarrantSyntax $file) => $file->forSchema($context->schemaKey),
        $files,
    )));

    return $sets === []
        ? RuleSetNode::fromRules($context->schemaKey)
        : RuleSetNode::merge(...$sets);
}
```

Within one file, `forSchema()` already folds every block for that schema into one
set, in source order.

## Mixing text and builders

The two authoring front ends produce the same AST, so a merged set can come from
both. Stored text for the parts an administrator edits, builders for the parts that
depend on runtime values:

```php
$stored = Warrant::parse($textFromDatabase)->scopedTo($key);

$dynamic = RuleSetNode::build($key, function ($rule) use ($teamIds) {
    $rule()->if(fn ($c) => array_map(fn ($id) => $c->orIf('in_team', [$id]), $teamIds))
        ->theyCan('view');
});

return $stored->mergeWith($dynamic);
```

## What not to merge in

Anything that must hold whatever the resolver does belongs in
[`implicitRules()`](/schemas/schema-policy/) on the schema instead. A suspension
lockout that lives in the resolver is one refactor away from being dropped.
