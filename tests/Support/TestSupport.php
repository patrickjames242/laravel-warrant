<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warrant\Ability;
use Warrant\Facades\Warrant;
use Warrant\GlobalCondition;
use Warrant\HasWarrantSchema;
use Warrant\RuleResolutionContext;
use Warrant\RuleResolver;
use Warrant\RuleSyntaxTree\WarrantRule;
use Warrant\RuleSyntaxTree\WarrantRuleSet;
use Warrant\Schema\Conditions\GlobalConditionContext;
use Warrant\Schema\Conditions\TargetedConditionContext;
use Warrant\TargetedCondition;
use Warrant\Schema\WarrantSchema;
use Warrant\WarrantManager;

class WarrantTestUser implements Authenticatable
{
    public function __construct(public ?string $role_id = null) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->role_id;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): ?string
    {
        return null;
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}

class WarrantTestModel extends Model
{
    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';
}

class WarrantTestSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_CREATE = 'create';

    #[Ability]
    public const ABILITY_PUBLISH = 'publish';

    #[Ability]
    public const ABILITY_ARCHIVE = 'archive';

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[Ability]
    public const ABILITY_UPDATE = 'update';

    #[TargetedCondition]
    public function isTeacher(TargetedConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw("{$c->targetSqlId} = ?", ["teacher:{$c->user->role_id}"]);
    }

    #[GlobalCondition]
    public function isAdvisor(GlobalConditionContext $c): BuilderContract
    {
        return $c->query->whereRaw('? = ?', ['advisor', $c->user->role_id]);
    }
}

class WarrantBooleanConditionSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    #[GlobalCondition]
    public function isSuperUser(GlobalConditionContext $c): bool
    {
        return $c->user->role_id === 'super-role';
    }
}

class MistypedConditionSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // Marked targeted but typed with the global context — a boot-time mistake.
    #[TargetedCondition]
    public function isWrong(GlobalConditionContext $c): BuilderContract
    {
        return $c->query;
    }
}

class ExtraParamConditionSchema extends WarrantSchema
{
    public const model = WarrantTestModel::class;

    #[Ability]
    public const ABILITY_VIEW = 'view';

    // One context parameter is the whole contract; a second is a mistake.
    #[GlobalCondition]
    public function isWrong(GlobalConditionContext $c, string $extra): bool
    {
        return $extra !== '';
    }
}

class WarrantScopedModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return WarrantScopedModelSchema::class;
    }
}

class WarrantMismatchedScopedModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public function warrantSchema(): string
    {
        return WarrantTestSchema::class;
    }
}

class WarrantScopedModelSchema extends WarrantTestSchema
{
    public const model = WarrantScopedModel::class;
}

class WarrantImplicitRulesSchema extends WarrantTestSchema
{
    protected function implicitRules(): array
    {
        return [
            // Always grant publish, and never allow archive, regardless of the resolver.
            WarrantRule::fromSyntax('they can publish'),
            WarrantRule::fromSyntax('they cannot archive'),
        ];
    }
}

class FakeWarrantRuleResolver implements RuleResolver
{
    public function __construct(private WarrantRuleSet $ruleSet) {}

    public function resolve(RuleResolutionContext $context): WarrantRuleSet
    {
        return $this->ruleSet;
    }
}

/**
 * @param  array<int, class-string<WarrantSchema>>  $schemas
 */
function useWarrantSchemas(array $schemas): void
{
    config()->set('warrant.schemas', $schemas);
    app()->forgetInstance(WarrantManager::class);
    Warrant::clearResolvedInstances();
}

/**
 * Bind the resolver to a rule set built from Warrant syntax. The schema key is
 * irrelevant to the fake (the schema asks for its own rules), so it defaults to
 * the course_sections fixture schema key.
 */
function bindWarrantRules(string $syntax, array $bindings = [], string $schemaKey = 'course_sections'): void
{
    app()->instance(
        RuleResolver::class,
        new FakeWarrantRuleResolver(WarrantRuleSet::fromSyntax($schemaKey, $syntax, $bindings))
    );
}

/**
 * Bind the resolver to an explicit rule set.
 */
function bindWarrantRuleSet(WarrantRuleSet $ruleSet): void
{
    app()->instance(RuleResolver::class, new FakeWarrantRuleResolver($ruleSet));
}

function makeWarrantTestUser(?string $roleId = 'role-1'): Authenticatable
{
    return new WarrantTestUser($roleId);
}

function warrantTestQuery(string $table = 'course_sections'): BuilderContract
{
    return DB::connection()->table($table);
}

function normalizeWarrantSql(string $sql): string
{
    $sql = preg_replace('/\s+/', ' ', trim($sql));
    $sql = preg_replace('/\s*\(\s*/', '(', $sql);
    $sql = preg_replace('/\s*\)\s*/', ')', $sql);

    return preg_replace('/\s*,\s*/', ', ', $sql);
}
