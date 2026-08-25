<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Warrant\Ability;
use Warrant\GlobalCondition;
use Warrant\RowCondition;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Schema\WarrantSchema;

/**
 * Reference validation for the cross-schema `check(<predicate> for <schema>[(<row>)])`
 * builtin: the target schema must exist and may not be the owner; a row-bound
 * reference needs a model-backed target with a non-null row; and every leaf of the
 * predicate must be a condition declared by the *target* schema, with no row
 * condition allowed on an unbound handle. The emitted SQL is out of scope here.
 */
beforeEach(function () {
    useWarrantSchemas([
        XcvOwnerSchema::class,
        XcvTargetSchema::class,
        XcvCapabilitySchema::class,
    ]);
});

function validateOwnerCheckSyntax(string $syntax): void
{
    WarrantRuleSet::fromSyntax($syntax, 'xcv_owner')->validate();
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

it('rejects a reference to its own schema', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_owner_open for xcv_owner) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'cannot target its own schema [xcv_owner]');
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

it('rejects a can(...) leaf inside a check(...) predicate', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(can(view for xcv_target) for xcv_target(@context id)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'may only reference that schema\'s conditions');
});

it('rejects a row condition on an unbound handle', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_published for xcv_target) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_published] on schema [xcv_target] is a row condition and needs a specific row');
});

it('rejects a row condition on an unbound handle even when nested', function () {
    expect(fn () => validateOwnerCheckSyntax('if check(is_open or is_published for xcv_target) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Condition [is_published] on schema [xcv_target] is a row condition and needs a specific row');
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

// -- fixtures -----------------------------------------------------------------

class XcvOwnerModel extends Model
{
    protected $table = 'xcv_owners';

    public $incrementing = false;

    protected $keyType = 'string';
}

class XcvOwnerSchema extends WarrantSchema
{
    public const schemaKey = 'xcv_owner';

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
    protected $table = 'xcv_targets';

    public $incrementing = false;

    protected $keyType = 'string';
}

class XcvTargetSchema extends WarrantSchema
{
    public const schemaKey = 'xcv_target';

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
    public const schemaKey = 'xcv_capability';

    #[Ability]
    public const A_ACCESS = 'access';

    #[GlobalCondition]
    public function isOpen(GlobalConditionContext $c): bool
    {
        return true;
    }
}
