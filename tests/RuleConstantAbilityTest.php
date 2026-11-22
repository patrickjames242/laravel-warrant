<?php

require_once __DIR__.'/Support/TestSupport.php';

use Warrant\DSL\Parsing\ASTNodes\ConstantRef;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Global\GlobalCondition;
use Warrant\Schema\Conditions\Global\GlobalConditionContext;
use Warrant\Schema\Constants\Constant;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\GlobalSchema;
use Warrant\Schema\Types\Classes\RowlessSchema;

final class RcaGroupsSchema extends GlobalSchema
{
    #[Constant]
    public const READERS = ['view'];

    #[Constant]
    public const SHARED_GROUP = ['view', 'edit'];

    #[Constant]
    public const COUNT = 3;

    #[GlobalCondition]
    public function isStaff(GlobalConditionContext $c): bool
    {
        return true;
    }
}

final class RcaDocSchema extends RowlessSchema
{
    public static string $rules = '';

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[Ability]
    public const APPROVE = 'approve';

    #[Ability]
    public const ARCHIVE = 'archive';

    #[Constant]
    public const EDITORS = [self::VIEW, self::EDIT];

    #[Constant]
    public const EVERYTHING = ['*'];

    #[Constant]
    public const ONE = self::APPROVE;

    #[Constant]
    public const SHARED_GROUP = [self::APPROVE];

    #[Constant]
    public const BAD_EMPTY = [];

    #[Constant]
    public const BAD_NUMBERS = [1, 2];

    #[Constant]
    public const UNDECLARED = ['view', 'publish'];

    #[RuleTemplate]
    public function staffOnly(): string
    {
        return 'if is_staff they can';
    }

    public function rules(RuleProviderContext $context): string
    {
        return self::$rules;
    }
}

final class RcaFolderSchema extends RowlessSchema
{
    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const SHARE = 'share';

    #[Constant]
    public const ONE = self::SHARE;

    #[Constant]
    public const DOC_READERS = ['view'];
}

beforeEach(function () {
    config()->set('warrant.global', [RcaGroupsSchema::class]);
    useWarrantSchemas(['rca_docs' => RcaDocSchema::class, 'rca_folders' => RcaFolderSchema::class]);
    RcaDocSchema::$rules = '';
});

/**
 * $text, the rules of `rca_docs`, expanded.
 */
function rcaExpanded(string $text): ExpandedSyntax
{
    return expandedFor(WarrantSyntax::parse($text)->validateWithoutSchemas()->scopedTo('rca_docs'), new RcaDocSchema);
}

/**
 * What $text's first rule grants and denies, once expanded.
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function rcaAbilities(string $text): array
{
    $rule = rcaExpanded($text)->rules[0];

    return [$rule->grantedAbilities(), $rule->deniedAbilities()];
}

/**
 * Every mistake $text makes as the rules of `rca_docs`, as [code, message].
 *
 * @return list<array{0: string, 1: string}>
 */
function rcaMistakes(string $text): array
{
    return array_map(
        static fn ($mistake): array => [$mistake->code->value, $mistake->message],
        mistakesOf(asSchemaRules($text, 'rca_docs'), new ValidationInput(Warrant::registry())),
    );
}

// -- parsing -------------------------------------------------------------------

it('reads a prefixed constant in an ability list, and keeps a bare name a name', function () {
    $abilities = WarrantSyntax::parse('if is_staff they can rca_folders.doc_readers, editors')
        ->validateWithoutSchemas()->scopedTo('rca_docs')->entries[0]->canClauses[0]->abilities;

    expect($abilities[0])->toBeInstanceOf(ConstantRef::class)
        ->and($abilities[0]->written())->toBe('rca_folders.doc_readers')
        ->and($abilities[1])->toBeInstanceOf(NameNode::class);
});

it('reads a prefixed constant as the ability a can() asks about', function () {
    $can = WarrantSyntax::parse('if can(rca_folders.one for rca_folders) they can view')
        ->validateWithoutSchemas()->scopedTo('rca_docs')->entries[0]->conditions;

    expect($can)->toBeInstanceOf(CrossSchemaCanNode::class)
        ->and($can->ability)->toBeInstanceOf(ConstantRef::class);
});

it('writes a constant in an ability list back as it was written', function () {
    $text = "if can(rca_folders.one for rca_folders)\nthey can rca_folders.doc_readers, editors";
    $rule = WarrantSyntax::parse($text)->validateWithoutSchemas()->scopedTo('rca_docs')->entries[0];

    expect(writeSyntax($rule))->toBe($text);
});

// -- expansion -----------------------------------------------------------------

it('splices a group\'s abilities into a list where the group is written', function () {
    expect(rcaAbilities('if is_staff they can approve, editors, archive'))->toBe([['approve', 'view', 'edit', 'archive'], []])
        ->and(rcaAbilities('if is_staff they cannot editors'))->toBe([[], ['view', 'edit']]);
});

it('looks a group up in the schema first, then the globals', function () {
    expect(rcaAbilities('if is_staff they can readers')[0])->toBe(['view'])
        ->and(rcaAbilities('if is_staff they can shared_group')[0])->toBe(['approve']);
});

it('reads a string constant as the one ability it names', function () {
    expect(rcaAbilities('if is_staff they can one')[0])->toBe(['approve']);
});

it('reads a prefixed constant from the schema the prefix names', function () {
    expect(rcaAbilities('if is_staff they can rca_folders.doc_readers, rca_docs.editors')[0])->toBe(['view', 'view', 'edit']);
});

