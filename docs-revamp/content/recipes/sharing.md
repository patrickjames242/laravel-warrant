---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Sharing a single record
description: Per-row grants as data, so access can be handed out at runtime.
sidebar:
  order: 8
---

Roles and teams describe classes of rows. Sharing describes one: this person, this
document, these abilities.

The grant lives in a table, and a condition reads it. No rule changes when someone
clicks Share.

## The table

```php
Schema::create('document_shares', function (Blueprint $table) {
    $table->id();
    $table->foreignId('document_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('level');                    // viewer | commenter | editor
    $table->timestamp('expires_at')->nullable();
    $table->unique(['document_id', 'user_id']);
});
```

Index on `(user_id, document_id)` too, since the generated subquery correlates on
the document and filters on the user.

## The condition

One parameterized condition covers every level:

```php
#[RowCondition]
public function sharedWithMeAs(RowConditionContext $c, string ...$levels): Builder
{
    return $c->query->whereExists(fn ($sub) => $sub
        ->from('document_shares')
        ->whereColumn('document_shares.document_id', $c->row('id'))
        ->where('document_shares.user_id', $c->user->getAuthIdentifier())
        ->whereIn('document_shares.level', $levels)
        ->where(fn ($q) => $q
            ->whereNull('document_shares.expires_at')
            ->orWhere('document_shares.expires_at', '>', $c->context['now'] ?? now())));
}
```

## The rules

```warrant
for documents {
    if is_mine they can view, update, delete, share

    if shared_with_me_as('viewer', 'commenter', 'editor') they can view
    if shared_with_me_as('commenter', 'editor')           they can comment
    if shared_with_me_as('editor')                        they can update
}
```

Sharing composes with everything else. A user who owns one document, is in a team
that owns another, and was sent a link to a third sees all three in one query:

```php
Document::query()->userHasAbility('view')->paginate();
```

```sql
select * from "documents"
where (
    "documents"."user_id" = 7
    or exists (select * from "team_members" where …)
    or exists (
        select * from "document_shares"
        where "document_shares"."document_id" = "documents"."id"
          and "document_shares"."user_id" = 7
          and "document_shares"."level" in ('viewer', 'commenter', 'editor')
          and ("document_shares"."expires_at" is null
               or "document_shares"."expires_at" > '2026-09-22 10:00:00')
    )
)
```

## Sharing the act of sharing

Who may hand out access is itself an ability, which keeps the controller honest:

```php
public function store(Request $request, Document $document)
{
    Warrant::authorize('share', $document);

    $document->shares()->updateOrCreate(
        ['user_id' => $request->integer('user_id')],
        ['level' => $request->string('level')],
    );

    Warrant::flush(User::find($request->integer('user_id')));

    return back();
}
```

The flush matters if the recipient is acting in the same request, which they are
not usually. It is cheap insurance.

## Sharing with a team rather than a person

Widen the condition rather than adding a second one:

```php
#[RowCondition]
public function sharedWithMeAs(RowConditionContext $c, string ...$levels): Builder
{
    $userId  = $c->user->getAuthIdentifier();
    $teamIds = $c->user->teamIds();

    return $c->query->whereExists(fn ($sub) => $sub
        ->from('document_shares')
        ->whereColumn('document_shares.document_id', $c->row('id'))
        ->whereIn('document_shares.level', $levels)
        ->where(fn ($q) => $q
            ->where('document_shares.user_id', $userId)
            ->orWhereIn('document_shares.team_id', $teamIds)));
}
```

## Link sharing

A public link is not about the user at all. Put the token in context:

```php
#[RowCondition]
public function sharedByLink(RowConditionContext $c): Builder
{
    $token = $c->context['share_token'] ?? null;

    if ($token === null) {
        return $c->query->whereRaw('1 = 0');
    }

    return $c->query->whereExists(fn ($sub) => $sub
        ->from('share_links')
        ->whereColumn('share_links.document_id', $c->row('id'))
        ->where('share_links.token', $token)
        ->whereNull('share_links.revoked_at'));
}
```

```warrant
if shared_by_link they can view
```

```php
Warrant::can('view', $document, ['share_token' => $request->query('t')]);
```

Note the explicit `1 = 0` rather than returning the query untouched, which would
throw, and rather than returning `null`, which answers unknown. Here absent
genuinely means no, not unanswerable: there is no token, so the link grant does not
apply.

## Revoking

Deleting the share row is enough, since the condition is a live query. If you want
a revocation that outranks every other grant, that is
[a `cannot`](/recipes/suspension/), not a deleted row.

## Testing

```php
it('grants exactly what the share level says', function () {
    $doc = Document::factory()->create();

    DocumentShare::create([
        'document_id' => $doc->id, 'user_id' => $user->id, 'level' => 'commenter',
    ]);

    expect(Warrant::abilities($doc, user: $user))->toBe(['view', 'comment']);
});

it('stops honouring an expired share', function () {
    DocumentShare::create([
        'document_id' => $doc->id,
        'user_id' => $user->id,
        'level' => 'editor',
        'expires_at' => now()->subDay(),
    ]);

    expect(Warrant::can('view', $doc, user: $user))->toBeFalse();
});
```
