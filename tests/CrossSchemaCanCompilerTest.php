<?php


use Warrant\Facades\Warrant;
require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Warrant\Ability;
use Warrant\AbilityMatchMode;
use Warrant\GlobalCondition;
use Warrant\HasWarrantSchema;
use Warrant\RowCondition;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\CrossSchemaCycleException;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\WarrantSchema;

/*
|------------------------------------------------------------------------------
| SQL surface tests — cross-schema can(...)
|------------------------------------------------------------------------------
|
| These lock in the *exact SQL* filterQuery() emits for a rule that references
| another schema's ability via the can(...) builtin — not the rows it returns.
| Each test binds a rule set per schema, builds the query, and compares the
| emitted SQL (bindings already substituted, via toRawSql()) against a readable,
| hand-written expectation. Both sides run through normalizeWarrantSql(), so
| formatting and the builder's redundant *doubled* parentheses — `((E))` → `(E)`
| — are irrelevant; only tokens, literals, operators, and genuine parenthesised
| structure must match.
|
| How a can(...) reference is shaped (from RuleSetCompiler::applyCrossSchemaCan):
|   - a ROW-BOUND reference `can(<ab> for <schema>(<row>))` compiles schema B's
|     ability predicate against B's table and embeds it as
|     `exists (select * from <B> where <B>.id = <row> and (<B's predicate>))`;
|     under a `cannot` it embeds as `not exists (...)`.
|   - an UNBOUND reference `can(<ab> for <schema>)` compiles B with no target
|     (row conditions forced false) and splices B's boolean predicate inline;
|     a global condition returning a bool collapses to `1 = 1` / `1 = 0`, one
|     returning SQL splices its where-group verbatim.
|   - B sees only the explicit `with` map as its context — never A's ambient bag.
|
| Fixture conditions (defined at the foot of this file), with the acting user's
| role ("role-1") already substituted:
|   isOwner  (row, xc_folders):     whereRaw("xc_folders.owner = ?", ["role-1"])
|   ownerIs  (row, xc_folders):     whereRaw("xc_folders.owner = ?", [<arg>])
|   isSuper  (global, xc_capability): returns a bool (role_id === 'super')
|   tenantOk (global, xc_capability): whereRaw('? = ?', ['tenant', "role-1"])
|
*/

beforeEach(function () {
    // The tables need only exist for the models to resolve their connection and
    // grammar; these tests render SQL and never execute it, so no rows are seeded.
    Schema::create('xc_docs', fn ($table) => $table->string('id'));
    Schema::create('xc_folders', function ($table) {
        $table->string('id');
        $table->string('owner');
    });

    useWarrantSchemas([XcDocSchema::class, XcFolderSchema::class, XcCapabilitySchema::class]);
});

/**
 * Bind a resolver that returns a different rule set per schema key.
 *
 * @param array<string, string> $syntaxByKey
 */
function bindCrossSchemaRules(array $syntaxByKey): void
{
    $sets = [];
    foreach ($syntaxByKey as $key => $syntax) {
        $sets[$key] = WarrantRuleSet::fromSyntax($syntax, $key);
    }

    app()->instance(RuleResolver::class, new class($sets) implements RuleResolver {
        /** @param array<string, WarrantRuleSet> $sets */
        public function __construct(private array $sets) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $this->sets[$context->schemaKey] ?? new WarrantRuleSet($context->schemaKey, []);
        }
    });
}

/**
 * Bind the given per-schema rules, build filterQuery for schema A (xc_docs,
 * target `xc_docs.id`) and assert its normalized, bindings-substituted SQL.
 *
 * @param array<string, string>       $otherSyntax  Rules for the referenced schemas.
 * @param string|array<int, string>   $abilities
 * @param array<string, mixed>        $context
 */
function assertXcFilterSql(
    string $docSyntax,
    array $otherSyntax,
    string|array $abilities,
    array $context,
    string $expectedSql,
    AbilityMatchMode $matchMode = AbilityMatchMode::ALL,
    ?string $roleId = 'role-1',
): void {
    bindCrossSchemaRules(['xc_docs' => $docSyntax, ...$otherSyntax]);

    $sql = Warrant::guard(makeWarrantTestUser($roleId))->forSchema((new XcDocSchema))->filterQuery(
        warrantTestQuery('xc_docs'),
        'xc_docs.id',
        $abilities,
        $matchMode,
        $context,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql($expectedSql));
}

// -- row-bound can(...) --------------------------------------------------------

it('embeds a row-bound reference as an exists over the referenced table', function () {
    // The bound row id comes straight from @context folder_id; B's `is_owner`
    // predicate is compiled against B's table inside the EXISTS subquery.
    assertXcFilterSql(
        'if can(view for xc_folders(@context folder_id)) they can view',
        ['xc_folders' => 'if is_owner they can view'],
        'view',
        ['folder_id' => 'f-owned'],
        <<<SQL
            select * from "xc_docs" where (
                exists (
                    select * from "xc_folders"
                    where "xc_folders"."id" = 'f-owned'
                        and (xc_folders.owner = 'role-1')
                )
            )
        SQL,
    );
});

it('embeds a row-bound reference under a cannot as not exists', function () {
    // `they can view` contributes the always-true grant; the conditional cannot
    // that references B's `manage` ability compiles to `and not exists (...)`.
    assertXcFilterSql(
        'they can view if can(manage for xc_folders(@context folder_id)) they cannot view',
        ['xc_folders' => 'if is_owner they can view, manage'],
        'view',
        ['folder_id' => 'f-owned'],
        <<<SQL
            select * from "xc_docs" where (
                (1 = 1)
                and
                (not exists (
                    select * from "xc_folders"
                    where "xc_folders"."id" = 'f-owned'
                        and (xc_folders.owner = 'role-1')
                ))
            )
        SQL,
    );
});

