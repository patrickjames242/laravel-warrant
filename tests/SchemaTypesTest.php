<?php

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\ConditionDefinition;
use Warrant\Schema\Conditions\ConditionKind;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Context\RequiredContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\RuleTemplates\RuleTemplateContext;
use Warrant\Schema\Types\Classes\GlobalSchema;
use Warrant\Schema\Types\Classes\ModelSchema;
use Warrant\Schema\Types\Classes\QuerySchema;
use Warrant\Schema\Types\Classes\RowlessSchema;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;
use Warrant\Schema\Types\Interfaces\IGlobalSchema;
use Warrant\Schema\Types\Interfaces\IModelSchema;
use Warrant\Schema\Types\Interfaces\IRowSchema;
use Warrant\Schema\Vocabulary;

class SchemaTypesDocument extends Model
{
    protected $table = 'documents';
}

final class SchemaTypesTenancySchema extends GlobalSchema
{
    #[GlobalCondition]
    public function inTenant(GlobalConditionContext $c): bool
    {
        return true;
    }

    #[DerivedCondition]
    public function inTenantOrAdmin(): string
    {
        return 'in_tenant or is_admin';
    }

    #[RuleTemplate(callOncePerAbility: true)]
    public function ownerRules(RuleTemplateContext $c, string $column): string
    {
        return "if mine({$column}) they can";
    }
}

final class SchemaTypesBillingSchema extends RowlessSchema
{
    #[Ability(requiredContext: ['tenant'])]
    public const EXPORT = 'export';

    #[RequiredContext]
    public const TENANT = 'tenant';

    #[GlobalCondition]
    public function isFinance(GlobalConditionContext $c, string $department): bool
    {
        return $department === 'finance';
    }
}

final class SchemaTypesDocumentSchema extends ModelSchema
{
    public const model = SchemaTypesDocument::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function isOwner(RowConditionContext $c): Builder
    {
        return $c->query->where($c->column('owner_id'), 7);
    }
}

final class SchemaTypesOverriddenModelSchema extends ModelSchema
{
    public static function model(): string
    {
        return SchemaTypesDocument::class;
    }
}

final class SchemaTypesShiftSchema extends QuerySchema
{
    #[Ability]
    public const ASSIGN = 'assign';

    public function rows(): QueryBuilder
    {
        return DB::table('teams')->select(['teams.id as team_id', 'teams.day']);
    }

    protected function key(): array
    {
        return ['team_id', 'day'];
    }
}

final class SchemaTypesMatchedShiftSchema extends QuerySchema
{
    public function rows(): QueryBuilder
    {
        return DB::table('teams');
    }

    public function matchKey(RowConditionContext $c, mixed $teamId): ?Builder
    {
        return $c->query->where($c->column('id'), $teamId);
    }
}

/**
 * A row condition's context over a bare where clause, named as $rowName.
 *
 * @param array<int, mixed> $arguments
 */
function schemaTypesRowContext(IRowSchema $schema, string $rowName, array $arguments = []): RowConditionContext
{
    return new RowConditionContext(
        new User,
        $schema->conditionQuery(DB::table('x'), $rowName),
        $rowName,
        $schema->keyColumns(),
        $arguments,
    );
}

// -- each type implements the interfaces it should ----------------------------

it('gives each schema type the interfaces it provides', function () {
    expect(new SchemaTypesTenancySchema)->toBeInstanceOf(IGlobalSchema::class)->not->toBeInstanceOf(IAbilitySchema::class)
        ->and(new SchemaTypesBillingSchema)->toBeInstanceOf(IAbilitySchema::class)->not->toBeInstanceOf(IRowSchema::class)
        ->and(new SchemaTypesDocumentSchema)->toBeInstanceOf(IModelSchema::class)
        ->and(new SchemaTypesShiftSchema)->toBeInstanceOf(IRowSchema::class)->not->toBeInstanceOf(IModelSchema::class);
});

// -- conditions ---------------------------------------------------------------

