<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\NotNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\GlobalCondition;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowCondition;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| When blocks
|------------------------------------------------------------------------------
|
| A when block is sugar the expander flattens: every rule its body expands to
| takes the block's condition, and every rule its `otherwise` expands to takes
| the condition's negation. What it expands to is compared with the longhand
| an author would otherwise write, and then checked against rows.
|
*/

class WhenBlockModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'when_docs';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return WhenBlockSchema::class;
    }
}

class WhenBlockSchema extends WarrantSchema
{
    public const model = WhenBlockModel::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[Ability]
    public const DELETE = 'delete';

    #[GlobalCondition]
    public function always(GlobalConditionContext $c): bool
    {
        return true;
    }

    #[GlobalCondition]
    public function never(GlobalConditionContext $c): bool
    {
        return false;
    }

    /** The question has no answer here. */
    #[GlobalCondition]
    public function unanswerable(GlobalConditionContext $c): ?bool
    {
        return null;
    }

    #[RowCondition]
    public function isOwned(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->column('owner_id'), '=', $c->user->getAuthIdentifier());
    }

    #[RuleTemplate]
    public function ownerGrant(): string
    {
        return 'if is_owned they can';
    }

    #[RuleTemplate]
    public function ownerOnly(): string
    {
        return 'when is_owned { they can } otherwise { they cannot }';
    }
}

/**
 * The rules $syntax expands to against the when-block schema.
 *
 * @return list<WarrantRuleNode>
 */
function whenExpanded(string|BoundSyntax $syntax): array
{
    return expandedFor(WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('when_docs'), new WhenBlockSchema)->rules;
}

/**
 * The rules $syntax writes out longhand, as written.
 *
 * @return list<WarrantRuleNode>
 */
function whenLonghand(string $syntax): array
{
    return WarrantSyntax::parse($syntax)->validateWithoutSchemas()->scopedTo('when_docs')->entries;
}

function whenGuard()
{
    return Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new WhenBlockSchema);
}

beforeEach(function () {
    useWarrantSchemas(['when_docs' => WhenBlockSchema::class]);

    Schema::dropIfExists('when_docs');
    Schema::create('when_docs', function ($table) {
        $table->string('id')->primary();
        $table->string('owner_id');
    });

    DB::table('when_docs')->insert([
        ['id' => 'mine', 'owner_id' => 'role-1'],
        ['id' => 'theirs', 'owner_id' => 'someone-else'],
    ]);
});

// -- what a block expands to ----------------------------------------------------

it('expands a when block and its otherwise to the rules its longhand would produce', function (string $block, string $longhand) {
    expect(whenExpanded($block))->toEqualSyntax(whenLonghand($longhand));
})->with([
    'the condition onto each rule' => [
        'when always { if is_owned or never they can view they cannot delete } otherwise { they cannot view }',
        "if always and (is_owned or never) they can view they cannot delete\nif not always they cannot view",
    ],
    'nested when blocks' => [
        'when always { when never { they can edit } otherwise { if is_owned they can view } }',
        "if always and never they can edit\nif always and (not never and is_owned) they can view",
    ],
    'a when block in an ability block' => [
        'can they view { when always { if is_owned they can } otherwise { they cannot } }',
        "if always and is_owned they can view\nif not always they cannot view",
    ],
    'an ability block in a when block' => [
        'when always { can they view, edit { if is_owned they can } }',
        'if always and is_owned they can view, edit',
    ],
    'an include in a when block' => [
        'when always { @include owner_grant for view }',
        'if always and is_owned they can view',
    ],
    'a template whose body holds a when block' => [
        '@include owner_only for view, edit',
        "if is_owned they can view, edit\nif not is_owned they cannot view, edit",
    ],
    'an include in a when block in an ability block' => [
        'can they view { when never { @include owner_only } }',
        "if never and is_owned they can view\nif never and not is_owned they cannot view",
    ],
]);

it('replaces the bindings in a when block with their values', function () {
    expect(whenExpanded(new BoundSyntax('when always { they can :ability }', ['ability' => 'view'])))
        ->toEqualSyntax(whenLonghand('if always they can view'));
});

it('puts one condition instance on every rule of a block, and one negation of it on every rule of its otherwise', function () {
    $rules = whenExpanded('when always { they cannot delete if is_owned they can view } otherwise { they cannot view if is_owned they can edit }');
    $condition = $rules[0]->conditions;

    expect($rules[1]->conditions->leftSide)->toBe($condition)
        ->and($rules[2]->conditions)->toBeInstanceOf(NotNode::class)
        ->and($rules[2]->conditions->operand)->toBe($condition)
        ->and($rules[3]->conditions->leftSide)->toBe($rules[2]->conditions);
});

it('keeps the condition where it was written, and writes the and and not it implies nowhere', function () {
    $source = 'when always { if is_owned they can view } otherwise { they cannot view }';
    [$granted, $denied] = whenExpanded($source);

    $span = $granted->conditions->leftSide->location->span;

    expect(substr($source, $span->offset, $span->endOffset - $span->offset))->toBe('always')
        ->and($granted->conditions->location->isWritten())->toBeFalse()
        ->and($denied->conditions->location->isWritten())->toBeFalse();
});

// -- against rows ---------------------------------------------------------------

it('grants through a when block only where its condition holds', function () {
    bindWarrantRules('when always { if is_owned they can view } when never { they can edit }', schemaKey: 'when_docs');

    expect(whenGuard()->can('view', 'mine'))->toBeTrue()
        ->and(whenGuard()->can('view', 'theirs'))->toBeFalse()
        ->and(whenGuard()->can('edit', 'mine'))->toBeFalse();
});

it('applies the otherwise where the condition is false', function () {
    bindWarrantRules('when never { they can view } otherwise { if is_owned they can view }', schemaKey: 'when_docs');

    expect(whenGuard()->can('view', 'mine'))->toBeTrue()
        ->and(whenGuard()->can('view', 'theirs'))->toBeFalse();
});

it('grants nothing from either body when the condition is unknown', function () {
    bindWarrantRules('when unanswerable { they can view } otherwise { they can view }', schemaKey: 'when_docs');

    expect(whenGuard()->can('view', 'mine'))->toBeFalse();
});

it('still blocks with a deny in either body when the condition is unknown', function (string $deny) {
    /* `not unknown` is unknown, so the deny's `not (...)` is unknown too, and a
       row is only selected when the whole predicate is true. */
    bindWarrantRules("if is_owned they can view\n{$deny}", schemaKey: 'when_docs');

    expect(whenGuard()->can('view', 'mine'))->toBeFalse();
})->with([
    'in the when body' => ['when unanswerable { they cannot view }'],
    'in the otherwise' => ['when unanswerable { they can edit } otherwise { they cannot view }'],
]);
