<?php

use Warrant\DSL\Resolution\GlobalSchemaId;
use Warrant\DSL\Resolution\KeyedSchemaId;
use Warrant\Registry\SchemaRegistry;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Constants\Constant;
use Warrant\Schema\Constants\ConstantName;
use Warrant\Schema\Types\Classes\GlobalSchema;
use Warrant\Schema\Types\Classes\RowlessSchema;
use Warrant\Schema\Vocabulary;

enum SchemaConstantStatus: string
{
    case Open = 'open';
}

final class SchemaConstantPayrollSchema extends RowlessSchema
{
    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[Constant]
    public const PAYROLL_MODULE = 'payroll';

    #[Constant]
    public const EDITORS = [self::VIEW, self::EDIT];

    #[Constant]
    public const STATUS = SchemaConstantStatus::Open;

    #[Constant('cutoff')]
    public const CUTOFF_RATIO = 0.5;

    #[Constant]
    public const SHARED = 'payroll';

    public const UNMARKED = 'unmarked';
}

final class SchemaConstantModulesSchema extends GlobalSchema
{
    #[Constant]
    public const SHARED = 'global';

    #[Constant]
    public const REPORTS_MODULE = 'reports';
}

final class SchemaConstantOtherModulesSchema extends GlobalSchema
{
    #[Constant]
    public const ARCHIVE_MODULE = 'archive';
}

final class SchemaConstantDuplicateModulesSchema extends GlobalSchema
{
    #[Constant]
    public const REPORTS_MODULE = 'reports_again';
}

final class SchemaConstantReservedSchema extends RowlessSchema
{
    #[Constant]
    public const IF = 'if';
}

final class SchemaConstantReservedExplicitSchema extends RowlessSchema
{
    #[Constant('null')]
    public const NOTHING = 'nothing';
}

final class SchemaConstantReservedAbilitySchema extends RowlessSchema
{
    #[Ability]
    public const TRUE = 'true';
}

final class SchemaConstantTwiceSchema extends RowlessSchema
{
    #[Constant]
    public const PAYROLL_MODULE = 'payroll';

    #[Constant]
    public const payrollModule = 'payroll_too';
}

final class SchemaConstantAbilityNameSchema extends RowlessSchema
{
    #[Ability]
    public const VIEW = 'view';

    #[Constant]
    public const VIEW_NAME = 'view_name';

    #[Constant('view')]
    public const SEE = 'see';
}

final class SchemaConstantBothSchema extends RowlessSchema
{
    #[Ability]
    #[Constant]
    public const VIEW = 'view';
}

function schemaConstantRegistry(array $globals = [SchemaConstantModulesSchema::class, SchemaConstantOtherModulesSchema::class]): SchemaRegistry
{
    return new SchemaRegistry(['payroll' => SchemaConstantPayrollSchema::class], $globals);
}

it('names a constant by its PHP name in lowercase snake_case, or by the name it is given', function () {
    expect(ConstantName::of('PAYROLL_MODULE'))->toBe('payroll_module')
        ->and(ConstantName::of('payrollModule'))->toBe('payroll_module')
        ->and(ConstantName::of('PayrollModule'))->toBe('payroll_module')
        ->and(ConstantName::of('MODULE2'))->toBe('module2')
        ->and(ConstantName::of('PAYROLL_MODULE', 'Payroll-Module'))->toBe('Payroll-Module');
});

it('reads a schema\'s #[Constant]s, holding whatever PHP holds, and none it did not mark', function () {
    $vocabulary = Vocabulary::of(new SchemaConstantPayrollSchema);

    expect($vocabulary->constant('payroll_module')?->value)->toBe('payroll')
        ->and($vocabulary->constant('editors')?->value)->toBe(['view', 'edit'])
        ->and($vocabulary->constant('status')?->value)->toBe(SchemaConstantStatus::Open)
        ->and($vocabulary->constant('cutoff')?->value)->toBe(0.5)
        ->and($vocabulary->constant('cutoff_ratio'))->toBeNull()
        ->and($vocabulary->constant('unmarked'))->toBeNull();
});

