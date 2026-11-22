<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaConditionNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\LiteralNode;
use Warrant\DSL\Parsing\ASTNodes\SqlRef;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\RuleValidationException;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\Constants\Constant;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\GlobalSchema;
use Warrant\Schema\Types\Classes\ModelSchema;

enum RcArgStatus: string
{
    case Open = 'open';
}

final class RcArgModulesSchema extends GlobalSchema
{
    #[Constant]
    public const PAYROLL_MODULE = 'payroll';

    #[Constant]
    public const SHARED = 'global';

    #[GlobalCondition]
    public function moduleEnabled(GlobalConditionContext $c, mixed $module): bool
    {
        return true;
    }

    #[DerivedCondition]
    public function payrollEnabled(): string
    {
        return 'module_enabled(shared)';
    }
}

class RcArgDoc extends Model
{
    use \Warrant\HasWarrantSchema;

    protected $table = 'rc_docs';

    public static function warrantSchema(): string
    {
        return RcArgDocSchema::class;
    }
}

class RcArgFolder extends Model
{
    use \Warrant\HasWarrantSchema;

    protected $table = 'rc_folders';

    public static function warrantSchema(): string
    {
        return RcArgFolderSchema::class;
    }
}

final class RcArgDocSchema extends ModelSchema
{
    public const model = RcArgDoc::class;

    /** @var list<mixed> The arguments every call to the `with_value` template was given. */
    public static array $templateArguments = [];

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[Constant]
    public const SHARED = 'own';

    #[Constant]
    public const STATUS = RcArgStatus::Open;

    #[Constant]
    public const LIMIT = 5;

    #[Constant]
    public const REVIEWERS = ['ann', 'bo'];

    #[Constant]
    public const STATUS_FILTER = "status = 'open'";

    #[Constant]
    public const LOCKED_MESSAGE = 'Locked for payroll.';

    #[RowCondition]
    public function hasValue(RowConditionContext $c, mixed $value): Builder
    {
        return $c->query->where($c->column('status'), $value);
    }

    #[RuleTemplate]
    public function withValue(mixed $value): string
    {
        self::$templateArguments[] = $value;

        return 'if has_value(:value) they can';
    }
}

final class RcArgFolderSchema extends ModelSchema
{
    public const model = RcArgFolder::class;

    #[Ability]
    public const VIEW = 'view';

    #[Constant]
    public const SHARED = 'folder';

    #[RowCondition]
    public function inScope(RowConditionContext $c, mixed $scope): Builder
    {
        return $c->query->where($c->column('scope'), $scope);
    }
}

beforeEach(function () {
    config()->set('warrant.global', [RcArgModulesSchema::class]);
    useWarrantSchemas(['rc_docs' => RcArgDocSchema::class, 'rc_folders' => RcArgFolderSchema::class]);
    RcArgDocSchema::$templateArguments = [];
});

/**
 * $text, the rules of `rc_docs`, expanded.
 */
function rcExpanded(string $text): ExpandedSyntax
{
    return expandedFor(WarrantSyntax::parse($text)->validateWithoutSchemas()->scopedTo('rc_docs'), new RcArgDocSchema);
}

/**
 * The arguments of the first rule's condition, once expanded, as values.
 *
 * @return list<mixed>
 */
function rcArguments(string $text): array
{
    $condition = rcExpanded($text)->ruleNodes()[0]->conditions;

    return array_map(static fn (LiteralNode $argument): mixed => $argument->value, $condition->parameters);
}

/**
 * Every mistake $text makes as the rules of `rc_docs`, as [code, message].
 *
 * @return list<array{0: string, 1: string}>
 */
function rcMistakes(string $text): array
{
    return array_map(
        static fn ($mistake): array => [$mistake->code->value, $mistake->message],
        mistakesOf(asSchemaRules($text, 'rc_docs'), new ValidationInput(Warrant::registry())),
    );
}

// -- parsing -------------------------------------------------------------------

it('reads a bare name and a prefixed name as constants where an argument goes', function () {
    $condition = WarrantSyntax::parse('if has_value(shared, rc_folders.shared) they can view')
        ->validateWithoutSchemas()->scopedTo('rc_docs')->entries[0]->conditions;

    expect($condition)->toBeInstanceOf(ConditionNode::class)
        ->and($condition->parameters[0])->toBeInstanceOf(ConstantRef::class)
        ->and($condition->parameters[0]->schema)->toBeNull()
        ->and($condition->parameters[0]->name->name)->toBe('shared')
        ->and($condition->parameters[1]->schema?->name)->toBe('rc_folders')
        ->and($condition->parameters[1]->written())->toBe('rc_folders.shared');
});