it('lists each kind of condition, and every kind its type provides together', function () {
    $tenancy = new SchemaTypesTenancySchema;
    $document = new SchemaTypesDocumentSchema;

    expect(array_map(fn (ConditionDefinition $c) => $c->key, $tenancy->globalConditions()))->toBe(['in_tenant'])
        ->and(array_map(fn (ConditionDefinition $c) => $c->key, $tenancy->derivedConditions()))->toBe(['in_tenant_or_admin'])
        ->and(array_map(fn (ConditionDefinition $c) => $c->key, $tenancy->conditions()))->toBe(['in_tenant', 'in_tenant_or_admin'])
        ->and(array_map(fn (ConditionDefinition $c) => $c->key, $document->conditions()))->toBe(['is_owner']);
});

it('looks a condition up by key, of whatever kind', function () {
    $document = new SchemaTypesDocumentSchema;

    expect($document->condition('is_owner')?->kind)->toBe(ConditionKind::Row)
        ->and((new SchemaTypesTenancySchema)->condition('in_tenant_or_admin')?->kind)->toBe(ConditionKind::Derived)
        ->and($document->condition('nope'))->toBeNull();
});

it('keeps one list of conditions per class', function () {
    expect((new SchemaTypesDocumentSchema)->conditions())->toBe((new SchemaTypesDocumentSchema)->conditions());
});

it('invokes a global condition with its DSL arguments after the context', function () {
    $billing = new SchemaTypesBillingSchema;
    $isFinance = $billing->condition('is_finance');

    expect($billing->invokeGlobalCondition($isFinance, new GlobalConditionContext(new User, DB::table('x'), ['finance'])))->toBeTrue()
        ->and($billing->invokeGlobalCondition($isFinance, new GlobalConditionContext(new User, DB::table('x'), ['sales'])))->toBeFalse();
});

it('invokes a row condition against the name the row goes by', function () {
    $document = new SchemaTypesDocumentSchema;

    $answer = $document->invokeRowCondition($document->condition('is_owner'), schemaTypesRowContext($document, 'd'));

    expect($answer)->toBeInstanceOf(QueryBuilder::class)
        ->and($answer->toSql())->toBe('select * from "x" where "d"."owner_id" = ?');
});

it('invokes a derived condition with its arguments alone', function () {
    $tenancy = new SchemaTypesTenancySchema;

    expect($tenancy->invokeDerivedCondition($tenancy->condition('in_tenant_or_admin'), []))->toBe('in_tenant or is_admin');
});

it('refuses a condition that answers with anything but a query, a bool or null', function () {
    $schema = new class extends RowlessSchema {
        #[GlobalCondition]
        public function bad(GlobalConditionContext $c): string
        {
            return 'x';
        }
    };

    expect(fn () => $schema->invokeGlobalCondition($schema->condition('bad'), new GlobalConditionContext(new User, DB::table('x'))))
        ->toThrow(InvalidArgumentException::class, 'returned a [string]');
});

// -- rule templates -----------------------------------------------------------

it('reads a rule template and invokes it with its context first', function () {
    $tenancy = new SchemaTypesTenancySchema;
    $template = $tenancy->ruleTemplate('owner_rules');

    expect($template->requiredArgumentCount)->toBe(1)
        ->and($template->takesContext)->toBeTrue()
        ->and($template->callOncePerAbility)->toBeTrue()
        ->and($tenancy->invokeRuleTemplate($template, new RuleTemplateContext(['view'], true), ['author_id']))
        ->toBe('if mine(author_id) they can');
});

// -- abilities and context ----------------------------------------------------

it('reads abilities and required context', function () {
    $billing = new SchemaTypesBillingSchema;

    expect($billing->ability('export')?->requiredContext)->toBe(['tenant'])
        ->and($billing->ability('nope'))->toBeNull()
        ->and($billing->requiredContextKeys())->toBe(['tenant'])
        ->and($billing->defaultContext())->toBe([]);
});

// -- model schemas ------------------------------------------------------------

it('takes its model from the model constant, or an override', function () {
    expect(SchemaTypesDocumentSchema::model())->toBe(SchemaTypesDocument::class)
        ->and(SchemaTypesOverriddenModelSchema::model())->toBe(SchemaTypesDocument::class);
});

it('names its rows and their key by the model', function () {
    $document = new SchemaTypesDocumentSchema;

    expect($document->rowName())->toBe('documents')
        ->and($document->keyColumns())->toBe(['id'])
        ->and($document->rowsQuery()->toSql())->toBe('select * from "documents"');
});

