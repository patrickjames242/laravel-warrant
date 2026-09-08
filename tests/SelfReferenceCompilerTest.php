<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\CrossSchemaCycleException;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Rules\RuleResolutionContext;
use Warrant\Rules\RuleResolver;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;
require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| SQL surface tests — a schema referencing itself
|------------------------------------------------------------------------------
|
| A can(...) or check(...) may target the schema it is written on, which is how
| one ability is expressed in terms of another over a different row of the same
| table. Two frames over one table need telling apart, and `as <alias>` is what
| does it: the hop's subquery selects `<table> as <alias>`, and everything about
| that frame — its correlated key, its own row conditions, its @column
| references — is qualified with the alias.
|
| An unaliased hop gets an identifier of its own too, since two frames sharing
| one would leave a correlation pointing at whichever the innermost `from` had
| rebound. The author's word is always the base of it.
|
| Fixture (at the foot of this file): one schema `sr_docs` over one table, with
| three abilities and a row condition
|   isOwner      (row): whereRaw("<frame>.owner = ?", ["role-1"])
|   ownerMatches (row): where(<arg>, '=', raw("<frame>.owner"))
|
*/

beforeEach(function () {
    Schema::create('sr_docs', function ($table) {
        $table->string('id');
        $table->string('owner');
        $table->string('parent_id')->nullable();
    });

    useWarrantSchemas(['sr_docs' => SrDocSchema::class]);
});

/**
 * Bind one rule set for the fixture schema, build filterQuery for $ability, and
 * assert its normalized, bindings-substituted SQL.
 */
function assertSelfRefSql(string $syntax, string $ability, string $expectedSql, array $context = []): void
{
    $set = WarrantRuleSet::fromSyntax($syntax, 'sr_docs');

    app()->instance(RuleResolver::class, new class($set) implements RuleResolver {
        public function __construct(private WarrantRuleSet $set) {}

        public function resolve(RuleResolutionContext $context): WarrantRuleSet
        {
            return $this->set;
        }
    });

    $sql = Warrant::guard(makeWarrantTestUser('role-1'))->forSchema((new SrDocSchema))->filterQuery(
        warrantTestQuery('sr_docs'),
        $ability,
        AbilityMatchMode::ALL,
        $context,
    )->toRawSql();

    expect(normalizeWarrantSql($sql))->toBe(normalizeWarrantSql($expectedSql));
}

// -- a chain of self-references -----------------------------------------------

it('gives each frame of a self-referencing chain its own alias', function () {
    /* Three abilities over one table, each defined in terms of the one below it
       on the same row. Every hop selects the table again under its own name, and
       everything about that frame follows the name: its correlated key, and the
       row condition at the bottom, which says `$c->row('owner')` without knowing
       how deep it has been reached. */
    assertSelfRefSql(
        <<<'RULES'
            if is_owner they can do_thing_1
            if can(do_thing_1 for sr_docs(@column id) as d2) they can do_thing_2
            if can(do_thing_2 for sr_docs(@column id) as d3) they can do_thing_3
        RULES,
        'do_thing_3',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "d3"
                    where "d3"."id" = "sr_docs"."id" and (
                        exists (
                            select * from "sr_docs" as "d2"
                            where "d2"."id" = "d3"."id" and (d2.owner = 'role-1')
                        )
                    )
                )
            )
        SQL,
    );
});

it('correlates a self-reference to a different row of the same table', function () {
    // The useful shape: an ability on a row that turns on a fact about its parent.
    assertSelfRefSql(
        'if check(is_owner for sr_docs(@column parent_id) as parent) they can do_thing_1',
        'do_thing_1',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "parent"
                    where "parent"."id" = "sr_docs"."parent_id" and (parent.owner = 'role-1')
                )
            )
        SQL,
    );
});

it('lets a self check(...) predicate name both frames at once', function () {
    /* What the alias buys on a `check(...)`: the predicate is written here, so
       naming the inner frame `parent` leaves `sr_docs` still meaning the row the
       rule is about, and one predicate can compare the two. */
    assertSelfRefSql(
        'if check(owner_matches(@column sr_docs.owner) for sr_docs(@column parent_id) as parent) they can do_thing_1',
        'do_thing_1',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "parent"
                    where "parent"."id" = "sr_docs"."parent_id"
                        and ("sr_docs"."owner" = parent.owner)
                )
            )
        SQL,
    );
});

