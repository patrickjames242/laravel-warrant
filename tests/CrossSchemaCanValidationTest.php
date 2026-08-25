<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Database\Eloquent\Model;
use Warrant\Ability;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\WarrantSchema;

/**
 * Validation for the cross-schema `can(<ability> for <schema>[(<row>)])` builtin.
 * Only reference validation is exercised here — the target schema/ability must
 * exist, a schema may not reference itself, and a row-bound reference needs a
 * model-backed target. Cycle detection is intentionally out of scope (it lives in
 * the compiler, since a schema's rules are per-user).
 */
beforeEach(function () {
    useWarrantSchemas([
        XsOwnerSchema::class,
        XsTargetSchema::class,
        XsCapabilitySchema::class,
    ]);
});

function validateOwnerSyntax(string $syntax): void
{
    WarrantRuleSet::fromSyntax('xs_owner', $syntax)->validate();
}

it('accepts an unbound reference to a capability (no-model) schema', function () {
    validateOwnerSyntax('if can(access for xs_capability) they can edit');
    expect(true)->toBeTrue();
});

it('accepts an unbound reference to a model-backed schema (no-target check)', function () {
    validateOwnerSyntax('if can(manage for xs_target) they can edit');
    expect(true)->toBeTrue();
});

it('accepts a row-bound reference to a model-backed schema', function () {
    validateOwnerSyntax('if can(manage for xs_target(@context id)) they can edit');
    expect(true)->toBeTrue();
});

it('rejects a reference to its own schema', function () {
    expect(fn () => validateOwnerSyntax('if can(view for xs_owner) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'cannot target its own schema [xs_owner]');
});

it('rejects a reference to its own schema even when row-bound', function () {
    expect(fn () => validateOwnerSyntax('if can(view for xs_owner(@context id)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'cannot target its own schema [xs_owner]');
});

it('rejects an unknown target schema', function () {
    expect(fn () => validateOwnerSyntax('if can(view for nope_schema) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'unknown schema [nope_schema]');
});

it('rejects an ability not declared by the target schema', function () {
    expect(fn () => validateOwnerSyntax('if can(fly for xs_target) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly] is not declared by schema [xs_target]');
});

it('rejects a row-bound reference to a capability schema (no row to target)', function () {
    expect(fn () => validateOwnerSyntax('if can(access for xs_capability(@context id)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'has no model and cannot be row-targeted');
});

it('rejects a specified row target that is a null literal', function () {
    expect(fn () => validateOwnerSyntax('if can(manage for xs_target(null)) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

it('rejects a specified row target from a binding that resolved to null', function () {
    expect(fn () => WarrantRuleSet::fromSyntax(
        'xs_owner',
        'if can(manage for xs_target(:folder)) they can edit',
        ['folder' => null],
    )->validate())
        ->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

it('validates a can reference nested inside a boolean expression', function () {
    expect(fn () => validateOwnerSyntax('if not can(fly for xs_target) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly] is not declared by schema [xs_target]');

    expect(fn () => validateOwnerSyntax('if can(fly for xs_target) or can(access for xs_capability) they can edit'))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly] is not declared by schema [xs_target]');
});

// -- fixtures -----------------------------------------------------------------

class XsOwnerModel extends Model
{
    protected $table = 'xs_owners';

    public $incrementing = false;

    protected $keyType = 'string';
}

class XsOwnerSchema extends WarrantSchema
{
    public const schemaKey = 'xs_owner';

    public const model = XsOwnerModel::class;

    #[Ability]
    public const A_EDIT = 'edit';

    #[Ability]
    public const A_VIEW = 'view';
}

class XsTargetModel extends Model
{
    protected $table = 'xs_targets';

    public $incrementing = false;

    protected $keyType = 'string';
}

class XsTargetSchema extends WarrantSchema
{
    public const schemaKey = 'xs_target';

    public const model = XsTargetModel::class;

    #[Ability]
    public const A_MANAGE = 'manage';

    #[Ability]
    public const A_VIEW = 'view';
}

class XsCapabilitySchema extends WarrantSchema
{
    public const schemaKey = 'xs_capability';

    #[Ability]
    public const A_ACCESS = 'access';
}
