<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Warrant\Builders\Build;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\LanguageServer\Php\PhpSourceReader;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\ModelSchema;

class ArgumentCountDoc extends Model
{
    use \Warrant\HasWarrantSchema;

    protected $table = 'argument_count_docs';

    public static function warrantSchema(): string
    {
        return ArgumentCountSchema::class;
    }
}

final class ArgumentCountSchema extends ModelSchema
{
    public const model = ArgumentCountDoc::class;

    #[Ability]
    public const VIEW = 'view';

    #[RowCondition]
    public function ownedBy(RowConditionContext $c, string $userId): Builder
    {
        return $c->query->where($c->column('owner_id'), $userId);
    }

    #[RowCondition]
    public function inAnyFolder(RowConditionContext $c, string ...$folderIds): Builder
    {
        return $c->query->whereIn($c->column('folder_id'), $folderIds);
    }

    #[GlobalCondition]
    public function hasRole(GlobalConditionContext $c, string $role = 'admin'): bool
    {
        return true;
    }

    #[DerivedCondition]
    public function ownedByEither(string $first, string $second): string
    {
        return 'owned_by(:first) or owned_by(:second)';
    }

    #[RuleTemplate]
    public function ownersOnly(string $userId): string
    {
        return 'if owned_by(:userId) they can';
    }
}

beforeEach(function () {
    useWarrantSchemas(['argument_count_docs' => ArgumentCountSchema::class]);
});

/**
 * $rules as argument_count_docs' own rules, validated as written. Throws the first mistake.
 */
function validateArgumentCountRules(string $rules): void
{
    $ruleSet = WarrantSyntax::parse($rules)->validateWithoutSchemas()->scopedTo('argument_count_docs');

    validateRuleSet($ruleSet, new ArgumentCountSchema);
}

/**
 * The diagnostic code of the first mistake in $rules, or null when there is none.
 */
function argumentCountMistake(string $rules): ?DiagnosticCode
{
    try {
        validateArgumentCountRules($rules);
    } catch (RuleValidationException $mistake) {
        return $mistake->diagnosticCode;
    }

    return null;
}

it('records the most arguments a condition or template takes, and none for a variadic one', function () {
    $schema = new ArgumentCountSchema;

    expect($schema->condition('owned_by')->maxArgumentCount)->toBe(1)
        ->and($schema->condition('has_role')->maxArgumentCount)->toBe(1)
        ->and($schema->condition('owned_by_either')->maxArgumentCount)->toBe(2)
        ->and($schema->condition('in_any_folder')->maxArgumentCount)->toBeNull()
        ->and($schema->ruleTemplate('owners_only')->maxArgumentCount)->toBe(1);
});

it('refuses a condition given more arguments than it takes', function () {
    expect(fn () => validateArgumentCountRules("if owned_by('u-1', 'u-2') they can view"))
        ->toThrow(RuleValidationException::class, 'Condition [owned_by] takes at most 1 argument(s), but the rule supplied 2.');

    expect(argumentCountMistake("if owned_by('u-1', 'u-2') they can view"))->toBe(DiagnosticCode::TooManyArguments)
        ->and(argumentCountMistake("if owned_by_either('a', 'b', 'c') they can view"))->toBe(DiagnosticCode::TooManyArguments)
        ->and(argumentCountMistake("if has_role('a', 'b') they can view"))->toBe(DiagnosticCode::TooManyArguments);
});

it('accepts a condition given every argument it takes, or any number when its last is variadic', function () {
    expect(argumentCountMistake("if owned_by('u-1') they can view"))->toBeNull()
        ->and(argumentCountMistake('if has_role they can view'))->toBeNull()
        ->and(argumentCountMistake("if has_role('editor') they can view"))->toBeNull()
        ->and(argumentCountMistake("if in_any_folder('f-1', 'f-2', 'f-3') they can view"))->toBeNull();
});

it('refuses an include giving a template more arguments than it takes', function () {
    expect(fn () => validateArgumentCountRules("@include owners_only('u-1', 'u-2') for view"))
        ->toThrow(RuleValidationException::class, 'Rule template [owners_only] takes at most 1 argument(s), but the @include supplies 2.');

    expect(argumentCountMistake("@include owners_only('u-1', 'u-2') for view"))->toBe(DiagnosticCode::TooManyArguments)
        ->and(argumentCountMistake("@include owners_only('u-1') for view"))->toBeNull();
});

it('refuses a condition compiled with more arguments than it takes, unvalidated', function () {
    $rule = Build::rule()->if('owned_by', ['u-1', 'u-2'])->theyCan('view')->toRule();

    /* Only the expander makes an expanded syntax, and it validates first; the
       compiler has to count the arguments on its own all the same. */
    $expanded = (fn () => new ExpandedSyntax('argument_count_docs', [$rule], new ValidationInput(null)))
        ->bindTo(null, ExpandedSyntax::class)();

    expect(fn () => (new RuleSetCompiler(new ArgumentCountSchema, 'argument_count_docs'))->compile(
        CompilationContext::ability(
            QueryFactory::for(warrantTestQuery('argument_count_docs')),
            makeWarrantTestUser('owner'),
            'view',
            $expanded,
        )->forTargetRow(),
    ))->toThrow(InvalidArgumentException::class, 'Condition [owned_by] on schema [ArgumentCountSchema] takes at most 1 argument(s), but the rule supplied 2.');
});

it('reads a variadic parameter from source as taking any number of arguments', function () {
    [$class] = PhpSourceReader::read('x.php', '<?php class X { public function a($c, $one) {} public function b($c, ...$many) {} }');

    expect($class->methods['a']->maxParameterCount())->toBe(2)
        ->and($class->methods['b']->maxParameterCount())->toBeNull();
});