it('expands a group holding * to every ability', function () {
    expect(rcaAbilities('if is_staff they can everything')[0])->toBe(['view', 'edit', 'approve', 'archive']);
});

it('applies a group named by an ability block or an @include', function () {
    expect(array_map(static fn ($rule): array => $rule->grantedAbilities(), rcaExpanded(
        "can they editors { if is_staff they can }\n@include staff_only for editors",
    )->rules))->toBe([['view', 'edit'], ['view', 'edit']]);
});

it('keeps the place a group was written on each ability put there', function () {
    $text = 'if is_staff they can editors';
    $abilities = rcaExpanded($text)->ruleNodes()[0]->canClauses[0]->abilities;

    expect(array_map(static fn (NameNode $ability): ?int => $ability->location->span?->offset, $abilities))
        ->toBe([strpos($text, 'editors'), strpos($text, 'editors')]);
});

it('looks the ability a can() asks about up in the schema it asks, then the globals', function () {
    $own = rcaExpanded('if can(one) they can view')->ruleNodes()[0]->conditions;
    $across = rcaExpanded('if can(one for rca_folders) they can view')->ruleNodes()[0]->conditions;

    expect($own->ability->name)->toBe('approve')
        ->and($across->ability->name)->toBe('share');
});

it('grants the abilities a group names when checked', function () {
    RcaDocSchema::$rules = 'if is_staff they can editors';
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(RcaDocSchema::class);

    expect($guard->can('edit'))->toBeTrue()
        ->and($guard->can('view'))->toBeTrue()
        ->and($guard->can('approve'))->toBeFalse();
});

it('refuses, while expanding, a group whose value names no abilities', function () {
    $syntax = WarrantSyntax::parse('for rca_docs { if is_staff they can bad_numbers }')->validateWithoutSchemas();

    expect(fn () => ExpandedSyntax::expand('rca_docs', new ValidationInput(Warrant::registry()), $syntax))
        ->toThrow(InvalidArgumentException::class, 'The constant [bad_numbers] stands in an ability list, so its value is an ability name or a non-empty list of them; got a list holding int.');
});

it('refuses, while expanding, a group as the one ability a can() asks about', function () {
    $syntax = WarrantSyntax::parse('for rca_docs { if can(editors) they can view }')->validateWithoutSchemas();

    expect(fn () => ExpandedSyntax::expand('rca_docs', new ValidationInput(Warrant::registry()), $syntax))
        ->toThrow(InvalidArgumentException::class, 'The constant [editors] stands for the one ability a can(...) reference asks about, so its value is an ability name; got array.');
});

// -- validation ----------------------------------------------------------------

it('accepts groups and string constants it can look up', function () {
    expect(rcaMistakes(
        "if is_staff and can(one) and can(one for rca_folders) they can editors, readers, rca_folders.doc_readers\n"
            ."can they shared_group { if is_staff they cannot }\n"
            .'@include staff_only for everything',
    ))->toBe([]);
});

it('judges duplicates on the text as written, never on values', function () {
    expect(rcaMistakes('if is_staff they can view, editors'))->toBe([])
        ->and(array_column(rcaMistakes('if is_staff they can editors, editors'), 0))->toBe([DiagnosticCode::DuplicateAbility->value])
        ->and(array_column(rcaMistakes('if is_staff they can rca_docs.editors, rca_docs.editors'), 1))
        ->toBe(['Constant [rca_docs.editors] is written more than once among the abilities this rule grants; write it once.'])
        ->and(array_column(rcaMistakes('if is_staff they can *, editors'), 0))->toBe([DiagnosticCode::StarBesideAbilities->value]);
});

it('reports a group whose value names no abilities', function () {
    expect(rcaMistakes('if is_staff they can bad_empty, count'))->toBe([
        [DiagnosticCode::InvalidConstantValue->value, 'The constant [bad_empty] stands in an ability list, so its value is an ability name or a non-empty list of them; got an empty list.'],
        [DiagnosticCode::InvalidConstantValue->value, 'The constant [count] stands in an ability list, so its value is an ability name or a non-empty list of them; got int.'],
    ]);
});

it('reports an ability a group names that the schema does not declare', function () {
    expect(rcaMistakes('if is_staff they can undeclared'))->toBe([
        [DiagnosticCode::UnknownAbility->value, 'Ability [publish] is not declared by the schema.'],
    ]);
});

it('reports a name that is neither an ability nor a constant as an ability the schema does not declare', function () {
    expect(array_column(rcaMistakes('if is_staff they can nowhere'), 0))->toBe([DiagnosticCode::UnknownAbility->value]);
});

it('reports a prefixed constant in an ability list the named schema does not declare', function () {
    expect(array_column(rcaMistakes('if is_staff they can rca_folders.readers'), 1))->toBe([
        "Constant [rca_folders.readers]: schema [rca_folders] declares no constant [readers]. A schema prefix reaches that schema's own constants alone, never a global schema's.",
    ]);
});

it('reports a group as the one ability a can() asks about', function () {
    expect(rcaMistakes('if can(editors) they can view'))->toBe([
        [DiagnosticCode::InvalidConstantValue->value, 'The constant [editors] stands for the one ability a can(...) reference asks about, so its value is an ability name; got array.'],
    ]);
});

it('reports an ability a can() names through a constant that the schema it asks does not declare', function () {
    expect(array_column(rcaMistakes('if can(rca_docs.one for rca_folders) they can view'), 1))
        ->toBe(['Ability [approve] is not declared by schema [rca_folders].']);
});
