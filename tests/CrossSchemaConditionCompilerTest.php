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
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\WarrantSchema;

/*
|------------------------------------------------------------------------------
| SQL surface tests — cross-schema check(...)
|------------------------------------------------------------------------------
|
| These lock in the *exact SQL* filterQuery() emits for a rule that delegates a
| domain question to another schema via the check(...) builtin. Unlike can(...),
| check(...) never compiles the target schema's rules — it dispatches the target's
| own conditions and splices the emitted SQL. Each test binds only schema A's rule
| set (the check leaves call B's condition methods directly), builds the query, and
| compares the normalized, bindings-substituted SQL against a hand-written shape.
|
| How a check(...) reference is shaped (from RuleSetCompiler::crossSchemaCheckLeaf):
|   - a ROW-BOUND reference `check(<pred> for <schema>(<row>))` compiles the
|     predicate against B's table and embeds it as
|     `exists (select * from <B> where <B>.id = <row> and (<predicate>))`;
|     under a `cannot`/`not` it embeds as `not exists (...)`.
|   - an UNBOUND reference `check(<pred> for <schema>)` compiles the predicate with
|     no target and splices its tree inline, so a predicate that decides outright
|     folds into A's rather than stopping at a literal. A global condition
|     returning a bool therefore only shows up as `1 = 1` / `1 = 0` when it
|     decides the *whole* predicate; one returning SQL splices its where-group
|     verbatim.
|   - B sees only the explicit `with` map as its context — never A's ambient bag.
|
| Fixture conditions (defined at the foot of this file), with the acting user's
| role ("role-1") already substituted:
|   isOwner  (row, chk_targets):       whereRaw("chk_targets.owner = ?", ["role-1"])
|   ownerIs  (row, chk_targets):       whereRaw("chk_targets.owner = ?", [<arg>])
|   isSuper  (global, chk_capability): returns a bool (role_id === 'super')
|   tenantOk (global, chk_capability): whereRaw('? = ?', ['tenant', "role-1"])
|
*/

beforeEach(function () {
    Schema::create('chk_docs', fn ($table) => $table->string('id'));
    Schema::create('chk_targets', function ($table) {
        $table->string('id');
        $table->string('owner');
    });

    useWarrantSchemas(['chk_docs' => ChkDocSchema::class, 'chk_targets' => ChkTargetSchema::class, 'chk_capability' => ChkCapabilitySchema::class]);
});

/**
 * Bind a resolver that returns schema A's rule set. The referenced schema's
 * conditions are dispatched directly, so only A needs a rule set here.
 */
function bindCheckDocRules(string $docSyntax): void
{
    $set = WarrantRuleSet::fromSyntax($docSyntax, 'chk_docs');

    app()->instance(RuleResolver::class, new class($set) implements RuleResolver {
        public function __construct(private WarrantRuleSet $set) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $context->schemaKey === 'chk_docs'
                ? $this->set
                : new WarrantRuleSet($context->schemaKey, []);
        }
    });
}

/**
 * Bind schema A's rule, build filterQuery for chk_docs (target `chk_docs.id`) and
 * assert its normalized, bindings-substituted SQL.
 *
 * @param array<string, mixed> $context
 */
function assertChkFilterSql(
    string $docSyntax,
    array $context,
    string $expectedSql,
    ?string $roleId = 'role-1',
): void {
    bindCheckDocRules($docSyntax);

    $sql = Warrant::guard(makeWarrantTestUser($roleId))->forSchema((new ChkDocSchema))->filterQuery(
        warrantTestQuery('chk_docs'),
        'chk_docs.id',
        'view',
        AbilityMatchMode::ALL,
        $context,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql($expectedSql));
}

// -- row-bound check(...) ------------------------------------------------------

it('embeds a row-bound predicate as an exists over the referenced table', function () {
    assertChkFilterSql(
        'if check(is_owner for chk_targets(@context tid)) they can view',
        ['tid' => 'f-owned'],
        <<<SQL
            select * from "chk_docs" where (
                exists (
                    select * from "chk_targets"
                    where "chk_targets"."id" = 'f-owned'
                        and (chk_targets.owner = 'role-1')
                )
            )
        SQL,
    );
});

