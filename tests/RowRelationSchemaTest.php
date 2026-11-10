<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Warrant\Facades\Warrant;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Relations\RowRelation;
use Warrant\Schema\Relations\RowRelationContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\ModelSchema;
use Warrant\Schema\Types\Classes\RowlessSchema;
use Warrant\Schema\Vocabulary;
use Warrant\Support\StringableExpression;

require_once __DIR__.'/Support/TestSupport.php';

/*
|--------------------------------------------------------------------------
| Row relations: the schema side
|--------------------------------------------------------------------------
|
| A #[RowRelation] method says how a row of its schema reaches rows of another.
| These tests cover how a schema reads, refuses and invokes one, and how a
| relation is looked up by name. Rule text cannot name a relation yet.
|
*/

function rrRelationContext(string $ownTable, string $relatedTable): RowRelationContext
{
    return new RowRelationContext(new User, DB::table($relatedTable), $ownTable, $relatedTable);
}

// -- reading relations --------------------------------------------------------

it('reads a row relation, named after its method unless it gives a key', function () {
    $schema = new RrDocSchema;

    expect(array_map(static fn ($relation): array => [$relation->key, $relation->methodName, $relation->targetSchemaClass], $schema->rowRelations()))
        ->toBe([
            ['parent_folder', 'parentFolder', RrFolderSchema::class],
            ['uploader', 'uploadedBy', RrFolderSchema::class],
        ])
        ->and($schema->rowRelation('parent_folder')?->methodName)->toBe('parentFolder')
        ->and($schema->rowRelation('uploaded_by'))->toBeNull();
});

it('holds a schema\'s row relations in its vocabulary', function () {
    $vocabulary = Vocabulary::of(new RrDocSchema);

    expect($vocabulary->rowRelationKeys())->toBe(['parent_folder', 'uploader'])
        ->and($vocabulary->rowRelation('uploader')?->targetSchemaClass)->toBe(RrFolderSchema::class)
        ->and($vocabulary->rowRelation('missing'))->toBeNull();
});

// -- invoking a relation ------------------------------------------------------

it('invokes a relation with both sides\' columns quoted, comparing as columns', function () {
    $schema = new RrDocSchema;
    $related = $schema->invokeRowRelation($schema->rowRelation('parent_folder'), rrRelationContext('rr_docs', 'rr_folders'));

    expect($related)->toBeInstanceOf(QueryBuilder::class)
        ->and($related->toSql())->toBe('select * from "rr_folders" where "rr_folders"."id" = "rr_docs"."folder_id"')
        ->and($related->getBindings())->toBe([]);
});

it('names each side of a relation by the name the query gives it', function () {
    $c = rrRelationContext('d', 'rr_folders_2');

    expect($c->ownColumn('folder_id'))->toBeInstanceOf(StringableExpression::class)
        ->and((string) $c->ownColumn('folder_id'))->toBe('"d"."folder_id"')
        ->and((string) $c->relatedColumn('id'))->toBe('"rr_folders_2"."id"');
});

it('refuses a relation that answers with anything but the query', function () {
    $schema = new RrBoolAnswerSchema;

    expect(fn () => $schema->invokeRowRelation($schema->rowRelation('folder'), rrRelationContext('rr_docs', 'rr_folders')))
        ->toThrow(InvalidArgumentException::class, 'Row relation [folder] on schema [RrBoolAnswerSchema] must return the query it constrained; it returned a [bool].');
});

// -- refusing a relation declared wrongly -------------------------------------

it('refuses a row relation on a schema with no rows', function () {
    expect(fn () => new RrRowlessSchema)
        ->toThrow(InvalidArgumentException::class, 'Method [RrRowlessSchema::folder] is a #[RowRelation], but schema [RrRowlessSchema] has no rows.');
});

it('refuses a row relation that takes more than its context', function () {
    expect(fn () => new RrExtraParameterSchema)
        ->toThrow(InvalidArgumentException::class, 'Row relation [RrExtraParameterSchema::folder] must take exactly one parameter, a ['.RowRelationContext::class.'].');
});

it('refuses a row relation whose parameter is not its context', function () {
    expect(fn () => new RrWrongContextSchema)
        ->toThrow(InvalidArgumentException::class, 'Row relation [RrWrongContextSchema::folder] must take exactly one parameter');
});

it('refuses a row relation declared twice under one key', function () {
    expect(fn () => new RrDuplicateSchema)
        ->toThrow(InvalidArgumentException::class, 'Schema [RrDuplicateSchema] declares row relation [folder] more than once.');
});

it('refuses a row relation named like a condition', function () {
    expect(fn () => new RrNamedLikeAConditionSchema)
        ->toThrow(InvalidArgumentException::class, 'Schema [RrNamedLikeAConditionSchema] declares [folder] as both a condition and a row relation');
});

it('refuses one method declared as a condition and a row relation', function () {
    expect(fn () => new RrConditionAndRelationSchema)
        ->toThrow(InvalidArgumentException::class, 'Method [RrConditionAndRelationSchema::folder] cannot be both a condition and a row relation.');
});