it('reads a constant as an @sql body and as a denial message', function () {
    $rule = WarrantSyntax::parse("if has_value(@sql status_filter) they cannot edit because locked_message")
        ->validateWithoutSchemas()->scopedTo('rc_docs')->entries[0];

    expect($rule->conditions->parameters[0])->toBeInstanceOf(SqlRef::class)
        ->and($rule->conditions->parameters[0]->sql)->toBeInstanceOf(ConstantRef::class)
        ->and($rule->cannotClauses[0]->message)->toBeInstanceOf(ConstantRef::class);
});

it('reports a prefix with no name after its dot', function () {
    expect(fn () => WarrantSyntax::parse('if has_value(rc_folders.) they can view'))
        ->toThrow(\Warrant\DSL\Parsing\WarrantSyntaxException::class, "Expected a constant name after 'rc_folders.'.");
});

it('writes a constant back as it was written', function () {
    $text = "if has_value(shared, rc_folders.shared, @sql status_filter)\nthey cannot edit because locked_message";
    $rule = WarrantSyntax::parse($text)->validateWithoutSchemas()->scopedTo('rc_docs')->entries[0];

    expect(writeSyntax($rule))->toBe($text);
});

// -- expansion -----------------------------------------------------------------

it('puts a constant\'s value in its place, looking a bare name up in the schema first, then the globals', function () {
    expect(rcArguments('if has_value(shared) they can view'))->toBe(['own'])
        ->and(rcArguments('if module_enabled(payroll_module) they can view'))->toBe(['payroll'])
        ->and(rcArguments('if has_value(view) they can view'))->toBe(['view']);
});

it('puts a prefixed constant\'s value in its place from the schema the prefix names', function () {
    expect(rcArguments('if has_value(rc_folders.shared) they can view'))->toBe(['folder'])
        ->and(rcArguments('if has_value(rc_folders.view) they can view'))->toBe(['view']);
});

it('passes a constant\'s value as PHP holds it: an enum case, a number, an array', function () {
    expect(rcArguments('if has_value(status) they can view'))->toBe([RcArgStatus::Open])
        ->and(rcArguments('if has_value(limit) they can view'))->toBe([5])
        ->and(rcArguments('if has_value(reviewers) they can view'))->toBe([['ann', 'bo']]);
});

it('keeps the place a constant was written on the value put there', function () {
    $text = 'if has_value(shared) they can view';
    $argument = rcExpanded($text)->ruleNodes()[0]->conditions->parameters[0];

    expect($argument->location->span?->offset)->toBe(strpos($text, 'shared'));
});

it('looks a constant in a check() predicate up from the schema the check names, and one in its handle from the rule', function () {
    $check = rcExpanded('if check(in_scope(shared) for rc_folders(limit)) they can view')->ruleNodes()[0]->conditions;

    expect($check)->toBeInstanceOf(CrossSchemaConditionNode::class)
        ->and($check->predicate->parameters[0]->value)->toBe('folder')
        ->and($check->handle->boundKey[0]->value)->toBe(5);
});

it('calls a template with a constant\'s value', function () {
    rcExpanded('@include with_value(status) for view');

    expect(RcArgDocSchema::$templateArguments)->toBe([RcArgStatus::Open]);
});

it('looks a constant in a global\'s derived condition up in the globals alone', function () {
    $answer = rcExpanded('if payroll_enabled they can view')->ruleNodes()[0]->conditions->body;

    expect($answer->parameters[0]->value)->toBe('global');
});

it('puts a string constant\'s value in an @sql body and a denial message', function () {
    $rule = rcExpanded('if has_value(@sql status_filter) they cannot edit because locked_message')->ruleNodes()[0];

    expect($rule->conditions->parameters[0]->sql->value)->toBe("status = 'open'")
        ->and($rule->cannotClauses[0]->message->value)->toBe('Locked for payroll.');
});

it('filters a query with a constant\'s value', function () {
    bindWarrantRules('if has_value(status) they can view', schemaKey: 'rc_docs');

    $query = Warrant::guard(makeWarrantTestUser())
        ->forSchema(RcArgDocSchema::class)
        ->filterQuery(warrantTestQuery('rc_docs'), 'view');

    expect($query->toSql())->toContain('"rc_docs"."status" = ?')
        ->and($query->getBindings())->toBe(['open']);
});

