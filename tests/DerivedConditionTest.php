<?php

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\AbilityMatchMode;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Expanding\ExpandedRuleSet;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\ContextRef;
use Warrant\DSL\Parsing\ASTNodes\IBooleanExpressionNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\DerivedCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\RuleTemplate;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/*
|------------------------------------------------------------------------------
| Derived conditions — conditions built from other conditions
|------------------------------------------------------------------------------
|
| A #[DerivedCondition] takes its DSL arguments and no context object, and
| answers with an expression rather than SQL. Knowing no user, row or check
| context, it is expanded into that expression before anything compiles; a
| `@context` or `@column` argument reaches it as the reference, to be passed on
| and resolved where the expression lands.
|
| A row or global condition no longer answers with an expression at all.
|
*/

beforeEach(function () {
    Schema::create('dk_docs', function ($table) {
        $table->string('id');
        $table->string('owner_id');
        $table->string('tenant_id');
    });

    useWarrantSchemas(['dk_docs' => DkDocSchema::class]);

    DkDocSchema::$asTextCalls = 0;
});

function dkGuard()
{
    return Warrant::guard(makeWarrantTestUser('role-1'))->forSchema(new DkDocSchema);
}

/** Bind $syntax, dropping the guards that memoized whatever was bound before. */
function bindDkRules(string $syntax): void
{
    bindWarrantRules($syntax, schemaKey: 'dk_docs');
    Warrant::flush();
}

function dkFilterSql(string $syntax, array $context = []): string
{
    bindDkRules($syntax);

    return normalizeWarrantSql(
        dkGuard()->filterQuery(warrantTestQuery('dk_docs'), 'view', AbilityMatchMode::ALL, $context)->toRawSql()
    );
}

// -- what a derived condition may answer with ---------------------------------

it('passes on a @context argument it cannot read, resolved where the expression lands', function () {
    expect(dkFilterSql('if owned_or_tenant(@context tenant) they can view', ['tenant' => 't-1']))
        ->toBe(dkFilterSql('if is_owned or in_tenant(@context tenant) they can view', ['tenant' => 't-1']));

    // The method saw the reference, not a value: there was no context to read.
    expect(DkDocSchema::$lastTenant)->toBeInstanceOf(ContextRef::class);
});

it('reads a @column argument with the caller\'s names, not the condition\'s', function () {
    /* Inside the predicate, the caller's `dk_docs` is the outer row. The
       condition's own scope binds `dk_docs` to the row it was asked about —
       `other` — so resolving the argument there would compare a row with itself. */
    expect(dkFilterSql('if check(owner_matches(@column dk_docs.owner_id) for dk_docs(@column id) as other) they can view'))
        ->toBe(dkFilterSql('if check(owner_is_column(@column dk_docs.owner_id) for dk_docs(@column id) as other) they can view'))
        ->toContain('"other"."owner_id" = "dk_docs"."owner_id"');
});

it('reads a @column argument naming the caller\'s alias, which the condition cannot see', function () {
    expect(dkFilterSql('if check(owner_matches(@column other.tenant_id) for dk_docs(@column id) as other) they can view'))
        ->toBe(dkFilterSql('if check(owner_is_column(@column other.tenant_id) for dk_docs(@column id) as other) they can view'));
});

it('answers with rule text, parsed as a condition expression', function () {
    expect(dkFilterSql('if as_text they can view'))->toBe(dkFilterSql('if is_owned they can view'));
});

it('answers with a constant, deciding the outcome outright', function () {
    bindDkRules('if yes they can view');
    expect(dkGuard()->can('view'))->toBeTrue();

    bindDkRules('if no they can view');
    expect(dkGuard()->can('view'))->toBeFalse();
});

it('answers unknown with null, which neither grants nor negates into a grant', function () {
    expect(dkFilterSql('if unanswerable they can view'))
        ->toBe(normalizeWarrantSql('select * from "dk_docs" where (null)'));

    expect(dkFilterSql('if not unanswerable they can view'))
        ->toBe(normalizeWarrantSql('select * from "dk_docs" where (null)'));
});

it('rejects an answer that is not an expression, a builder, text, a bool or null', function () {
    expect(fn () => dkFilterSql('if bad_answer they can view'))
        ->toThrow(RuntimeException::class, 'must answer with an expression');
});

it('rejects too few arguments when it is expanded', function () {
    expect(fn () => dkFilterSql("if needs_two('a') they can view"))
        ->toThrow(InvalidArgumentException::class, 'requires at least 2 argument(s), but the rule supplied 1');
});

// -- when it is expanded ------------------------------------------------------

it('is expanded once per guard, however many checks read it', function () {
    bindDkRules('if as_text they can view');
    DB::table('dk_docs')->insert(['id' => 'd1', 'owner_id' => 'role-1', 'tenant_id' => 't-1']);

    $guard = dkGuard();
    $guard->can('view', 'd1');
    $guard->can('view');
    $guard->filterQuery(warrantTestQuery('dk_docs'), 'view');

    expect(DkDocSchema::$asTextCalls)->toBe(1);
});

it('leaves the call in the expanded tree, around the expression it answered with', function () {
    $expanded = (new RuleSetExpander)->expand(
        WarrantSyntax::parse('if owned_or_tenant(@context tenant) they can view')->scopedTo('dk_docs'),
        new DkDocSchema,
    );

    $node = $expanded->rules[0]->conditions;

    expect($node)->toBeInstanceOf(DerivedConditionNode::class);
    expect($node->conditionKey)->toBe('owned_or_tenant');
    expect($node->parameters[0])->toBeInstanceOf(ContextRef::class);

    // Written back out, it reads as the rule wrote it, not as what it expanded to.
    expect(writeSyntax($expanded->rules[0]))->toContain('owned_or_tenant(@context tenant)');
    expect(writeSyntax($expanded->rules[0]))->not->toContain('in_tenant');
});

