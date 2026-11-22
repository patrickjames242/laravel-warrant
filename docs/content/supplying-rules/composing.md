---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Composing from several sources
description: Returning rules from several sources as one list, and the role-plus-team-plus-override pattern it exists for.
sidebar:
  order: 3
---

Real policies come from more than one place. Users have roles. They belong to
teams, each with its own grants. Someone granted one of them access to one specific
record. An administrator revoked one thing.

Warrant has no roles table and no notion of any of those. What it has is lists:
`rules()` may return a list of rule text, rule sets, rules and builders, in any
mix, and Warrant joins them. Because [rule order never matters](/concepts/grants-and-denials/),
joining is safe in any order.

```php
return [$roleRules, $teamRules, 'if is_suspended they cannot *'];
```

The rule provider may return rules for any schema. Only the rule sets for the
schema it was asked about, and the rules with no `for` header, apply to it; the
rest are left out. A schema's own [`rules()`](/schemas/schema-policy/) must hold
only rules for that schema.

## The shape most applications end up with

```php
class CompositeRuleProvider implements IRuleProvider
{
    public function __construct(private RoleRules $roles, private TeamRules $teams) {}

    public function rules(RuleProviderContext $context): array
    {
        $key = $context->schemaKey;

        return [
            // 1. Baseline for everyone.
            'they can view',

            // 2. What each role grants, inside a when block on that role.
            ...$this->roles->forSchema($key),

            // 3. What each team grants, inside a when block on membership.
            ...$this->teams->forSchema($key),

            // 4. Grants on one record, read from a table by a condition.
            "if shared_with_me_as('editor') they can view, update",
        ];
    }
}
```

Each source is written and tested on its own, and none of them has to know the
others exist. None of them looks at the user either: which users a rule matches is
the rule's own condition, so the same list answers for every user, including when
[users are filtered](/checking/filtering-users/). A source that does have to pick
rules per user can sit in the same list; see [branching in the rules or in
PHP](/supplying-rules/provider/#branching-in-the-rules-or-in-php). [Sharing](/recipes/sharing/)
covers the grants table behind `shared_with_me_as`.

## What joining can and cannot express

**Grants accumulate.** A role granting `view` and a team granting `update` gives
the user both. That is an `OR`, and it is the whole reason adding a source is
safe.

**Denials win, absolutely.** A `cannot` from any source beats a `can` from every
other source:

```php
$sources[] = 'if is_suspended they cannot *';
```

**A later source cannot carve an exception out of an earlier denial.** There is no
order, so there is no later. If role rules say `if is_locked they cannot update`,
no team's rules can restore `update` on a locked row. The exception has to be
written into the denial itself:

```warrant
if is_locked and not is_admin they cannot update
```

That is the [ceiling of the flat model](/concepts/what-rules-cannot-do/), and it is
worth knowing before you design a permission hierarchy around joining sources.
Locally overridable denials are [on the roadmap](/roadmap/planned/).

## Roles, expressed as sources

A role is a block of rule text. Store one per role per schema, and return every
role's, each inside a `when` block on that role:

```php
class RoleRules
{
    /** @return list<BoundSyntax> */
    public function forSchema(string $schemaKey): array
    {
        return DB::table('role_rules')
            ->where('schema_key', $schemaKey)
            ->get()
            ->map(fn ($row) => warrant(
                "when has_role(:role) {\n{$row->rules}\n}",
                ['role' => $row->role],
            ))
            ->all();
    }
}
```

```warrant
# role: editor, schema: documents
if is_mine or in_my_team they can view, update
if is_locked they cannot update because 'This document is locked.'
```

The text has no `for` header, so it is about the schema the provider was asked
for. Inside its `when` block, every rule in it applies only to editors, the
denial included. A user with several roles matches several blocks. Nothing else
changes. [Roles](/recipes/roles/) works this through as a complete recipe.

## Joining files

When each source is a `.warrant` file covering several schemas, return the files.
Each file's blocks for the schema asked about apply, in source order, and its
blocks for every other schema are left out. A file per role says which users it is
for with a `when` block inside each schema's block:

```php
public function rules(RuleProviderContext $context): array
{
    return array_map(
        fn (string $path) => warrant_file($path),
        glob(base_path('warrant/roles/*.warrant')),
    );
}
```

## Mixing text and builders

The two authoring front ends produce the same AST, so one list can hold both.
Stored text for the parts an administrator edits, builders for the parts that
depend on runtime values that are not about the user:

```php
$dynamic = Build::ruleSet($key, function ($rule) use ($publicTeamIds) {
    $rule()->if(fn ($c) => array_map(fn ($id) => $c->orIf('in_team', [$id]), $publicTeamIds))
        ->theyCan('view');
});

return [$textFromDatabase, $dynamic];
```

## What not to put in the list

Anything that must hold whatever the provider does belongs in
[`rules()`](/schemas/schema-policy/) on the schema instead. A suspension
lockout that lives in the provider is one refactor away from being dropped.