it('finds a model row by its key', function () {
    $document = new SchemaTypesDocumentSchema;

    expect($document->rowMatcher()->requiredArgumentCount)->toBe(1)
        ->and($document->invokeRowMatcher(schemaTypesRowContext($document, 'd', [12]))->toSql())
        ->toBe('select * from "x" where "d"."id" = ?');
});

// -- query schemas ------------------------------------------------------------

it('selects its rows from its query, under a fixed name', function () {
    expect((new SchemaTypesShiftSchema)->rowsQuery()->toSql())
        ->toBe('select * from (select "teams"."id" as "team_id", "teams"."day" from "teams") as "warrant_rows"');
});

it('finds a row by every column of its key, in order', function () {
    $shift = new SchemaTypesShiftSchema;

    expect($shift->rowMatcher()->requiredArgumentCount)->toBe(2)
        ->and($shift->invokeRowMatcher(schemaTypesRowContext($shift, 'warrant_rows', [3, '2026-10-25']))->toSql())
        ->toBe('select * from "x" where "warrant_rows"."team_id" = ? and "warrant_rows"."day" = ?');
});

it('answers unknown when a part of the key is null', function () {
    $shift = new SchemaTypesShiftSchema;

    expect($shift->invokeRowMatcher(schemaTypesRowContext($shift, 'warrant_rows', [3, null])))->toBeNull();
});

it('finds a row with a matchKey of its own', function () {
    $matched = new SchemaTypesMatchedShiftSchema;

    expect($matched->keyColumns())->toBe([])
        ->and($matched->rowMatcher()->methodName)->toBe('matchKey')
        ->and($matched->invokeRowMatcher(schemaTypesRowContext($matched, 'warrant_rows', [5]))->toSql())
        ->toBe('select * from "x" where "warrant_rows"."id" = ?');
});

it('asks for a column name when the key is not one column', function () {
    expect(fn () => schemaTypesRowContext(new SchemaTypesShiftSchema, 'warrant_rows')->column())
        ->toThrow(BadMethodCallException::class, 'several key columns');
});

// -- the vocabulary -----------------------------------------------------------

it('holds a schema\'s lookups as data', function () {
    $vocabulary = Vocabulary::of(new SchemaTypesDocumentSchema);

    expect($vocabulary->ability('view')?->name)->toBe('view')
        ->and($vocabulary->condition('is_owner')?->kind)->toBe(ConditionKind::Row)
        ->and($vocabulary->rowMatcher()?->requiredArgumentCount)->toBe(1)
        ->and($vocabulary->hasRows())->toBeTrue()
        ->and(Vocabulary::of(new SchemaTypesBillingSchema)->hasRows())->toBeFalse();
});

// -- what each type refuses ---------------------------------------------------

it('refuses a row condition on a schema with no rows', function () {
    expect(fn () => new class extends RowlessSchema {
        #[RowCondition]
        public function mine(RowConditionContext $c): bool
        {
            return true;
        }
    })->toThrow(InvalidArgumentException::class, 'is a #[RowCondition], but schema');
});

it('refuses an ability on a global schema', function () {
    expect(fn () => new class extends GlobalSchema {
        #[Ability]
        public const VIEW = 'view';
    })->toThrow(InvalidArgumentException::class, 'declares an ability, but schema');
});

it('refuses required context on a global schema', function () {
    expect(fn () => new class extends GlobalSchema {
        #[RequiredContext]
        public const TENANT = 'tenant';
    })->toThrow(InvalidArgumentException::class, 'declares required context, but schema');
});

it('refuses a query schema with no way to find a row', function () {
    expect(fn () => new class extends QuerySchema {
        public function rows(): QueryBuilder
        {
            return DB::table('teams');
        }
    })->toThrow(InvalidArgumentException::class, 'has no way to find one of its rows');
});

it('refuses a model schema that names no model', function () {
    expect(fn () => new class extends ModelSchema {})
        ->toThrow(LogicException::class, 'names no model');
});

it('refuses a condition key used by two kinds', function () {
    expect(fn () => new class extends RowlessSchema {
        #[GlobalCondition('same')]
        public function a(GlobalConditionContext $c): bool
        {
            return true;
        }

        #[DerivedCondition('same')]
        public function b(): string
        {
            return 'x';
        }
    })->toThrow(InvalidArgumentException::class, 'declares condition [same] more than once');
});
