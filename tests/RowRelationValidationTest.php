<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Relations\RowRelation;
use Warrant\Schema\Relations\RowRelationContext;
use Warrant\Schema\Types\Classes\ModelSchema;
use Warrant\Schema\Types\Classes\RowlessSchema;

/*
|--------------------------------------------------------------------------
| Row relations: validation and expansion
|--------------------------------------------------------------------------
|
| `for its <relation>` names a #[RowRelation] of the schema the rule is for.
| Validation finds the relation, follows it to its target, and checks the
| ability or predicate against that target, as it does for a schema named by
| key. Expansion reads a check(...) predicate against the same target.
|
*/

beforeEach(function () {
    useWarrantSchemas([
        'rv_docs' => RvDocSchema::class,
        'rv_folders' => RvFolderSchema::class,
        'rv_settings' => RvSettingsSchema::class,
    ]);
});

function validateDocSyntax(string $syntax): void
{
    validateRegistered(WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('rv_docs'));
}

/**
 * The diagnostics for $source written for rv_docs, as [code, the text each covers].
 *
 * @return list<array{0: DiagnosticCode, 1: string}>
 */
function docDiagnostics(string $source): array
{
    return array_map(
        static fn (Diagnostic $diagnostic): array => [
            $diagnostic->code,
            substr($source, $diagnostic->offset, $diagnostic->endOffset - $diagnostic->offset),
        ],
        analyzedDiagnostics($source, Warrant::registry(), 'rv_docs'),
    );
}

// -- what validates -----------------------------------------------------------

it('accepts a can(...) over a relation, with an alias and a with map', function (string $syntax) {
    validateDocSyntax($syntax);

    expect(true)->toBeTrue();
})->with([
    'bare' => 'if can(view for its folder) they can view',
    'aliased' => 'if can(view for its folder as f) they can view',
    'with a with map' => 'if can(view for its folder with org = @context org) they can view',
]);

it('accepts a check(...) over a relation, reading its predicate against the relation\'s target', function () {
    validateDocSyntax('if check(is_locked for its folder) they cannot view');

    expect(true)->toBeTrue();
});

it('lets a check(...) predicate name the related rows by the relation, or by an alias', function (string $syntax) {
    validateDocSyntax($syntax);

    expect(true)->toBeTrue();
})->with([
    'by the relation' => 'if check(owner_is(@column folder.owner) for its folder) they can view',
    'by an alias' => 'if check(owner_is(@column f.owner) for its folder as f) they can view',
    'the rule\'s own row beside it' => 'if check(owner_is(@column rv_docs.owner) for its folder) they can view',
]);

// -- what is refused ----------------------------------------------------------

it('reports a relation the schema does not declare, over its name', function () {
    expect(docDiagnostics('if can(view for its nope) they can view'))
        ->toBe([[DiagnosticCode::UnknownRelation, 'nope']]);

    expect(fn () => validateDocSyntax('if can(view for its nope) they can view'))
        ->toThrow(InvalidArgumentException::class, 'Schema [rv_docs] declares no row relation [nope].');
});

it('reports a relation whose schema is not registered', function () {
    expect(docDiagnostics('if can(view for its stray) they can view'))
        ->toBe([[DiagnosticCode::RelationTargetUnregistered, 'stray']]);

    expect(fn () => validateDocSyntax('if can(view for its stray) they can view'))
        ->toThrow(InvalidArgumentException::class, 'Row relation [stray] reaches [RvStraySchema], which is not a registered schema.');
});

it('reports a relation to a schema with no rows', function () {
    expect(fn () => validateDocSyntax('if can(manage for its settings) they can view'))
        ->toThrow(InvalidArgumentException::class, 'Row relation [settings] reaches schema [rv_settings], which has no rows for it to reach.');
});

it('reports an ability the relation\'s target does not declare', function () {
    expect(fn () => validateDocSyntax('if can(fly for its folder) they can view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly] is not declared by schema [rv_folders].');
});

it('reports a condition the relation\'s target does not declare inside a check(...)', function () {
    expect(fn () => validateDocSyntax('if check(is_shiny for its folder) they can view'))
        ->toThrow(InvalidArgumentException::class, 'is_shiny');
});

it('reports a @column naming a frame the predicate cannot see', function () {
    expect(docDiagnostics('if check(owner_is(@column nowhere.owner) for its folder) they can view'))
        ->toBe([[DiagnosticCode::ColumnNotInScope, '@column nowhere.owner']]);
});

it('refuses a relation handle inside a check(...) predicate, as it refuses any for', function () {
    expect(fn () => validateDocSyntax('if check(can(view for its folder) for rv_folders(@context id)) they can view'))
        ->toThrow(InvalidArgumentException::class, 'may not name a schema with for');
});

it('names the relation when it refuses something inside its check(...) predicate', function () {
    expect(fn () => validateDocSyntax('if check(check(is_locked for rv_folders) for its folder) they can view'))
        ->toThrow(
            InvalidArgumentException::class,
            'A check(...) may not be written inside the predicate of the check(...) for the schema row relation [folder] reaches;',
        );
});

// -- expansion ----------------------------------------------------------------

it('expands a check(...) predicate over a relation against the relation\'s target', function () {
    $expanded = expandedFor(
        WarrantSyntax::parse('if check(is_open for its folder) they can view')->validateWithoutSchemas()->scopedTo('rv_docs'),
        new RvDocSchema,
    );

    $predicate = $expanded->ruleNodes()[0]->conditions->predicate;

    expect($predicate)->toBeInstanceOf(DerivedConditionNode::class)
        ->and($predicate->conditionKey->name)->toBe('is_open');
});

// -- fixtures -----------------------------------------------------------------

class RvDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'rv_docs';

    public static function warrantSchema(): string
    {
        return RvDocSchema::class;
    }
}

class RvFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'rv_folders';

    public static function warrantSchema(): string
    {
        return RvFolderSchema::class;
    }
}

class RvFolderSchema extends ModelSchema
{
    public const model = RvFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isLocked(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('locked'), true);
    }

    #[RowCondition]
    public function ownerIs(RowConditionContext $c, mixed $owner): BuilderContract
    {
        return $c->query->where($c->column('owner'), $owner);
    }

    #[DerivedCondition]
    public function isOpen(): string
    {
        return 'not is_locked';
    }
}

class RvSettingsSchema extends RowlessSchema
{
    #[Ability]
    public const MANAGE = 'manage';
}

class RvStraySchema extends ModelSchema
{
    public const model = RvFolder::class;
}

class RvDocSchema extends ModelSchema
{
    public const model = RvDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowRelation(RvFolderSchema::class)]
    public function folder(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('folder_id'));
    }

    #[RowRelation(RvStraySchema::class)]
    public function stray(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery->where($c->relatedColumn('id'), $c->ownColumn('stray_id'));
    }

    #[RowRelation(RvSettingsSchema::class)]
    public function settings(RowRelationContext $c): BuilderContract
    {
        return $c->relatedQuery;
    }
}
