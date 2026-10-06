---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Hierarchies
description: Access that flows down a parent chain, and the two ways to express it.
sidebar:
  order: 4
---

Folders inside folders, org units inside org units, projects inside programmes.
Access granted at one level flows down.

There are two shapes, and the choice is about depth.

## One level down: delegate with a hop

A document lives in a folder, and the folder's rules decide:

```warrant
for folders {
    if is_owner or in_my_team they can view, edit
}

for documents {
    if can(view for folders(@column folder_id)) they can view
    if can(edit for folders(@column folder_id)) they can update, delete
}
```

```sql
select * from "documents"
where (exists (
    select * from "folders"
    where "folders"."id" = "documents"."folder_id"
      and ("folders"."owner_id" = 7 or exists (…team…))
))
```

Note that the abilities need not line up. `edit` on a folder grants `update` and
`delete` on its documents, which is a policy decision expressed in one line.

## Many levels: a closure table

A folder inside a folder inside a folder cannot be expressed by a rule that hops
into its own ability, because the compiler refuses a repeated `(schema, ability)`
frame:

```text
Cross-schema can(...) cycle detected: folders:view → folders:view.
```

The answer is to make ancestry a fact the database can answer in one step. A
closure table holds every ancestor-descendant pair:

```php
Schema::create('folder_paths', function (Blueprint $table) {
    $table->foreignId('ancestor_id')->constrained('folders');
    $table->foreignId('descendant_id')->constrained('folders');
    $table->unsignedInteger('depth');
    $table->primary(['ancestor_id', 'descendant_id']);
});
```

Every folder has a row to itself at depth 0, so a grant on the folder itself needs
no special case.

```php
#[RowCondition]
public function grantedAtOrAbove(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('folder_paths')
        ->join('folder_grants', 'folder_grants.folder_id', '=', 'folder_paths.ancestor_id')
        ->whereColumn('folder_paths.descendant_id', $c->row('id'))
        ->where('folder_grants.user_id', $c->user->getAuthIdentifier()));
}
```

```warrant
for folders {
    if granted_at_or_above they can view, edit
}
```

One subquery, whatever the depth. The `join` inside a `whereExists` is fine: the
restriction is on the *outer* query's shape, and a subquery may look however it
likes.

Index `folder_paths` on `descendant_id` and `folder_grants` on
`(user_id, folder_id)`.

## Materialized paths

If you already store a path string, the condition is even cheaper, at the cost of a
prefix scan:

```php
#[RowCondition]
public function underMyGrantedFolder(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('folder_grants')
        ->join('folders as g', 'g.id', '=', 'folder_grants.folder_id')
        ->whereColumn('g.path', '<=', $c->row('path'))
        ->whereRaw("? like g.path || '%'", [/* … */])
        ->where('folder_grants.user_id', $c->user->getAuthIdentifier()));
}
```

Workable, and fiddlier than the closure table. Prefer the closure table unless the
path column is already load-bearing elsewhere.

## Bounded depth with a template

When the hierarchy has a known small maximum, a
[recursive rule template](/rules/templates/) will express it, bounded in PHP:

```php
#[RuleTemplate]
public function inheritedFrom(int $levels): string|WarrantSyntax
{
    if ($levels <= 0) {
        return 'they cannot';
    }

    return Warrant::parse(
        'if granted_directly they can
         @include inherited_from(:next)',
        ['next' => $levels - 1],
    );
}
```

Worth knowing about; rarely the right answer. The SQL grows a nesting level per
step, and the compiler's depth cap of 64 is a hard ceiling.

## Denials flow down too

A denial at an ancestor stops everything beneath it, with no extra machinery:

```warrant
for folders {
    if granted_at_or_above they can view
    if archived_at_or_above they cannot view because 'An enclosing folder is archived.'
}
```

```php
#[RowCondition]
public function archivedAtOrAbove(RowConditionContext $c): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('folder_paths')
        ->join('folders as a', 'a.id', '=', 'folder_paths.ancestor_id')
        ->whereColumn('folder_paths.descendant_id', $c->row('id'))
        ->whereNotNull('a.archived_at'));
}
```

## Testing the depth

```php
it('grants access through two levels of nesting', function () {
    $root  = Folder::factory()->create();
    $mid   = Folder::factory()->for($root, 'parent')->create();
    $leaf  = Folder::factory()->for($mid, 'parent')->create();

    FolderGrant::create(['folder_id' => $root->id, 'user_id' => $user->id]);

    expect(Warrant::can('view', $leaf, user: $user))->toBeTrue();

    $sibling = Folder::factory()->create();
    expect(Warrant::can('view', $sibling, user: $user))->toBeFalse();
});
```

## Related

[Longer chains](/rules/cross-schema/) for the cycle and depth rules.
[Teams](/recipes/teams/) when the grouping is flat.
