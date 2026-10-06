---
banner:
  content: 'Laravel Warrant is in <strong>beta</strong> and still being tested — expect API changes between releases. <a href="https://github.com/patrickjames242/laravel-warrant/issues">Report an issue</a>.'
title: 1. Add a schema
description: Attach a schema to a model, declare one ability, and write one condition.
sidebar:
  order: 1
---

We are going to gate a `Document` model so that people can read and edit their own
documents. Six short steps, ending with a boolean check and a filtered list that
both come from the same rule.

Start with a schema. A schema is the list of words a rule is allowed to use about
one model: the abilities that exist, and the conditions a rule may test. It
decides nothing by itself.

```php
namespace App\Warrant;

use App\Models\Document;
use Illuminate\Contracts\Database\Query\Builder;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

class DocumentSchema extends WarrantSchema
{
    public const model = Document::class;

    #[Ability] public const VIEW   = 'view';
    #[Ability] public const UPDATE = 'update';

    #[RowCondition]
    public function isMine(RowConditionContext $c): Builder
    {
        return $c->query->where($c->row('user_id'), $c->user->getAuthIdentifier());
    }
}
```

Three things are worth reading closely.

`const model` binds the schema to a model. The model has to name the schema back,
which we do in step 4.

`#[Ability]` declares a verb. The constant's **value** is what a rule writes, so
`VIEW = 'view'` means a rule says `they can view`. The constant's name is ignored.
Warrant ships no fixed list, so if your domain says `approve`, `submit`, and
`unlock`, declare those.

`#[RowCondition]` declares a test a rule may put after `if`. The method name
snake-cases into the rule name, so `isMine` is written `is_mine`. The body adds a
`where` to the query it was handed and returns it. `$c->row('user_id')` gives back
the qualified column, `documents.user_id`, which matters later when the same
condition runs under an alias.

## The model side

The model points back at the schema with the `HasWarrantSchema` trait:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Warrant\HasWarrantSchema;

class Document extends Model
{
    use HasWarrantSchema;

    public static function warrantSchema(): string
    {
        return \App\Warrant\DocumentSchema::class;
    }
}
```

If the two disagree, Warrant says so the first time it resolves the pair:

```text
Model [App\Models\Document] names schema [App\Warrant\FolderSchema], but that
schema names model [App\Models\Folder]; a schema and its model must name each other.
```

## Register it

A schema is known by the key it is registered under in `config/warrant.php`. That
key is what rules and middleware write:

```php
'schemas' => [
    'documents' => App\Warrant\DocumentSchema::class,
],
```

Next: [write a rule](/first-rule/rule/).
