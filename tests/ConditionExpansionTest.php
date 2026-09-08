<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Compiling\CompileDepthException;
use Warrant\DSL\Compiling\CrossSchemaCycleException;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| Derived conditions — a condition that answers with an expression
|------------------------------------------------------------------------------
|
| A condition may return a WarrantConditionBuilder (or a bare AST node) instead
| of constraining the builder it was handed. The compiler walks the result as
| though the author had written it inline in the rule, which is what these tests
| pin down: the SQL is identical to the equivalent hand-written rule, negation
| De Morgans through the expansion, and the expansion is bounded by the
| CallStack's depth budget rather than by cycle detection.
|
*/

beforeEach(function () {
    Schema::create('dc_folders', function ($table) {
        $table->string('id');
        $table->string('owner');
        $table->string('parent_id')->nullable();
    });

    useWarrantSchemas(['dc_folders' => DcFolderSchema::class]);
});

function assertDcFilterSql(string $syntax, string $expectedSql): void
{
    bindWarrantRules($syntax, schemaKey: 'dc_folders');

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema((new DcFolderSchema))
        ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])
        ->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql($expectedSql));
}

// -- a derived condition may expand into a cross-schema reference --------------

it('compiles a check(...) an expansion produced, in the frame the condition sits in', function () {
    /* The compiler walks an expansion as though it were written inline, and that
       includes the cross-schema builtins — so a condition can reach a frame its
       own builder could never have named. */
    assertDcFilterSql('if parent_is_owned they can view', <<<SQL
        select * from "dc_folders" where (
            exists (
                select * from "dc_folders" as "parent"
                where "parent"."id" = "dc_folders"."parent_id" and (parent.owner = 'role-1')
            )
        )
    SQL);
});

it('emits the same SQL for an expanded check as for the hand-written rule', function () {
    assertDcFilterSql(
        'if check(is_owner for dc_folders(@column parent_id) as parent) they can view',
        <<<SQL
            select * from "dc_folders" where (
                exists (
                    select * from "dc_folders" as "parent"
                    where "parent"."id" = "dc_folders"."parent_id" and (parent.owner = 'role-1')
                )
            )
        SQL,
    );
});

it('expands a condition reached from inside a predicate, in that predicate\'s frame', function () {
    /* Two levels, one of them produced by PHP: the outer check names a condition
       of the target, and that condition derives itself into a further check whose
       selector is read in the *target's* frame, not the rule's. */
    assertDcFilterSql(
        'if check(parent_is_owned for dc_folders(@column parent_id) as p) they can view',
        <<<SQL
            select * from "dc_folders" where (
                exists (
                    select * from "dc_folders" as "p"
                    where "p"."id" = "dc_folders"."parent_id" and (
                        exists (
                            select * from "dc_folders" as "parent"
                            where "parent"."id" = "p"."parent_id" and (parent.owner = 'role-1')
                        )
                    )
                )
            )
        SQL,
    );
});

// -- a derived condition compiles as if written inline -------------------------

it('compiles a condition that returns a builder as the expression it composed', function () {
    // is_editable expands to `is_owner or owner_is('role-9')`.
    assertDcFilterSql(
        'if is_editable they can view',
        <<<SQL
            select * from "dc_folders" where (
                dc_folders.owner = 'role-1' or dc_folders.owner = 'role-9'
            )
        SQL,
    );
});

it('emits the same SQL as the hand-written rule it expands to', function () {
    bindWarrantRules('if is_editable they can view', schemaKey: 'dc_folders');
    $derived = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DcFolderSchema))
        ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])->toRawSql();

    bindWarrantRules("if is_owner or owner_is('role-9') they can view", schemaKey: 'dc_folders');
    $inline = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DcFolderSchema))
        ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])->toRawSql();

    expect(normalizeWarrantSql($derived))->toBe(normalizeWarrantSql($inline));
});