it('refuses one method declared as a rule template and a row relation', function () {
    expect(fn () => new RrTemplateAndRelationSchema)
        ->toThrow(InvalidArgumentException::class, 'Method [RrTemplateAndRelationSchema::folder] cannot be both a row relation and a rule template.');
});

it('refuses a row relation attribute on matchKey', function () {
    expect(fn () => new RrRelationOnMatchKeySchema)
        ->toThrow(InvalidArgumentException::class, 'declares a vocabulary attribute on matchKey()');
});

it('refuses an empty row relation key', function () {
    expect(fn () => new RowRelation(RrFolderSchema::class, ''))
        ->toThrow(InvalidArgumentException::class, 'RowRelation key cannot be empty.');
});

// -- looking a relation up by name --------------------------------------------

it('looks a relation up on the schema it starts from, with its target\'s key', function () {
    $catalog = catalogOf(['rr_docs' => new RrDocSchema, 'rr_folders' => new RrFolderSchema]);
    $found = $catalog->constructs()->rowRelation('parent_folder', 'rr_docs');

    expect($found?->definition->methodName)->toBe('parentFolder')
        ->and($found?->targetKey)->toBe('rr_folders')
        ->and($found?->schemaClass())->toBe(RrDocSchema::class)
        ->and($found?->declaredIn()->key ?? null)->toBe('rr_docs');
});

it('finds no relation the schema does not declare, or on a schema it does not know', function () {
    $catalog = catalogOf(['rr_docs' => new RrDocSchema, 'rr_folders' => new RrFolderSchema]);

    expect($catalog->constructs()->rowRelation('missing', 'rr_docs'))->toBeNull()
        ->and($catalog->constructs()->rowRelation('parent_folder', 'rr_folders'))->toBeNull()
        ->and($catalog->constructs()->rowRelation('parent_folder', 'unknown'))->toBeNull();
});

it('finds a relation whose target is not registered, with no target key', function () {
    $found = catalogOf(['rr_docs' => new RrDocSchema])->constructs()->rowRelation('parent_folder', 'rr_docs');

    expect($found?->definition->key)->toBe('parent_folder')
        ->and($found?->targetKey)->toBeNull();
});

it('gives the key a schema class is registered under', function () {
    useWarrantSchemas(['rr_docs' => RrDocSchema::class, 'rr_folders' => RrFolderSchema::class]);

    expect(Warrant::registry()->keyOf(RrFolderSchema::class))->toBe('rr_folders')
        ->and(Warrant::registry()->keyOf('\\'.RrFolderSchema::class))->toBe('rr_folders')
        ->and(Warrant::registry()->keyOf(RrRowlessSchema::class))->toBeNull();
});

// -- fixtures -----------------------------------------------------------------

class RrDoc extends Model
{
    protected $table = 'rr_docs';
}

class RrFolder extends Model
{
    protected $table = 'rr_folders';
}

class RrFolderSchema extends ModelSchema
{
    public const model = RrFolder::class;

    #[Ability]
    public const VIEW = 'view';
}

class RrDocSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowRelation(RrFolderSchema::class)]
    public function parentFolder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
    }

    #[RowRelation(RrFolderSchema::class, key: 'uploader')]
    public function uploadedBy(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('owner_id'), $c->ownColumn('uploader_id'));
    }
}

class RrBoolAnswerSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RowRelation(RrFolderSchema::class)]
    public function folder(RowRelationContext $c): bool
    {
        return true;
    }
}

class RrRowlessSchema extends RowlessSchema
{
    #[Ability]
    public const VIEW = 'view';

    #[RowRelation(RrFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery;
    }
}

class RrExtraParameterSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RowRelation(RrFolderSchema::class)]
    public function folder(RowRelationContext $c, string $type): BuilderContract
    {
        return $c->relatedQuery;
    }
}

class RrWrongContextSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RowRelation(RrFolderSchema::class)]
    public function folder(RowConditionContext $c): BuilderContract
    {
        return $c->query;
    }
}

class RrDuplicateSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RowRelation(RrFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery;
    }

    #[RowRelation(RrFolderSchema::class, key: 'folder')]
    public function containingFolder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery;
    }
}

class RrNamedLikeAConditionSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RowCondition('folder')]
    public function inAFolder(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereNotNull($c->column('folder_id'));
    }

    #[RowRelation(RrFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery;
    }
}

class RrConditionAndRelationSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RowCondition]
    #[RowRelation(RrFolderSchema::class)]
    public function folder(RowConditionContext $c): BuilderContract
    {
        return $c->query;
    }
}

class RrTemplateAndRelationSchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RuleTemplate]
    #[RowRelation(RrFolderSchema::class)]
    public function folder(): string
    {
        return 'they can view';
    }
}

class RrRelationOnMatchKeySchema extends ModelSchema
{
    public const model = RrDoc::class;

    #[RowRelation(RrFolderSchema::class)]
    public function matchKey(RowConditionContext $c, mixed $key): ?BuilderContract
    {
        return $c->query->where($c->column(), '=', $key);
    }
}
