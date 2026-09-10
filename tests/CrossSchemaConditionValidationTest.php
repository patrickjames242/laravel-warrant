<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Warrant\HasWarrantSchema;
use Warrant\Builders\Ref;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\GlobalCondition;
use Warrant\Schema\RowCondition;
use Warrant\Schema\WarrantSchema;

/**
 * Reference validation for the cross-schema `check(<predicate> for <schema>[(<row>)])`
 * builtin: the target schema must exist and may not be the owner; a row-bound
 * reference needs a model-backed target with a non-null row; and every leaf of the
 * predicate must be a condition declared by the *target* schema. Whether a leaf
 * is a row condition is not a validation concern — one with no row answers
 * unknown. The emitted SQL is out of scope here.
 */
beforeEach(function () {
    useWarrantSchemas([
        'xcv_owner' => XcvOwnerSchema::class,
        'xcv_target' => XcvTargetSchema::class,
        'xcv_capability' => XcvCapabilitySchema::class,
    ]);
});

function validateOwnerCheckSyntax(string $syntax): void
{
    WarrantRuleSet::fromSyntax($syntax, 'xcv_owner')->validate();
}

function validateOwnerCheckRule(WarrantRuleBuilder $rule): void
{
    WarrantRuleSet::fromRules('xcv_owner', $rule)->validate();
}

it('accepts an unbound reference with a global condition', function () {
    validateOwnerCheckSyntax('if check(is_open for xcv_capability) they can edit');
    expect(true)->toBeTrue();
});

it('accepts a row-bound reference with a row condition', function () {
    validateOwnerCheckSyntax('if check(is_published for xcv_target(@context id)) they can edit');
    expect(true)->toBeTrue();
});

it('accepts a row-bound reference mixing a global and row condition', function () {
    validateOwnerCheckSyntax('if check(is_open or (is_published and not is_frozen) for xcv_target(@context id)) they can edit');
    expect(true)->toBeTrue();
});

it('accepts a global condition on a row-bound handle', function () {
    validateOwnerCheckSyntax('if check(is_open for xcv_target(@context id)) they can edit');
    expect(true)->toBeTrue();
});

it('accepts a reference to its own schema', function () {
    /* A check(...) never reads the target's rules, so a self-reference here has
       no recursion to bound at all — it just asks this schema's own conditions
       about another of its rows. */
    validateOwnerCheckSyntax('if check(is_owner_open for xcv_owner) they can edit');
    expect(true)->toBeTrue();
});

it('rejects an unknown target schema', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_open for nope_schema) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'unknown schema [nope_schema]');
});

it('rejects a condition not declared by the target schema', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_bogus for xcv_target) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_bogus] is not declared by schema [xcv_target]');
});

it('rejects an unknown condition nested inside a boolean predicate', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_open or (is_frozen and is_bogus) for xcv_target(@context id)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_bogus] is not declared by schema [xcv_target]');
});

it('accepts a can(...) leaf inside a check(...) predicate', function () {
    validateOwnerCheckSyntax('if check(can(view for xcv_target) for xcv_target(@context id)) they can edit');
    expect(true)->toBeTrue();
});

it('accepts a schema-less can(...) inside a predicate, read against the handle\'s schema', function () {
    /* The predicate is about xcv_target's row, so a `can` naming no schema asks
       about xcv_target's ability — not the owning schema's. */
    validateOwnerCheckSyntax('if check(is_open and can(view) for xcv_target(@context id)) they can edit');
    expect(true)->toBeTrue();
});

it('rejects a schema-less can(...) in a predicate naming an ability the handle\'s schema lacks', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(can(edit) for xcv_target(@context id)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Ability [edit] is not declared by the schema.');
});

it('accepts a nested check(...) inside a check(...) predicate', function () {
    validateOwnerCheckSyntax(
        'if check(is_open and check(is_open for xcv_target(@context id)) for xcv_target(@context id)) they can edit'
    );
    expect(true)->toBeTrue();
});

it('rejects a constant inside a check(...) predicate', function () {
    /* A predicate that decides itself asks the target nothing. The builder refuses
       to compose one, so this reaches the validator only as a hand-built node. */
    $node = new CrossSchemaConditionNode('xcv_target', new BooleanNode(true), true, [Ref::context('id')]);

    expect(fn () => WarrantRuleSet::fromRules('xcv_owner', new WarrantRule($node, ['edit'], []))->validate())
        ->toThrow(InvalidArgumentException::class, 'may not contain a constant');
});

