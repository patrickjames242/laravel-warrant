---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Roles
description: Roles without a roles table, as named rule sets that merge.
sidebar:
  order: 1
---

Warrant has no roles table and no `hasRole()`. A role is a named block of rules,
and a condition says which users hold it. Every role's rules go to every check,
each inside a `when` block on its role, so a user with three roles matches three
blocks. Because [order never matters](/concepts/grants-and-denials/), merging is
safe.

## The storage

```php
Schema::create('role_rules', function (Blueprint $table) {
    $table->id();
    $table->string('role');
    $table->string('schema_key');
    $table->text('rules');
    $table->unique(['role', 'schema_key']);
});
```

Rows for a document editor:

| role | schema_key | rules |
|---|---|---|
| `viewer` | `documents` | `they can view` |
| `editor` | `documents` | `if is_mine or in_my_team they can view, update` |
| `approver` | `documents` | `if in_my_team they can approve` |
| `editor` | `folders` | `if is_member they can view` |

## The schema

```php
class DocumentSchema extends ModelSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW    = 'view';
    #[Ability] public const UPDATE  = 'update';
    #[Ability] public const APPROVE = 'approve';
    #[Ability] public const DELETE  = 'delete';

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->where($c->column('user_id'), $c->userColumn('id'));
    }

    #[RowCondition]
    public function inMyTeam(RowConditionContext $c): Builder
    {
        return $c->query->whereExists(fn ($sub) => $sub
            ->from('team_members')
            ->whereColumn('team_members.team_id', $c->column('team_id'))
            ->whereColumn('team_members.user_id', $c->userColumn('id')));
    }

    #[GlobalCondition]
    public function hasRole(GlobalConditionContext $c, string $role): Builder
    {
        return $c->query->whereExists(fn ($sub) => $sub
            ->from('role_user')
            ->whereColumn('role_user.user_id', $c->userColumn('id'))
            ->where('role_user.role', $role));
    }
}
```

`has_role` reads the user's roles in SQL, so it answers for one user and for
[filtering users](/checking/filtering-users/) alike. Put it on a
[global schema](/schemas/global-schemas/) to share it between schemas.

## The provider

```php
class RoleRuleProvider implements IRuleProvider
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

Every check on documents gets:

```warrant
when has_role('viewer') {
    they can view
}

when has_role('editor') {
    if is_mine or in_my_team they can view, update
}

when has_role('approver') {
    if in_my_team they can approve
}
```

For a user with `viewer` and `approver`, `view` compiles to a grant through
`has_role('viewer')`, and `approve` to `has_role('approver')` and the team
check.

## Roles in code instead

If your roles rarely change and belong in version control, skip the table:

```php
class RoleRuleProvider implements IRuleProvider
{
    private const RULES = [
        'viewer'   => ['documents' => 'they can view'],
        'editor'   => ['documents' => 'if is_mine or in_my_team they can view, update'],
        'approver' => ['documents' => 'if in_my_team they can approve'],
        'admin'    => ['documents' => 'they can *'],
    ];

    public function rules(RuleProviderContext $context): iterable
    {
        return collect(self::RULES)
            ->filter(fn (array $bySchema) => isset($bySchema[$context->schemaKey]))
            ->map(fn (array $bySchema, string $role) => warrant(
                "when has_role(:role) {\n{$bySchema[$context->schemaKey]}\n}",
                ['role' => $role],
            ));
    }
}
```

Or as [`.warrant` files](/supplying-rules/where-rules-live/), one per role, which
gets you editor highlighting. Each file says which users it is for:

```warrant
# warrant/roles/editor.warrant

for documents {
    when has_role('editor') {
        if is_mine or in_my_team they can view, update
        if is_locked they cannot update because 'This document is locked.'
    }
}

for folders {
    when has_role('editor') {
        if is_member they can view
    }
}
```

## Revoking one thing

A `cannot` from any role beats a `can` from every other, so a restricted role is
just another set:

```warrant
# role: contractor, schema: documents
they cannot delete because 'Contractors cannot delete documents.'
```

Give a user `editor` and `contractor` and they can update but never delete, whatever
`editor` says.

That is also the limit. A role cannot carve an exception out of another role's
denial. If `editor` says `if is_locked they cannot update`, no role restores update
on a locked row. Anticipate the exception in the denial:

```warrant
if is_locked and not is_admin they cannot update
```

## Testing a role

```php
it('gives approvers approve on their team only', function () {
    $user = userWithRoles('approver', teamIds: ['team-a']);

    $ours   = Document::factory()->create(['team_id' => 'team-a']);
    $theirs = Document::factory()->create(['team_id' => 'team-b']);

    expect(Warrant::can('approve', $ours, user: $user))->toBeTrue();
    expect(Warrant::can('approve', $theirs, user: $user))->toBeFalse();
    expect(Warrant::can('delete', $ours, user: $user))->toBeFalse();
});
```

## What this buys over a permissions table

The grant is not a flag, it is a predicate, so `editor` means "their own and their
team's" rather than a row per document. And the same definition filters the index
page:

```php
Document::query()->userHasAbility('update')->paginate();
```

## Related

[Composing from several sources](/supplying-rules/composing/) for returning several sources at once.
[Teams](/recipes/teams/) for the membership half.
[Super admins](/recipes/super-admin/) for the role that grants everything.