// -- unbound can(...) on a capability schema -----------------------------------

it('collapses an unbound reference to a bool global condition', function () {
    // isSuper returns a bool; a non-super user makes the reference `1 = 0`.
    assertXcFilterSql(
        'if can(access for xc_capability) they can view',
        ['xc_capability' => 'if is_super they can access'],
        'view',
        [],
        <<<SQL
            select * from "xc_docs" where (1 = 0)
        SQL,
    );

    // A super user makes the same reference `1 = 1`.
    assertXcFilterSql(
        'if can(access for xc_capability) they can view',
        ['xc_capability' => 'if is_super they can access'],
        'view',
        [],
        <<<SQL
            select * from "xc_docs" where (1 = 1)
        SQL,
        roleId: 'super',
    );
});

it('splices an unbound reference to a SQL-emitting global condition inline', function () {
    // tenantOk emits a where-group (not a bool); it splices in verbatim, no EXISTS.
    assertXcFilterSql(
        'if can(access for xc_capability) they can view',
        ['xc_capability' => 'if tenant_ok they can access'],
        'view',
        [],
        <<<SQL
            select * from "xc_docs" where ('tenant' = 'role-1')
        SQL,
    );
});

// -- explicit with-map (no ambient inheritance) --------------------------------

it('feeds the referenced schema a fresh bag built from the with map', function () {
    // B's owner check reads B's `picked` key, filled from A's `outer` (role-2) —
    // not the acting user's role_id (role-1), proving the value flows via the map
    // and A's ambient context is not inherited.
    assertXcFilterSql(
        'if can(view for xc_folders(@context folder_id) with picked = @context outer) they can view',
        ['xc_folders' => 'if owner_is(@context picked) they can view'],
        'view',
        ['folder_id' => 'f-owned', 'outer' => 'role-2'],
        <<<SQL
            select * from "xc_docs" where (
                exists (
                    select * from "xc_folders"
                    where "xc_folders"."id" = 'f-owned'
                        and (xc_folders.owner = 'role-2')
                )
            )
        SQL,
    );
});

// -- sibling references (path-scoped cycle guard) ------------------------------

it('emits two independent exists clauses for sibling references to one schema', function () {
    // Both operands reference (xc_folders, view) on sibling branches of one OR;
    // the path-scoped guard must not treat the second as a repeat of the first,
    // so both compile to their own EXISTS.
    assertXcFilterSql(
        'if can(view for xc_folders(@context f1)) or can(view for xc_folders(@context f2)) they can view',
        ['xc_folders' => 'if is_owner they can view'],
        'view',
        ['f1' => 'f-owned', 'f2' => 'f-other'],
        <<<SQL
            select * from "xc_docs" where (
                exists (
                    select * from "xc_folders"
                    where "xc_folders"."id" = 'f-owned'
                        and (xc_folders.owner = 'role-1')
                )
                or
                exists (
                    select * from "xc_folders"
                    where "xc_folders"."id" = 'f-other'
                        and (xc_folders.owner = 'role-1')
                )
            )
        SQL,
    );
});

// -- cycle detection -----------------------------------------------------------

it('throws while compiling when two schemas reference each other in a cycle', function () {
    bindCrossSchemaRules([
        'xc_docs' => 'if can(view for xc_folders(@context folder_id)) they can view',
        'xc_folders' => 'if can(view for xc_docs(@context doc_id)) they can view',
    ]);

    // The cross-schema recursion runs while filterQuery builds its where-clause,
    // so the cycle is detected at compile time, before any SQL executes.
    expect(fn () => Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new XcDocSchema))->filterQuery(
        warrantTestQuery('xc_docs'),
        'xc_docs.id',
        'view',
        AbilityMatchMode::ALL,
        ['folder_id' => 'f-owned', 'doc_id' => 'doc-1'],
    )->toRawSql())
        ->toThrow(CrossSchemaCycleException::class, 'cycle detected');
});

// -- fixtures -----------------------------------------------------------------

class XcDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'xc_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return XcDocSchema::class;
    }
}

class XcDocSchema extends WarrantSchema
{
    public const schemaKey = 'xc_docs';
    public const model = XcDoc::class;

    #[Ability]
    public const VIEW = 'view';
}

class XcFolder extends Model
{
    use HasWarrantSchema;

    protected $table = 'xc_folders';
    public $incrementing = false;
    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return XcFolderSchema::class;
    }
}

class XcFolderSchema extends WarrantSchema
{
    public const schemaKey = 'xc_folders';
    public const model = XcFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const MANAGE = 'manage';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }

    // Owner check driven by a context argument (used to prove with-map plumbing).
    #[RowCondition]
    public function ownerIs(RowConditionContext $c, mixed $owner): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$owner]);
    }
}

class XcCapabilitySchema extends WarrantSchema
{
    public const schemaKey = 'xc_capability';

    #[Ability]
    public const ACCESS = 'access';

    #[GlobalCondition]
    public function isSuper(GlobalConditionContext $c): bool
    {
        return $c->user->role_id === 'super';
    }

    // A global condition that emits SQL (rather than a bool), so its shape is
    // visible in the SQL-surface tests.
    #[GlobalCondition]
    public function tenantOk(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('? = ?', ['tenant', $c->user->role_id]);
    }
}