it('accepts a row condition on an unbound handle, leaving it to answer unknown', function () {
    /* A row condition with no row has an undefined answer, and the compiler gives
       it one. Rejecting it here would require the author to know that
       is_published reads a column while is_open does not. */
    validateOwnerCheckSyntax('if check(is_published for xcv_target) they can edit');
    expect(true)->toBeTrue();
});

it('accepts a predicate mixing a global and a row condition on an unbound handle', function () {
    /* The case that settles it: is_open can answer, so rejecting the whole rule
       over the is_published leaf would throw away a predicate that works. */
    validateOwnerCheckSyntax('if check(is_open or is_published for xcv_target) they can edit');
    expect(true)->toBeTrue();
});

it('rejects a row-bound reference to a capability (no-model) schema', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_open for xcv_capability(@context id)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'has no model and cannot be row-targeted');
});

it('rejects a specified row target that is a null literal', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_published for xcv_target(null)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

it('rejects a specified row target from a binding that resolved to null', function () {
    expect(fn () => WarrantRuleSet::fromSyntax(
        'if check(is_published for xcv_target(:pp)) they can edit',
        'xcv_owner',
        ['pp' => null],
    )->validate())
        ->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

// -- built references validate identically ------------------------------------

it('accepts a builder-built unbound reference with a global condition', function () {
    validateOwnerCheckRule(WarrantRule::build()->ifCheck('is_open', 'xcv_capability')->theyCan('edit'));
    expect(true)->toBeTrue();
});

it('accepts a builder-built row-bound reference with a row condition', function () {
    validateOwnerCheckRule(
        WarrantRule::build()->ifCheck('is_published', 'xcv_target', Ref::context('id'))->theyCan('edit')
    );
    expect(true)->toBeTrue();
});

it('rejects a builder-built predicate leaf the target does not declare', function () {
    expect(fn () => validateOwnerCheckRule(WarrantRule::build()->ifCheck('is_bogus', 'xcv_target')->theyCan('edit')))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_bogus] is not declared by schema [xcv_target]');
});

it('accepts a builder-built row condition on an unbound handle', function () {
    validateOwnerCheckRule(
        WarrantRule::build()->ifCheck(fn ($p) => $p->if('is_open')->orIf('is_published'), 'xcv_target')->theyCan('edit')
    );
    expect(true)->toBeTrue();
});

it('accepts a nested can(...) inside a builder-built predicate', function () {
    validateOwnerCheckRule(
        WarrantRule::build()->ifCheck(fn ($p) => $p->ifCan('view', 'xcv_target'), 'xcv_target', Ref::context('id'))->theyCan('edit')
    );
    expect(true)->toBeTrue();
});

it('rejects a builder-built row-bound reference with an explicit null row', function () {
    expect(fn () => validateOwnerCheckRule(
        WarrantRule::build()->ifCheck('is_published', 'xcv_target', null)->theyCan('edit')
    ))->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

// -- fixtures -----------------------------------------------------------------

class XcvOwnerModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'xcv_owners';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return XcvOwnerSchema::class;
    }
}

class XcvOwnerSchema extends WarrantSchema
{

    public const model = XcvOwnerModel::class;

    #[Ability]
    public const A_EDIT = 'edit';

    #[GlobalCondition]
    public function isOwnerOpen(GlobalConditionContext $c): bool
    {
        return true;
    }
}

class XcvTargetModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'xcv_targets';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return XcvTargetSchema::class;
    }
}

class XcvTargetSchema extends WarrantSchema
{

    public const model = XcvTargetModel::class;

    #[Ability]
    public const A_VIEW = 'view';

    #[GlobalCondition]
    public function isOpen(GlobalConditionContext $c): bool
    {
        return true;
    }

    #[RowCondition]
    public function isPublished(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('published')} = ?", [true]);
    }

    #[RowCondition]
    public function isFrozen(RowConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->row('frozen')} = ?", [true]);
    }
}

class XcvCapabilitySchema extends WarrantSchema
{

    #[Ability]
    public const A_ACCESS = 'access';

    #[GlobalCondition]
    public function isOpen(GlobalConditionContext $c): bool
    {
        return true;
    }
}