it('reads every ability as a constant, named by its value and holding it', function () {
    $view = Vocabulary::of(new SchemaConstantPayrollSchema)->constant('view');

    expect($view?->name)->toBe('view')
        ->and($view?->value)->toBe('view')
        ->and($view?->valueIsKnown)->toBeTrue()
        ->and(Vocabulary::of(new SchemaConstantPayrollSchema)->constantNames())
        ->toBe(['payroll_module', 'editors', 'status', 'cutoff', 'shared', 'view', 'edit']);
});

it('refuses a constant named as a keyword or a literal, whether its name is converted or given', function () {
    expect(fn () => new SchemaConstantReservedSchema)
        ->toThrow(InvalidArgumentException::class, 'is named [if], which rule text reads as a keyword or a literal')
        ->and(fn () => new SchemaConstantReservedExplicitSchema)
        ->toThrow(InvalidArgumentException::class, 'is named [null], which rule text reads as a keyword or a literal');
});

it('refuses an ability named as a keyword or a literal', function () {
    expect(fn () => new SchemaConstantReservedAbilitySchema)
        ->toThrow(InvalidArgumentException::class, 'declares ability [true], which rule text reads as a keyword or a literal');
});

it('refuses two constants of one schema with the same name', function () {
    expect(fn () => new SchemaConstantTwiceSchema)
        ->toThrow(InvalidArgumentException::class, 'declares constant [payroll_module] more than once');
});

it('refuses a constant named as one of the schema\'s abilities', function () {
    expect(fn () => new SchemaConstantAbilityNameSchema)
        ->toThrow(InvalidArgumentException::class, 'declares constant [view] and an ability of the same name');
});

it('refuses a constant marked both an ability and a #[Constant]', function () {
    expect(fn () => new SchemaConstantBothSchema)
        ->toThrow(InvalidArgumentException::class, 'cannot be both an ability and a #[Constant]');
});

it('looks a bare constant up in the schema it is written in, then in the globals in order', function () {
    $lookup = schemaConstantRegistry()->constructs();
    $payroll = new KeyedSchemaId('payroll');

    expect($lookup->constant('shared', $payroll)?->definition->value)->toBe('payroll')
        ->and($lookup->constant('shared', $payroll)?->schemaClass())->toBe(SchemaConstantPayrollSchema::class)
        ->and($lookup->constant('edit', $payroll)?->definition->value)->toBe('edit')
        ->and($lookup->constant('reports_module', $payroll)?->schemaClass())->toBe(SchemaConstantModulesSchema::class)
        ->and($lookup->constant('archive_module', $payroll)?->schemaClass())->toBe(SchemaConstantOtherModulesSchema::class)
        ->and($lookup->constant('nowhere', $payroll))->toBeNull();
});

it('looks a bare constant written in a global up in the globals alone', function () {
    $registry = schemaConstantRegistry();
    $writtenInGlobal = new GlobalSchemaId(SchemaConstantOtherModulesSchema::class, $registry);

    expect($registry->constructs()->constant('shared', $writtenInGlobal)?->definition->value)->toBe('global')
        ->and($registry->constructs()->constant('payroll_module', $writtenInGlobal))->toBeNull();
});

it('looks a prefixed constant up in the named schema alone', function () {
    $lookup = schemaConstantRegistry()->constructs();

    expect($lookup->constantDeclaredBy('payroll', 'payroll_module')?->definition->value)->toBe('payroll')
        ->and($lookup->constantDeclaredBy('payroll', 'view')?->definition->value)->toBe('view')
        ->and($lookup->constantDeclaredBy('payroll', 'payroll_module')?->declaredIn())->toEqual(new KeyedSchemaId('payroll'));
});

it('finds no prefixed constant that only a global declares', function () {
    expect(schemaConstantRegistry()->constructs()->constantDeclaredBy('payroll', 'reports_module'))->toBeNull();
});

it('finds no prefixed constant under a key no schema is registered under', function () {
    expect(schemaConstantRegistry()->constructs()->constantDeclaredBy('nowhere', 'payroll_module'))->toBeNull();
});

it('refuses two global schemas declaring the same constant', function () {
    $registry = schemaConstantRegistry([SchemaConstantModulesSchema::class, SchemaConstantDuplicateModulesSchema::class]);

    expect(fn () => $registry->constructs()->constant('anything', new KeyedSchemaId('payroll')))
        ->toThrow(LogicException::class, 'both declare constant [reports_module]');
});