it('refuses, while expanding, a constant validation could not look up', function () {
    $syntax = WarrantSyntax::parse('for rc_docs { if has_value(nowhere) they can view }')->validateWithoutSchemas();

    expect(fn () => ExpandedSyntax::expand('rc_docs', new ValidationInput(Warrant::registry()), $syntax))
        ->toThrow(InvalidArgumentException::class, 'Constant [nowhere] is declared by neither schema [rc_docs] nor any global schema.');
});

it('refuses, while expanding, a prefixed constant only a global declares', function () {
    $syntax = WarrantSyntax::parse('for rc_docs { if has_value(rc_folders.payroll_module) they can view }')->validateWithoutSchemas();

    expect(fn () => ExpandedSyntax::expand('rc_docs', new ValidationInput(Warrant::registry()), $syntax))
        ->toThrow(InvalidArgumentException::class, 'schema [rc_folders] declares no constant [payroll_module]');
});

it('refuses, while expanding, an @sql constant that is not a string', function () {
    $syntax = WarrantSyntax::parse('for rc_docs { if has_value(@sql limit) they can view }')->validateWithoutSchemas();

    expect(fn () => ExpandedSyntax::expand('rc_docs', new ValidationInput(Warrant::registry()), $syntax))
        ->toThrow(InvalidArgumentException::class, 'An @sql constant must be a string, but [limit] is int.');
});

// -- validation ----------------------------------------------------------------

it('accepts constants it can look up', function () {
    expect(rcMistakes(
        "if has_value(shared) and module_enabled(payroll_module) and check(in_scope(shared) for rc_folders(limit))\n"
            ."they cannot edit because locked_message\n"
            .'@include with_value(rc_folders.shared) for view',
    ))->toBe([]);
});

it('reports a bare constant no schema it is looked up in declares, with the closest names', function () {
    expect(rcMistakes('if has_value(sharde) they can view'))->toBe([[
        DiagnosticCode::UnknownConstant->value,
        'Constant [sharde] is declared by neither schema [rc_docs] nor any global schema. Did you mean [shared]?',
    ]]);
});

it('reports a prefixed constant on a global as one the named schema does not declare', function () {
    expect(rcMistakes('if has_value(rc_folders.payroll_module) they can view'))->toBe([[
        DiagnosticCode::UnknownConstant->value,
        "Constant [rc_folders.payroll_module]: schema [rc_folders] declares no constant [payroll_module]. A schema prefix reaches that schema's own constants alone, never a global schema's.",
    ]]);
});

it('reports a constant whose prefix names no schema', function () {
    expect(rcMistakes('if has_value(rc_foldres.shared) they can view'))->toBe([[
        DiagnosticCode::UnknownSchema->value,
        'Constant [rc_foldres.shared] names unknown schema [rc_foldres]. Did you mean [rc_folders]?',
    ]]);
});

it('reports a constant in a check() predicate the checked schema cannot find, and one in its handle the rule cannot', function () {
    expect(array_column(rcMistakes('if check(in_scope(limit) for rc_folders(nowhere)) they can view'), 1))->toBe([
        'Constant [nowhere] is declared by neither schema [rc_docs] nor any global schema.',
        'Constant [limit] is declared by neither schema [rc_folders] nor any global schema.',
    ]);
});

it('reports an unknown constant given to a template', function () {
    expect(array_column(rcMistakes('@include with_value(nowhere) for view'), 0))->toBe([DiagnosticCode::UnknownConstant->value]);
});

it('reports an @sql constant and a denial message constant that are not strings', function () {
    expect(rcMistakes('if has_value(@sql limit) they cannot edit because reviewers'))->toBe([
        [DiagnosticCode::InvalidConstantValue->value, 'A denial message must be a string, but constant [reviewers] is array.'],
        [DiagnosticCode::InvalidConstantValue->value, 'An @sql constant must be a string, but [limit] is int.'],
    ]);
});

it('checks no constant where it has no schema to look it up in', function () {
    expect(WarrantSyntax::parse('if has_value(nowhere) they can view')->validateWithoutSchemas())
        ->toBeInstanceOf(\Warrant\DSL\Parsing\ASTNodes\ValidSyntax::class);
});

it('throws the first constant mistake when validating strictly', function () {
    expect(fn () => asSchemaRules('if has_value(nowhere) they can view', 'rc_docs')->validate())
        ->toThrow(RuleValidationException::class, 'Constant [nowhere] is declared by neither schema [rc_docs] nor any global schema.');
});