it('refuses to compile a derived condition nobody expanded', function () {
    $rule = WarrantSyntax::parse('if as_text they can view')->scopedTo('dk_docs')->entries[0];

    expect(fn () => (new RuleSetCompiler(new DkDocSchema))->compile(
        CompilationContext::ability(
            QueryFactory::for(DB::table('dk_docs')),
            makeWarrantTestUser('role-1'),
            'view',
            new ExpandedRuleSet('dk_docs', [$rule]),
        )->forTargetRow(),
    ))->toThrow(InvalidArgumentException::class, 'reached the compiler unexpanded');
});

// -- a row or global condition answers with SQL, a constant or unknown --------

it('rejects an expression from a row condition, pointing at #[DerivedCondition]', function () {
    expect(fn () => dkFilterSql('if returns_expression they can view'))
        ->toThrow(InvalidArgumentException::class, 'returned an expression; a row or global condition answers');

    expect(fn () => dkFilterSql('if returns_expression they can view'))
        ->toThrow(InvalidArgumentException::class, '#[DerivedCondition]');
});

// -- declaring one ------------------------------------------------------------

it('lists derived conditions among the schema\'s conditions', function () {
    expect(DkDocSchema::derivedConditionKeys())->toContain('owned_or_tenant', 'as_text', 'needs_two');
    expect(DkDocSchema::conditionKeys())->toContain('owned_or_tenant', 'is_owned');
    expect(DkDocSchema::rowConditionKeys())->not->toContain('owned_or_tenant');
});

it('counts every parameter as an argument, there being no context object', function () {
    $definition = (new DkDocSchema)->getConditionDefinition('needs_two');

    expect($definition->isDerived())->toBeTrue();
    expect($definition->requiredArgumentCount)->toBe(2);
});

it('rejects a derived condition that takes a context object', function () {
    expect(fn () => DkTakesContextSchema::conditionKeys())
        ->toThrow(InvalidArgumentException::class, 'takes no context object');
});

it('rejects a method that is both a derived and a row condition', function () {
    expect(fn () => DkTwoKindsSchema::conditionKeys())
        ->toThrow(InvalidArgumentException::class, 'must declare exactly one of');
});

it('rejects a method that is both a derived condition and a rule template', function () {
    expect(fn () => DkTemplateTooSchema::ruleTemplateKeys())
        ->toThrow(InvalidArgumentException::class, 'cannot be both a condition and a rule template');
});

// -- fixtures -----------------------------------------------------------------

class DkDoc extends Model
{
    use HasWarrantSchema;

    protected $table = 'dk_docs';
    public $incrementing = false;
    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return DkDocSchema::class;
    }
}

class DkDocSchema extends WarrantSchema
{
    public const model = DkDoc::class;

    public static int $asTextCalls = 0;

    public static mixed $lastTenant = null;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOwned(RowConditionContext $c): BuilderContract
    {
        return $c->query->where($c->row('owner_id'), '=', $c->user->role_id);
    }

    #[RowCondition]
    public function inTenant(RowConditionContext $c, mixed $tenant): BuilderContract
    {
        return $c->query->where($c->row('tenant_id'), '=', $tenant);
    }

    /** Hands its argument back through a binding without reading it. */
    #[DerivedCondition]
    public function ownedOrTenant(mixed $tenant): IBooleanExpressionNode
    {
        self::$lastTenant = $tenant;

        return WarrantSyntax::parse('is_owned or in_tenant(:tenant)', ['tenant' => $tenant])->conditionExpression();
    }

    /** Compares a column with the owner, through a binding, without reading it. */
    #[DerivedCondition]
    public function ownerMatches(mixed $column): IBooleanExpressionNode
    {
        return WarrantSyntax::parse('owner_is_column(:column)', ['column' => $column])->conditionExpression();
    }

    #[RowCondition]
    public function ownerIsColumn(RowConditionContext $c, mixed $column): BuilderContract
    {
        return $c->query->where($c->row('owner_id'), '=', $column);
    }

    #[DerivedCondition]
    public function asText(): string
    {
        self::$asTextCalls++;

        return 'is_owned';
    }

    #[DerivedCondition]
    public function yes(): bool
    {
        return true;
    }

    #[DerivedCondition]
    public function no(): bool
    {
        return false;
    }

    #[DerivedCondition]
    public function unanswerable(): null
    {
        return null;
    }

    #[DerivedCondition]
    public function badAnswer(): array
    {
        return [];
    }

    #[DerivedCondition]
    public function needsTwo(mixed $a, mixed $b): bool
    {
        return true;
    }

    /** The old contract: a row condition answering with an expression. */
    #[RowCondition]
    public function returnsExpression(RowConditionContext $c): IBooleanExpressionNode
    {
        return WarrantSyntax::parse('is_owned')->conditionExpression();
    }
}

class DkTakesContextSchema extends WarrantSchema
{
    #[DerivedCondition]
    public function readsTheRow(RowConditionContext $c): string
    {
        return 'is_owned';
    }
}

class DkTwoKindsSchema extends WarrantSchema
{
    #[DerivedCondition]
    #[RowCondition]
    public function both(RowConditionContext $c): string
    {
        return 'is_owned';
    }
}

class DkTemplateTooSchema extends WarrantSchema
{
    #[DerivedCondition]
    #[RuleTemplate]
    public function both(): string
    {
        return 'is_owned';
    }
}
