<?php

use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\GenericMode;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Schema\RuleTemplates\RuleTemplate;

require_once __DIR__.'/Support/TestSupport.php';

class EveryAbilityExceptModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return EveryAbilityExceptSchema::class;
    }
}

/**
 * The test schema, declaring create, publish, archive, view and update, with a
 * template whose rules grant the abilities its include names.
 */
class EveryAbilityExceptSchema extends WarrantTestSchema
{
    public const model = EveryAbilityExceptModel::class;

    #[RuleTemplate]
    public function grantsIt(): string
    {
        return 'they can';
    }
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => EveryAbilityExceptSchema::class]);
});

/**
 * $syntax with $bindings handed over as the test schema's own rules.
 *
 * @param array<string, mixed> $bindings
 */
function exceptSyntax(string $syntax, array $bindings = []): WarrantSyntax
{
    return producedFor(WarrantSyntax::parse(new BoundSyntax($syntax, $bindings)), 'course_sections', GenericMode::RequireNotGeneric);
}

/**
 * Every mistake $syntax with $bindings makes as the test schema's own rules, as
 * its code, message and offset, its binding values checked unless $checkValues
 * says not to.
 *
 * @param array<string, mixed> $bindings
 * @return list<array{0: DiagnosticCode, 1: string, 2: int}>
 */
function exceptMistakes(string $syntax, array $bindings = [], bool $checkValues = true): array
{
    return array_map(
        static fn ($diagnostic): array => [$diagnostic->code, $diagnostic->message, $diagnostic->offset],
        mistakesOf(exceptSyntax($syntax, $bindings), new ValidationInput(Warrant::registry(), $checkValues)),
    );
}

/**
 * $syntax with $bindings validated and expanded as the test schema's own rules,
 * its binding values checked while validating unless $checkValues says not to.
 *
 * @param array<string, mixed> $bindings
 */
function exceptExpanded(string $syntax, array $bindings = [], bool $checkValues = true): ExpandedSyntax
{
    $input = new ValidationInput(Warrant::registry(), $checkValues);

    return ExpandedSyntax::expand('course_sections', $input, ValidSyntax::of(exceptSyntax($syntax, $bindings), $input));
}

// -- validating ---------------------------------------------------------------

it('accepts * except wherever an ability list is written', function (string $syntax) {
    expect(exceptMistakes($syntax, ['more' => ['update']]))->toBe([]);
})->with([
    'they can' => 'they can * except view, :more',
    'they cannot, with a message' => "they cannot * except view, :more because 'no'",
    'an ability block header' => 'can they * except view, :more { if is_teacher they can }',
    'an @include' => '@include grants_it for * except view, :more',
]);

it('reports what * except leaves out written twice, at the second', function (string $syntax, string $written, int $offset) {
    expect(exceptMistakes($syntax, ['a' => 'view'], checkValues: false))->toBe([[
        DiagnosticCode::DuplicateAbility,
        "$written is written more than once among the abilities this `* except` leaves out; write it once.",
        $offset,
    ]]);
})->with([
    'a name' => ['they can * except view, view', 'Ability [view]', 24],
    'a binding' => ['they can * except :a, :a', 'Binding [:a]', 22],
]);

it('reports * except written beside anything else', function (string $syntax, string $message) {
    expect(array_column(exceptMistakes($syntax, checkValues: false), 1))->toBe([$message]);
})->with([
    'beside a name' => ['they can view, * except update', '`*` stands for every ability, so nothing else is written beside it among the abilities this rule grants.'],
    'in another can clause' => ['they can * except update they can view', '`*` stands for every ability, so nothing else is written beside it among the abilities this rule grants.'],
    'beside *' => ['they can *, * except update', 'Ability [*] is written more than once among the abilities this rule grants; write it once.'],
    'in a block header' => ['can they view, * except update { they can }', '`*` stands for every ability, so nothing else is written beside it among the abilities this ability block names.'],
]);

it('reports an ability * except leaves out that the schema does not declare', function (string $syntax, array $bindings) {
    expect(exceptMistakes($syntax, $bindings))->toBe([[
        DiagnosticCode::UnknownAbility,
        'Ability [veiw] is not declared by the schema.',
        strpos($syntax, $bindings === [] ? 'veiw' : ':more'),
    ]]);
})->with([
    'written' => ['they can * except veiw', []],
    'bound' => ['they can * except view, :more', ['more' => ['update', 'veiw']]],
]);

