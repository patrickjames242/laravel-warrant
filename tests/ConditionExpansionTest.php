<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\Builders\Ref;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Compiling\CrossSchemaCycleException;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\DerivedCondition;
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

    Schema::create('dc_files', function ($table) {
        $table->string('id');
        $table->string('folder_id')->nullable();
    });

    useWarrantSchemas([
        'dc_folders' => DcFolderSchema::class,
        'dc_files' => DcFileSchema::class,
    ]);
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

function adjacentExpansionSql(string $conditionKey): string
{
    bindWarrantRules(
        "if check({$conditionKey} for dc_folders(@column parent_id) as p) they can view",
        schemaKey: 'dc_folders',
    );
    // The guard memoizes the rule set it resolved, so drop it for the new one.
    Warrant::flush();

    return normalizeWarrantSql(
        Warrant::guard(makeWarrantTestUser('role-1'))
            ->forSchema((new DcFolderSchema))
            ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])
            ->toRawSql()
    );
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
    Warrant::flush();
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

it('stops a condition that expands into itself at expansion, with a depth error', function () {
    bindWarrantRules('if runaway they can view', schemaKey: 'dc_folders');

    try {
        Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DcFolderSchema))
            ->filterQuery(warrantTestQuery('dc_folders'), 'view', AbilityMatchMode::ALL, [])->toRawSql();

        $this->fail('Expected an expansion depth error.');
    } catch (RuntimeException $e) {
        // The chain names the condition, collapses the repetition rather than
        // printing it sixty-five times, and says why it can never terminate.
        expect($e->getMessage())
            ->toContain('maximum nesting depth of 64')
            ->toContain('dc_folders.runaway  (repeated 65 times)')
            ->toContain('no base case to reach');
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

// -- an expansion names its own frame, and only its own frame -----------------

it('reads a qualified @column in an expansion as the frame the condition was asked about', function () {
    /* The condition's author names their own schema key meaning "my row", and it
       has to stay that row however the caller reached them — here through a
       `check(... as p)`, which for the caller's own text would leave the key
       meaning the caller's row. */
    assertDcFilterSql(
        'if check(qualified_parent_owned for dc_folders(@column parent_id) as p) they can view',
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

it('emits the same SQL for a qualified expansion as for the unqualified one', function () {
    $qualified = adjacentExpansionSql('qualified_parent_owned');
    $unqualified = adjacentExpansionSql('parent_is_owned');

    expect($qualified)->toBe($unqualified);
});

it('hides the caller\'s names from an expansion, whatever the caller had in scope', function () {
    /* `p` is the caller's word for a frame, introduced by the caller's handle. A
       condition reached through that handle is not the text that wrote it and
       cannot name it, so the reference is reported rather than resolved. */
    expect(fn () => assertDcFilterSql(
        'if check(names_callers_alias for dc_folders(@column parent_id) as p) they can view',
        'unreachable',
    ))->toThrow(InvalidArgumentException::class, 'names [p], which is not in scope here');
});

it('names the schema key in an expansion reached from another schema', function () {
    /* Reached through a hop from dc_files, whose scope binds neither dc_folders
       nor anything else this condition knows. Its own key still resolves, to the
       frame the hop selected. */
    bindWarrantRules(
        'if check(qualified_parent_owned for dc_folders(@column folder_id) as f) they can view',
        schemaKey: 'dc_files',
    );

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))
        ->forSchema((new DcFileSchema))
        ->filterQuery(warrantTestQuery('dc_files'), 'view', AbilityMatchMode::ALL, [])
        ->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql(<<<SQL
        select * from "dc_files" where (
            exists (
                select * from "dc_folders" as "f"
                where "f"."id" = "dc_files"."folder_id" and (
                    exists (
                        select * from "dc_folders" as "parent"
                        where "parent"."id" = "f"."parent_id" and (parent.owner = 'role-1')
                    )
                )
            )
        )
    SQL));
});

it('folds a qualified @column in an expansion with no row in scope', function () {
    /* An untargeted compile binds the schema key to no qualifier at all — a row
       known but not in scope. The reference folds to an unknown, which grants
       nothing, rather than throwing or naming a table the query never selects. */
    bindWarrantRules('if qualified_global they can view', schemaKey: 'dc_folders');

    expect(Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new DcFolderSchema))->can('view'))
        ->toBeFalse();
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
    #[DerivedCondition]
    public function isEditable(): IBooleanExpressionNode
    {
        return WarrantSyntax::parse(<<<WARRANT
            is_owner or owner_is('role-9')
        WARRANT)->conditionExpression();
    }

    /** Derived, as a bare AST node. */
    /**
     * Expands into a `check(...)` over another row of this same table — the case
     * a derived condition cannot express by constraining its own builder, since
     * the correlated frame is not the one it was handed.
     */
    #[DerivedCondition]
    public function parentIsOwned(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            'is_owner',
            DcFolderSchema::class,
            Ref::column('parent_id'),
            as: 'parent',
        );
    }

    /**
     * The same expansion, written with the qualified form of the reference — the
     * author of dc_folders naming dc_folders' rows.
     */
    #[DerivedCondition]
    public function qualifiedParentOwned(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            'is_owner',
            DcFolderSchema::class,
            Ref::column('dc_folders', 'parent_id'),
            as: 'parent',
        );
    }

    /** Names a frame only the calling rule's text could have known about. */
    #[DerivedCondition]
    public function namesCallersAlias(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            'is_owner',
            DcFolderSchema::class,
            Ref::column('p', 'parent_id'),
            as: 'gp',
        );
    }

    /**
     * A global condition — asked without a row — expanding into a reference that
     * names this schema's rows.
     */
    #[DerivedCondition]
    public function qualifiedGlobal(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCheck(
            'is_owner',
            DcFolderSchema::class,
            Ref::column('dc_folders', 'parent_id'),
            as: 'gp',
        );
    }

    #[DerivedCondition]
    public function alwaysTrue(): BooleanNode
    {
        return new BooleanNode(true);
    }

    #[DerivedCondition]
    public function emptyExpansion(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build();
    }

    /** Expands into itself with the same arguments: no base case, ever. */
    #[DerivedCondition]
    public function runaway(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->if('runaway');
    }

    /** Closes a can(...) loop from inside a condition. */
    #[DerivedCondition]
    public function derivedCan(): WarrantConditionBuilder
    {
        return WarrantConditionBuilder::build()->ifCan('view', DcFolderSchema::class);
    }
}

class DcFile extends Model
{
    use HasWarrantSchema;

    protected $table = 'dc_files';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return DcFileSchema::class;
    }
}

class DcFileSchema extends WarrantSchema
{
    public const model = DcFile::class;

    #[Ability]
    public const VIEW = 'view';
}
