<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Database\Eloquent\Model;
use Warrant\HasWarrantSchema;
use Warrant\Builders\Ref;
use Warrant\Builders\WarrantRuleBuilder;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Schema\Ability;
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
        'xs_owner' => XsOwnerSchema::class,
        'xs_target' => XsTargetSchema::class,
        'xs_capability' => XsCapabilitySchema::class,
    ]);
});

function validateOwnerSyntax(string $syntax): void
{
    WarrantRuleSet::fromSyntax($syntax, 'xs_owner')->validate();
}

function validateOwnerRule(WarrantRuleBuilder $rule): void
{
    WarrantRuleSet::fromRules('xs_owner', $rule)->validate();
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

it('accepts a reference to its own schema, unbound or row-bound', function (string $syntax) {
    /* Once a hop's rows can be named, a schema referencing itself is expressible
       — and it is the natural way to say "you may do this if you may do that".
       Whether such a reference *terminates* is about rules this validator cannot
       see, so it is left to the compiler's cycle guard. */
    validateOwnerSyntax($syntax);
    expect(true)->toBeTrue();
})->with([
    'unbound' => 'if can(view for xs_owner) they can edit',
    'row-bound' => 'if can(view for xs_owner(@context id)) they can edit',
    'row-bound and aliased' => 'if can(view for xs_owner(@context id) as o2) they can edit',
]);

it('accepts a can with no for clause naming a declared ability', function () {
    validateOwnerSyntax('if can(edit) they can view');
    expect(true)->toBeTrue();
});

it('rejects a can with no for clause naming an ability this schema does not declare', function () {
    expect(fn () => validateOwnerSyntax('if can(fly) they can view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [fly] is not declared by the schema.');
});

it('rejects a with map on a can that crosses no boundary', function () {
    // Nothing is crossed, so the context is already there and cannot be handed over.
    expect(fn () => validateOwnerSyntax('if can(edit with tenant = 7) they can view'))
        ->toThrow(
            InvalidArgumentException::class,
            'A can(edit) reference carries the context it is already in, so it takes no `with` map',
        );
});

it('rejects an alias on a can that selects no rows of its own', function () {
    expect(fn () => validateOwnerRule(WarrantRule::build()->ifCan('edit', as: 'e2')->theyCan('view')))
        ->toThrow(
            InvalidArgumentException::class,
            'A can(edit) reference selects no rows of its own, so there is nothing for [as e2] to name',
        );
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
        'if can(manage for xs_target(:folder)) they can edit',
        'xs_owner',
        ['folder' => null],
    )->validate())
        ->toThrow(InvalidArgumentException::class, 'specifies a row target that is null');
});

// -- built references validate identically ------------------------------------

it('accepts a builder-built unbound reference to a capability schema', function () {
    validateOwnerRule(WarrantRule::build()->ifCan('access', 'xs_capability')->theyCan('edit'));
    expect(true)->toBeTrue();
});

it('accepts a builder-built row-bound reference', function () {
    validateOwnerRule(WarrantRule::build()->ifCan('manage', 'xs_target', Ref::context('id'))->theyCan('edit'));
    expect(true)->toBeTrue();
});

it('accepts a builder-built reference to its own schema', function () {
    validateOwnerRule(WarrantRule::build()->ifCan('view', 'xs_owner')->theyCan('edit'));
    expect(true)->toBeTrue();
});

it('rejects a builder-built row-bound reference with an explicit null row', function () {
    // What NoRow buys: omitting the selector asks a schema-wide question, while a
    // null id stays row-bound and fails here instead of silently widening.
    expect(fn () => validateOwnerRule(WarrantRule::build()->ifCan('manage', 'xs_target', null)->theyCan('edit')))
        ->toThrow(InvalidArgumentException::class, 'row target that is null');
});

it('rejects a builder-built row-bound reference to a capability schema', function () {
    expect(fn () => validateOwnerRule(WarrantRule::build()->ifCan('access', 'xs_capability', Ref::context('id'))->theyCan('edit')))
        ->toThrow(InvalidArgumentException::class, 'has no model and cannot be row-targeted');
});

it('rejects a builder-built ability the target schema does not declare', function () {
    expect(fn () => validateOwnerRule(WarrantRule::build()->ifCan('nope', 'xs_target')->theyCan('edit')))
        ->toThrow(InvalidArgumentException::class, 'is not declared by schema [xs_target]');
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
    use HasWarrantSchema;

    protected $table = 'xs_owners';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return XsOwnerSchema::class;
    }
}

class XsOwnerSchema extends WarrantSchema
{

    public const model = XsOwnerModel::class;

    #[Ability]
    public const A_EDIT = 'edit';

    #[Ability]
    public const A_VIEW = 'view';
}

class XsTargetModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'xs_targets';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return XsTargetSchema::class;
    }
}

class XsTargetSchema extends WarrantSchema
{

    public const model = XsTargetModel::class;

    #[Ability]
    public const A_MANAGE = 'manage';

    #[Ability]
    public const A_VIEW = 'view';
}

class XsCapabilitySchema extends WarrantSchema
{

    #[Ability]
    public const A_ACCESS = 'access';
}