it('reports * except that leaves out every ability the schema declares', function (string $syntax, array $bindings) {
    expect(exceptMistakes($syntax, $bindings))->toBe([[
        DiagnosticCode::ExceptsEveryAbility,
        '`* except` leaves out every ability the schema declares, so it stands for none.',
        strpos($syntax, '*'),
    ]]);
})->with([
    'written' => ['they can * except create, publish, archive, view, update', []],
    'partly bound' => ['they cannot * except create, :rest', ['rest' => ['publish', 'archive', 'view', 'update']]],
    'in an @include' => ['@include grants_it for * except :all', ['all' => ['create', 'publish', 'archive', 'view', 'update']]],
]);

it('leaves * except unjudged when a binding it leaves out has a value nobody checks', function () {
    expect(exceptMistakes('they can * except create, :rest', ['rest' => ['publish', 'archive', 'view', 'update']], checkValues: false))->toBe([]);
});

it('reports a binding * except leaves out whose value is not abilities, at the binding', function (mixed $value, string $got) {
    expect(exceptMistakes('they can * except view, :more', ['more' => $value]))->toBe([[
        DiagnosticCode::InvalidBindingValue,
        "The binding [:more] names abilities `* except` leaves out, so its value is an ability name or a non-empty list of them; got $got.",
        24,
    ]]);
})->with([
    '*' => ['*', '`*`'],
    'a list holding *' => [['update', '*'], 'a list holding `*`'],
    'an int' => [5, 'int'],
    'an empty list' => [[], 'an empty list'],
    'an array with keys' => [['a' => 'update'], 'an array with keys'],
    'a nested list' => [[['update']], 'a list holding array'],
]);

// -- expanding ----------------------------------------------------------------

it('expands * except to every ability the schema declares but those it leaves out', function () {
    $rule = exceptExpanded('they can * except view, :more', ['more' => ['publish']])->rules[0];

    expect($rule->grantedAbilities())->toBe(['create', 'archive', 'update']);
});

it('places what * except expands to where the * except was written', function () {
    $syntax = 'they can * except view';
    $abilities = exceptExpanded($syntax)->ruleNodes()[0]->canClauses[0]->abilities;

    expect(array_map(
        static fn ($ability): string => substr($syntax, $ability->location->span->offset, $ability->location->span->length()),
        $abilities,
    ))->toBe(array_fill(0, 4, '* except view'));
});

it('denies what * except stands for with the message its cannot clause gives', function () {
    $rule = exceptExpanded("they cannot * except view because 'Read only.'")->rules[0];

    expect($rule->deniedAbilities())->toBe(['create', 'publish', 'archive', 'update'])
        ->and($rule->messageFor('update'))->toBe('Read only.')
        ->and($rule->denies('view'))->toBeFalse();
});

it('applies the * except a block header writes to every can/cannot clause under it', function () {
    $rules = exceptExpanded('can they * except archive, :more { if is_teacher they can }', ['more' => 'update'])->rules;

    expect($rules[0]->grantedAbilities())->toBe(['create', 'publish', 'view']);
});

it('applies the * except an @include writes to the rules its template expands to', function () {
    $rules = exceptExpanded('@include grants_it for * except :more', ['more' => ['create', 'publish']])->rules;

    expect($rules[0]->grantedAbilities())->toBe(['archive', 'view', 'update']);
});

it('rejects while expanding what the validator reports, when binding values were not checked', function (array $bindings, string $message) {
    expect(fn () => exceptExpanded('they can * except view, :more', $bindings, checkValues: false))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a bound *' => [['more' => '*'], 'The binding [:more] names abilities `* except` leaves out, so its value is an ability name or a non-empty list of them; got `*`.'],
    'a bound ability the schema does not declare' => [['more' => 'veiw'], 'Ability [veiw] is not declared by the schema.'],
    'every ability left out' => [['more' => ['create', 'publish', 'archive', 'update']], '`* except` leaves out every ability the schema declares, so it stands for none.'],
]);