it('pushes negation through an expansion, De Morgan and all', function () {
    // not (is_owner or owner_is) => not is_owner and not owner_is.
    assertDcFilterSql(
        'if not is_editable they can view',
        <<<SQL
            select * from "dc_folders" where (
                (not (dc_folders.owner = 'role-1') and not (dc_folders.owner = 'role-9'))
            )
        SQL,
    );
});

it('accepts a bare expression node as well as a builder', function () {
    // always_true returns a BooleanNode, which folds the whole predicate away.
    assertDcFilterSql('if always_true they can view', 'select * from "dc_folders" where (1 = 1)');
});

it('rejects a builder with no terms, which would silently match every row', function () {
    bindWarrantRules('if empty_expansion they can view', schemaKey: 'dc_folders');

    expect(fn () => Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DcFolderSchema))
        ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])->toRawSql())
        ->toThrow(InvalidArgumentException::class, 'returned a condition builder with no terms');
});

// -- the call stack bounds what cycle detection cannot decide ------------------

it('stops a condition that expands into itself with a depth error, not a cycle error', function () {
    bindWarrantRules('if runaway they can view', schemaKey: 'dc_folders');

    try {
        Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DcFolderSchema))
            ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])->toRawSql();

        $this->fail('Expected a CompileDepthException.');
    } catch (CompileDepthException $e) {
        // The trace names the expansion, collapses the repetition rather than
        // printing it sixty times, and says why it can never terminate.
        expect($e->getMessage())
            ->toContain('maximum nesting depth')
            ->toContain('dc_folders:view')
            ->toContain('dc_folders.runaway')
            ->toContain('repeat this 1-frame segment')
            ->toContain('cannot terminate');

        expect($e->calls())->toHaveCount(65);
    }
});

it('shows the invisible layers in a cycle trace', function () {
    // The rule says `if derived_can they can view`; the can(...) that closes the
    // loop lives inside the condition, where the rule string cannot show it.
    bindWarrantRules('if derived_can they can view', schemaKey: 'dc_folders');

    try {
        Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DcFolderSchema))
            ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])->toRawSql();

        $this->fail('Expected a CrossSchemaCycleException.');
    } catch (CrossSchemaCycleException $e) {
        expect($e->getMessage())
            ->toContain('cycle detected')
            ->toContain('1. dc_folders:view')
            ->toContain('2. dc_folders.derived_can')
            ->toContain('3. dc_folders:view')
            ->toContain('cycle starts here')
            ->toContain('re-enters frame 1');
    }
});

// -- fixtures -----------------------------------------------------------------

class DcFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'dc_folders';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return DcFolderSchema::class;
    }
}

class DcFolderSchema extends WarrantSchema
{
    public const model = DcFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }

    #[RowCondition]
    public function ownerIs(RowConditionContext $c, mixed $owner): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$owner]);
    }

    /** Derived: answers with the expression rather than SQL of its own. */
    #[RowCondition]
    public function isEditable(RowConditionContext $c): IBooleanExpressionNode
    {
        return Warrant::condition(<<<WARRANT
            is_owner or owner_is('role-9')
        WARRANT);
    }

    /** Derived, as a bare AST node. */
    /**
     * Expands into a `check(...)` over another row of this same table — the case
     * a derived condition cannot express by constraining its own builder, since
     * the correlated frame is not the one it was handed.
     */
    #[RowCondition]
    public function parentIsOwned(RowConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            'is_owner',
            DcFolderSchema::class,
            \Warrant\Builders\Ref::column('parent_id'),
            as: 'parent',
        );
    }

    #[GlobalCondition]
    public function alwaysTrue(GlobalConditionContext $c): BooleanNode
    {
        return new BooleanNode(true);
    }

    #[GlobalCondition]
    public function emptyExpansion(GlobalConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build();
    }

    /** Expands into itself with the same arguments: no base case, ever. */
    #[GlobalCondition]
    public function runaway(GlobalConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->if('runaway');
    }

    /** Closes a can(...) loop from inside a condition. */
    #[GlobalCondition]
    public function derivedCan(GlobalConditionContext $c): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCan('view', DcFolderSchema::class);
    }
}