// -- identifiers when two frames want the same name ----------------------------

it('gives an unaliased self-reference an identifier of its own', function () {
    /* The selector is resolved in the outer frame, so it has to keep meaning that
       frame once it is written down. A subquery selecting the bare table again
       would rebind the name to the inner frame and collapse the correlation into
       `"sr_docs"."id" = "sr_docs"."id"`. */
    assertSelfRefSql(
        <<<'WARRANT'
            if is_owner they can do_thing_1
            if can(do_thing_1 for sr_docs(@column id)) they can do_thing_2
        WARRANT,
        'do_thing_2',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "sr_docs_1"
                    where "sr_docs_1"."id" = "sr_docs"."id" and (sr_docs_1.owner = 'role-1')
                )
            )
        SQL,
    );
});

it('keeps the author alias as the base when two hops ask for one name', function () {
    /* Both hops write `as d2`, and the author's word survives into the SQL at
       both levels: the inner frame is distinguished, not renamed to something
       they would not recognise. */
    assertSelfRefSql(
        <<<'WARRANT'
            if is_owner they can do_thing_1
            if can(do_thing_1 for sr_docs(@column id) as d2) they can do_thing_2
            if can(do_thing_2 for sr_docs(@column id) as d2) they can do_thing_3
        WARRANT,
        'do_thing_3',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "d2"
                    where "d2"."id" = "sr_docs"."id" and (
                        exists (
                            select * from "sr_docs" as "d2_1"
                            where "d2_1"."id" = "d2"."id" and (d2_1.owner = 'role-1')
                        )
                    )
                )
            )
        SQL,
    );
});

// -- can(<ability>), with no boundary to cross ---------------------------------

it('compiles a can with no for clause inline, with no subquery at all', function () {
    /* Same schema, same row, same context — an `exists` over this table matched
       on its own key would ask a question the frame has already answered, so the
       ability's predicate is spliced where the reference sits. */
    assertSelfRefSql(
        <<<'WARRANT'
            if is_owner they can do_thing_1
            if can(do_thing_1) they can do_thing_2
        WARRANT,
        'do_thing_2',
        <<<SQL
            select * from "sr_docs" where (sr_docs.owner = 'role-1')
        SQL,
    );
});

it('collapses a whole chain of abilities into one predicate', function () {
    assertSelfRefSql(
        <<<'WARRANT'
            if is_owner they can do_thing_1
            if can(do_thing_1) they can do_thing_2
            if can(do_thing_2) they can do_thing_3
        WARRANT,
        'do_thing_3',
        <<<SQL
            select * from "sr_docs" where (sr_docs.owner = 'role-1')
        SQL,
    );
});

it('carries the check-time context through a can with no for clause', function () {
    // No boundary is crossed, so nothing has to be declared to cross it.
    assertSelfRefSql(
        <<<'WARRANT'
            if owner_is(@context who) they can do_thing_1
            if can(do_thing_1) they can do_thing_2
        WARRANT,
        'do_thing_2',
        <<<SQL
            select * from "sr_docs" where (sr_docs.owner = 'role-9')
        SQL,
        ['who' => 'role-9'],
    );
});

it('leaves the context behind once a for clause names a schema', function () {
    /* The same two rules with the boundary spelled out. `for` is what makes it a
       boundary, and a boundary is exactly what the context does not cross: B sees
       only what a `with` map hands it, so `@context who` arrives empty. */
    assertSelfRefSql(
        <<<'WARRANT'
            if owner_is(@context who) they can do_thing_1
            if can(do_thing_1 for sr_docs(@column id)) they can do_thing_2
        WARRANT,
        'do_thing_2',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "sr_docs_1"
                    where "sr_docs_1"."id" = "sr_docs"."id" and (sr_docs_1.owner = null)
                )
            )
        SQL,
        ['who' => 'role-9'],
    );
});

