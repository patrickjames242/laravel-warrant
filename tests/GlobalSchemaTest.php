<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Resolution\ConstructLookup;
use Warrant\DSL\Resolution\GlobalSchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\Facades\Warrant;
use Warrant\Registry\SchemaRegistry;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\GlobalSchema;
use Warrant\Schema\Types\Classes\ModelSchema;

final class GlobalTenancySchema extends GlobalSchema
{
    #[GlobalCondition]
    public function inCurrentTenant(GlobalConditionContext $c): bool
    {
        return ($c->context['tenant_id'] ?? null) === 't1';
    }

    #[GlobalCondition]
    public function isStaff(GlobalConditionContext $c): bool
    {
        return $c->user->role_id === 'staff';
    }
}

final class GlobalAuditSchema extends GlobalSchema
{
    #[GlobalCondition]
    public function isStaff(GlobalConditionContext $c): bool
    {
        return $c->user->role_id === 'auditor';
    }

    #[DerivedCondition]
    public function isTenantStaff(): string
    {
        return 'is_staff and in_current_tenant';
    }

    #[RuleTemplate]
    public function staffOnly(): string
    {
        return 'if is_tenant_staff they can';
    }

    #[DerivedCondition]
    public function isAuditedRow(): string
    {
        return 'is_audited';
    }

    #[DerivedCondition]
    public function isStaffUnderHeader(): string
    {
        return 'for global_docs { is_staff }';
    }

    #[RuleTemplate]
    public function staffOnlyUnderHeader(): string
    {
        return 'for global_docs { if is_staff they can }';
    }
}

class GlobalDoc extends Model
{
    use \Warrant\HasWarrantSchema;

    protected $table = 'global_docs';

    public static function warrantSchema(): string
    {
        return GlobalDocSchema::class;
    }
}

final class GlobalDocSchema extends ModelSchema
{
    public const model = GlobalDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[RowCondition]
    public function isStaff(RowConditionContext $c): Builder
    {
        return $c->query->where($c->column('staff_id'), $c->user->getAuthIdentifier());
    }

    #[RowCondition]
    public function isAudited(RowConditionContext $c): Builder
    {
        return $c->query->whereNotNull($c->column('audited_at'));
    }

    public function rules(RuleProviderContext $context): string
    {
        return <<<'WARRANT'
            if is_staff they can edit
            @include staff_only for view
        WARRANT;
    }
}

beforeEach(function () {
    config()->set('warrant.global', [GlobalTenancySchema::class, GlobalAuditSchema::class]);
    useWarrantSchemas(['global_docs' => GlobalDocSchema::class]);
});

function globalDocsSql(string $role, string $ability): string
{
    return Warrant::guard(makeWarrantTestUser($role))
        ->forSchema(GlobalDocSchema::class)
        ->filterQuery(warrantTestQuery('global_docs'), $ability, context: ['tenant_id' => 't1'])
        ->toSql();
}

it('resolves a name written in a schema to the schema\'s own construct first', function () {
    $found = (new ConstructLookup(Warrant::registry()))->condition('is_staff', new KeyedSchemaId('global_docs'));

    expect($found->declaredIn())->toEqual(new KeyedSchemaId('global_docs'))
        ->and($found->schemaClass())->toBe(GlobalDocSchema::class)
        ->and($found->definition->isRow())->toBeTrue();
});

it('resolves a name a schema does not declare to the first global that does', function () {
    $found = (new ConstructLookup(Warrant::registry()))->ruleTemplate('staff_only', new KeyedSchemaId('global_docs'));

    expect($found->declaredIn())->toEqual(new GlobalSchemaId(GlobalAuditSchema::class))
        ->and($found->schemaClass())->toBe(GlobalAuditSchema::class);
});

it('resolves a name written in a global among the globals in order, its own included in no priority', function () {
    $found = (new ConstructLookup(Warrant::registry()))->condition('is_staff', new GlobalSchemaId(GlobalAuditSchema::class));

    expect($found->declaredIn())->toEqual(new GlobalSchemaId(GlobalTenancySchema::class))
        ->and($found->schemaClass())->toBe(GlobalTenancySchema::class)
        ->and($found->definition->isGlobal())->toBeTrue();
});

it('compiles a schema\'s own condition where it hides a global\'s', function () {
    expect(globalDocsSql('staff', 'edit'))->toContain('"global_docs"."staff_id" = ?');
});

it('compiles a global template\'s conditions against the globals, in order', function () {
    // TenancySchema::isStaff answers for `is_staff`, not the checked schema's row condition.
    expect(globalDocsSql('staff', 'view'))->not->toContain('staff_id')
        ->and(globalDocsSql('staff', 'view'))->toContain('1 = 1');

    // AuditSchema::isStaff, listed second, never answers.
    expect(globalDocsSql('auditor', 'view'))->toContain('1 = 0');
});

it('refuses a class listed both as a schema and a global', function () {
    expect(fn () => new SchemaRegistry(['tenancy' => GlobalTenancySchema::class], [GlobalTenancySchema::class]))
        ->toThrow(InvalidArgumentException::class, 'is listed more than once across `schemas` and `global`');
});

it('refuses a global schema registered under a key', function () {
    config()->set('warrant.global', []);
    useWarrantSchemas(['tenancy' => GlobalTenancySchema::class]);

    expect(fn () => Warrant::registry()->resolveOrFail('tenancy'))
        ->toThrow(LogicException::class, 'which is a global schema; list it under `global`');
});

it('refuses to name a class that is not a global schema as one', function () {
    expect(fn () => new GlobalSchemaId(GlobalDocSchema::class))
        ->toThrow(InvalidArgumentException::class, 'Schema [GlobalDocSchema] does not implement');
});

it('says a name written in a schema is declared by neither it nor any global', function () {
    $ruleSet = WarrantSyntax::parse('if is_nowhere they can view')->validateWithoutSchemas()->scopedTo('global_docs');

    expect(fn () => validateRuleSet($ruleSet, new GlobalDocSchema))
        ->toThrow(RuleValidationException::class, 'Condition [is_nowhere] is declared by neither schema [global_docs] nor any global schema.');
});

it('says a name a global answered with is declared by no global, though the schema reaching it declares one', function () {
    // GlobalDocSchema declares is_audited, but the global's text never looks in it.
    $ruleSet = WarrantSyntax::parse('if is_audited_row they can view')->validateWithoutSchemas()->scopedTo('global_docs');

    expect(fn () => expandedFor($ruleSet, new GlobalDocSchema))
        ->toThrow(RuleValidationException::class, 'Condition [is_audited] is declared by no global schema; the text global schema ['.GlobalAuditSchema::class.'] answers with looks only in the global schemas.');
});

it('refuses a global\'s derived condition answering under a for header, even one naming the schema that reached it', function () {
    $ruleSet = WarrantSyntax::parse('if is_staff_under_header they can view')->validateWithoutSchemas()->scopedTo('global_docs');

    expect(fn () => expandedFor($ruleSet, new GlobalDocSchema))
        ->toThrow(RuleValidationException::class, 'answered under a `for` header naming schema [global_docs]; a global schema answers with no header');
});

it('refuses a global\'s rule template answering under a for header', function () {
    $ruleSet = WarrantSyntax::parse('@include staff_only_under_header for view')->validateWithoutSchemas()->scopedTo('global_docs');

    expect(fn () => expandedFor($ruleSet, new GlobalDocSchema))
        ->toThrow(RuleValidationException::class, 'Rule template [GlobalAuditSchema::staffOnlyUnderHeader] answered under a `for` header naming schema [global_docs]; a global schema answers with no header');
});