it('embeds a row-bound predicate under a cannot as not exists', function () {
    // The unconditional grant's always-true term folds away, leaving the deny.
    assertChkFilterSql(
        'they can view if check(is_owner for chk_targets(@context tid)) they cannot view',
        ['tid' => 'f-owned'],
        <<<SQL
            select * from "chk_docs" where (
                not exists (
                    select * from "chk_targets"
                    where "chk_targets"."id" = 'f-owned'
                        and (chk_targets.owner = 'role-1')
                )
            )
        SQL,
    );
});

it('compiles a complex boolean predicate of conditions inside the exists', function () {
    // is_owner OR (owner_is('role-9') AND NOT is_owner) — all against B's row.
    assertChkFilterSql(
        "if check(is_owner or (owner_is('role-9') and not is_owner) for chk_targets(@context tid)) they can view",
        ['tid' => 'f-owned'],
        <<<SQL
            select * from "chk_docs" where (
                exists (
                    select * from "chk_targets"
                    where "chk_targets"."id" = 'f-owned'
                        and (
                            chk_targets.owner = 'role-1'
                            or (
                                chk_targets.owner = 'role-9'
                                and not (chk_targets.owner = 'role-1')
                            )
                        )
                )
            )
        SQL,
    );
});

// -- unbound check(...) on a capability schema ---------------------------------

it('collapses an unbound predicate of a bool global condition', function () {
    // isSuper returns a bool; a non-super user makes the reference `1 = 0`.
    assertChkFilterSql(
        'if check(is_super for chk_capability) they can view',
        [],
        <<<SQL
            select * from "chk_docs" where (1 = 0)
        SQL,
    );

    // A super user makes the same reference `1 = 1`.
    assertChkFilterSql(
        'if check(is_super for chk_capability) they can view',
        [],
        <<<SQL
            select * from "chk_docs" where (1 = 1)
        SQL,
        roleId: 'super',
    );
});

it('splices an unbound predicate of a SQL-emitting global condition inline', function () {
    assertChkFilterSql(
        'if check(tenant_ok for chk_capability) they can view',
        [],
        <<<SQL
            select * from "chk_docs" where ('tenant' = 'role-1')
        SQL,
    );
});

it('splices an unbound boolean predicate of several global conditions inline', function () {
    // is_super returns false and tenant_ok returns SQL; the false side of the OR
    // is dropped rather than emitted as a `1 = 0` operand.
    assertChkFilterSql(
        'if check(is_super or tenant_ok for chk_capability) they can view',
        [],
        <<<SQL
            select * from "chk_docs" where ('tenant' = 'role-1')
        SQL,
    );
});

it('negates an unbound predicate spliced inline', function () {
    assertChkFilterSql(
        'if not check(tenant_ok for chk_capability) they can view',
        [],
        <<<SQL
            select * from "chk_docs" where (not ('tenant' = 'role-1'))
        SQL,
    );
});

// -- explicit with-map (no ambient inheritance) --------------------------------

it('feeds the referenced condition a fresh bag built from the with map', function () {
    // owner_is reads its arg from @context picked, filled from A's `outer` (role-2)
    // via the with-map — proving the value flows through the map and A's ambient
    // context (which has role-1 as the acting user) is not inherited.
    assertChkFilterSql(
        'if check(owner_is(@context picked) for chk_targets(@context tid) with picked = @context outer) they can view',
        ['tid' => 'f-owned', 'outer' => 'role-2'],
        <<<SQL
            select * from "chk_docs" where (
                exists (
                    select * from "chk_targets"
                    where "chk_targets"."id" = 'f-owned'
                        and (chk_targets.owner = 'role-2')
                )
            )
        SQL,
    );
});

// -- fixtures -----------------------------------------------------------------

class ChkDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'chk_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ChkDocSchema::class;
    }
}

class ChkDocSchema extends WarrantSchema
{
    public const model = ChkDoc::class;

    #[Ability]
    public const VIEW = 'view';
}

class ChkTarget extends Model
{
    use HasWarrantSchema;

    protected $table = 'chk_targets';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return ChkTargetSchema::class;
    }
}

class ChkTargetSchema extends WarrantSchema
{
    public const model = ChkTarget::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }

    // Owner check driven by a condition argument (used to prove with-map plumbing).
    #[RowCondition]
    public function ownerIs(RowConditionContext $c, mixed $owner): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$owner]);
    }
}

class ChkCapabilitySchema extends WarrantSchema
{

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