it('negates an inlined ability without disturbing its own deny side', function () {
    /* Under a `cannot` the reference is negated as a whole; the ability inside it
       still compiles its grants and denies the right way round. */
    assertSelfRefSql(
        <<<'WARRANT'
            they can do_thing_2
            if is_owner they can do_thing_1
            if can(do_thing_1) they cannot do_thing_2
        WARRANT,
        'do_thing_2',
        <<<SQL
            select * from "sr_docs" where (not (sr_docs.owner = 'role-1'))
        SQL,
    );
});

// -- nesting ------------------------------------------------------------------

it('nests a check inside a check, resolving each selector in its own frame', function () {
    /* "you own this doc's parent, and you own that parent's parent." The inner
       handle's `@column parent_id` is read in the *parent* frame, so the same
       unqualified text means a different table at each level. */
    assertSelfRefSql(
        <<<'WARRANT'
            if check(
                is_owner and check(is_owner for sr_docs(@column parent_id) as gp)
                for sr_docs(@column parent_id) as parent
            ) they can do_thing_1
        WARRANT,
        'do_thing_1',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "parent"
                    where "parent"."id" = "sr_docs"."parent_id" and (
                        parent.owner = 'role-1'
                        and exists (
                            select * from "sr_docs" as "gp"
                            where "gp"."id" = "parent"."parent_id" and (gp.owner = 'role-1')
                        )
                    )
                )
            )
        SQL,
    );
});

it('reads a schema-less can inside a predicate against the frame the predicate is about', function () {
    /* `can(do_thing_2)` names no schema, so it means the row the predicate is
       about — the `parent` frame — not the row the rule is about. */
    assertSelfRefSql(
        <<<'WARRANT'
            if owner_is(@context who) they can do_thing_2
            if check(can(do_thing_2) for sr_docs(@column parent_id) as parent) they can do_thing_1
        WARRANT,
        'do_thing_1',
        <<<SQL
            select * from "sr_docs" where (
                exists (
                    select * from "sr_docs" as "parent"
                    where "parent"."id" = "sr_docs"."parent_id" and (parent.owner = null)
                )
            )
        SQL,
        ['who' => 'role-9'],
    );
});

// -- recursion is still bounded ------------------------------------------------

it('rejects a can with no for clause that names the ability being compiled', function () {
    expect(fn () => assertSelfRefSql(
        'if can(do_thing_1) they can do_thing_1',
        'do_thing_1',
        'unreachable',
    ))->toThrow(CrossSchemaCycleException::class, 'cycle detected');
});

it('still rejects a self-reference to the ability being compiled', function () {
    /* Aliasing makes the SQL expressible; it does not make the recursion
       terminate. An ability defined in terms of itself has no base case, and the
       call stack rejects it on sight rather than unrolling to the depth cap.
       A chain naming a *different* ability each hop is fine — the test at the top
       of this file compiles one three deep. */
    expect(fn () => assertSelfRefSql(
        'if can(do_thing_1 for sr_docs(@column id) as d2) they can do_thing_1',
        'do_thing_1',
        'unreachable',
    ))->toThrow(CrossSchemaCycleException::class, 'cycle detected');
});

// -- fixtures -----------------------------------------------------------------

class SrDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'sr_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return SrDocSchema::class;
    }
}

class SrDocSchema extends WarrantSchema
{
    public const model = SrDoc::class;

    #[Ability]
    public const DO_THING_1 = 'do_thing_1';

    #[Ability]
    public const DO_THING_2 = 'do_thing_2';

    #[Ability]
    public const DO_THING_3 = 'do_thing_3';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$c->user->role_id]);
    }

    // Owner check driven by an argument, so a @context value can reach it.
    #[RowCondition]
    public function ownerIs(RowConditionContext $c, mixed $owner): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('owner')} = ?", [$owner]);
    }

    /* Compares this frame's owner to a column of another frame — both operands
       are expressions, so nothing is bound. */
    #[RowCondition]
    public function ownerMatches(RowConditionContext $c, mixed $column): BuilderContract
    {
        return $c->query->where($column, '=', DB::raw($c->row('owner')));
    }
}
