---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: Frontend permission maps
description: Ship what the user may do to the client, without a request per row.
sidebar:
  order: 10
---

A client needs two things: which controls to render at all, and which to render per
row. Warrant answers both without an endpoint per question.

## Per row, in the list response

```php
public function index()
{
    $documents = Document::query()
        ->userHasAbility('view')
        ->selectUserAbilities(onlyAbilities: ['update', 'delete', 'share'])
        ->latest()
        ->paginate();

    return DocumentResource::collection($documents);
}
```

```php
class DocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'    => $this->id,
            'title' => $this->title,
            'can'   => $this->abilities,   // ['update', 'share']
        ];
    }
}
```

```json
{
  "data": [
    { "id": 1, "title": "Q3 plan", "can": ["update", "share"] },
    { "id": 2, "title": "Payroll", "can": [] }
  ]
}
```

```jsx
{documents.map(doc => (
  <Row key={doc.id}>
    <span>{doc.title}</span>
    {doc.can.includes('update') && <EditButton id={doc.id}/>}
    {doc.can.includes('delete') && <DeleteButton id={doc.id}/>}
  </Row>
))}
```

Narrowing with `onlyAbilities` is worth doing. The attached subquery grows one
branch per ability, so asking for three rather than twelve is a real saving on a
long list.

## For a single record

```php
public function show(Document $document)
{
    Warrant::authorize('view', $document);

    return [
        'document' => $document,
        'can'      => Warrant::abilities($document),
    ];
}
```

## Whole-screen decisions, without touching rows

Whether to render the Documents tab at all is a reachability question, so it costs
no query:

```php
public function bootstrap()
{
    return [
        'nav' => [
            'documents'  => Warrant::couldEverHave(Document::class, 'view'),
            'timesheets' => Warrant::couldEverHave(Timesheet::class, 'view'),
            'settings'   => Warrant::couldEverHave('settings', 'manage'),
        ],
        'documents' => [
            'canCreate'      => Warrant::can('create', Document::class),
            'mightEditSome'  => Warrant::couldEverHave(Document::class, 'update'),
            'editsAlwaysOk'  => Warrant::alwaysHas(Document::class, 'update'),
        ],
    ];
}
```

`mightEditSome` decides whether the Edit column exists. `editsAlwaysOk` decides
whether you can skip the per-row check and render every button enabled. See
[reachability](/concepts/reachability/).

## Inertia

Share it once and every page has it:

```php
class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'can' => fn () => $request->user() === null ? [] : [
                'documents' => Warrant::possibleAbilities(Document::class),
                'settings'  => Warrant::abilities('settings'),
            ],
        ]);
    }
}
```

The closure matters: it defers the work to pages that actually read it.

## Blade

```blade
@can('update', $document)
    <a href="{{ route('documents.edit', $document) }}">Edit</a>
@endcan
```

In a loop, prefer the ability column. A `@can` inside a `@foreach` is one predicate
evaluation per row, and while the rule set is resolved once, the query is not:

```blade
@foreach ($documents as $document)
    @if (in_array('update', $document->abilities, true))
        <a href="{{ route('documents.edit', $document) }}">Edit</a>
    @endif
@endforeach
```

## Never trust it

The payload is for rendering. Every write still authorizes on the server:

```php
public function update(Request $request, Document $document)
{
    Warrant::authorize('update', $document);

    $document->update($request->validated());
}
```

A client that hides the button is a nicety. A server that rejects the request is
the permission.

## Keeping it fresh

The map is a snapshot. When a permission changes mid-session, the client is stale
until it refetches. Two habits help: return the updated `can` array in the response
to any mutation that changes it, and treat a 403 on a supposedly permitted action
as a signal to refetch rather than as a bug:

```js
if (response.status === 403) {
  await refetchPermissions();
  showMessage(response.data.message);   // Warrant's own denial message
}
```

That message is the one attached to the rule that did the forbidding. See
[denial messages](/rules/denial-messages/).
