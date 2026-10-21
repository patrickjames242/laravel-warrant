<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Warrant\DSL\Parsing\ASTNodes\BindingNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\NameNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\DiagnosticCode;
use Warrant\DSL\Validation\GenericMode;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Registry\RegisteredSchemas;
use Warrant\Schema\RuleTemplate;

require_once __DIR__.'/Support/TestSupport.php';

class AbilityBindingModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return AbilityBindingSchema::class;
    }
}

class AbilityBindingSchema extends WarrantTestSchema
{
    public const model = AbilityBindingModel::class;

    #[RuleTemplate]
    public function grantsIt(): string
    {
        return 'they can';
    }
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => AbilityBindingSchema::class]);
});

/**
 * $syntax with $bindings handed over as the test schema's own rules.
 *
 * @param array<string, mixed> $bindings
 */
function abilityBindingSyntax(string $syntax, array $bindings = []): WarrantSyntax
{
    return producedFor(WarrantSyntax::parse(new BoundSyntax($syntax, $bindings)), 'course_sections', GenericMode::RequireNotGeneric);
}

/**
 * $syntax with $bindings validated as the test schema's own rules, its
 * binding values checked unless $checkValues says not to.
 *
 * @param array<string, mixed> $bindings
 */
function abilityBindingValid(string $syntax, array $bindings = [], bool $checkValues = true): ValidSyntax
{
    return ValidSyntax::of(abilityBindingSyntax($syntax, $bindings), new ValidationInput(new RegisteredSchemas, $checkValues));
}

/**
 * The messages of every mistake $syntax with $bindings makes as the test
 * schema's own rules, its binding values checked unless $checkValues says not
 * to.
 *
 * @param array<string, mixed> $bindings
 * @return list<string>
 */
function abilityBindingMistakes(string $syntax, array $bindings = [], bool $checkValues = true): array
{
    return array_map(
        static fn ($diagnostic): string => $diagnostic->message,
        mistakesOf(abilityBindingSyntax($syntax, $bindings), new ValidationInput(new RegisteredSchemas, $checkValues)),
    );
}

/**
 * $syntax with $bindings validated and expanded as the test schema's own rules,
 * its binding values checked while validating unless $checkValues says not to.
 *
 * @param array<string, mixed> $bindings
 */
function abilityBindingExpanded(string $syntax, array $bindings = [], bool $checkValues = true): ExpandedSyntax
{
    return ExpandedSyntax::expand('course_sections', new ValidationInput(new RegisteredSchemas), abilityBindingValid($syntax, $bindings, $checkValues));
}

// -- parsing ------------------------------------------------------------------

it('reads a binding wherever an ability list is written', function (string $syntax, Closure $abilities) {
    $ability = $abilities(WarrantSyntax::parse($syntax)->children[0]);

    expect($ability)->toBeInstanceOf(BindingNode::class)
        ->and($ability->name)->toBe('abilities')
        ->and(substr($syntax, $ability->location->span->offset, $ability->location->span->length()))->toBe(':abilities');
})->with([
    'they can' => ['they can view, :abilities', fn ($rule) => $rule->canClauses[0]->abilities[1]],
    'they cannot' => ["they cannot :abilities because 'no'", fn ($rule) => $rule->cannotClauses[0]->abilities[0]],
    'an ability block header' => ['can they :abilities { they can }', fn ($block) => $block->abilities[0]],
    'an @include' => ['@include grants_it for :abilities', fn ($include) => $include->abilities[0]],
]);

it('reads a binding as the ability a can expression asks about', function (string $syntax) {
    $can = WarrantSyntax::parse($syntax)->children[0]->conditions;

    expect($can)->toBeInstanceOf(CrossSchemaCanNode::class)
        ->and($can->ability)->toBeInstanceOf(BindingNode::class)
        ->and($can->ability->name)->toBe('ability');
})->with([
    'bare' => 'if can :ability they can update',
    'in parentheses' => 'if can(:ability) they can update',
    'across a boundary' => 'if can(:ability for course_sections) they can update',
]);

// -- validating ---------------------------------------------------------------

it('asks a bound ability for its value when binding values are checked', function () {
    expect(fn () => abilityBindingValid('they can :abilities'))
        ->toThrow(InvalidArgumentException::class, 'No binding provided for ":abilities".');
});

it('accepts a bound ability without its value when binding values are not checked', function () {
    $checked = ValidSyntax::check(
        producedFor(WarrantSyntax::parse('they can :abilities if can :other they can update'), 'course_sections'),
        new ValidationInput(new RegisteredSchemas, checkBindingValues: false),
    );

    expect($checked)->toBeInstanceOf(ValidSyntax::class);
});

it('counts a clause naming only a binding as naming its abilities', function () {
    expect(fn () => ValidSyntax::of(
        producedFor(WarrantSyntax::parse('they can :abilities'), 'course_sections', GenericMode::RequireGeneric),
        new ValidationInput(new RegisteredSchemas, checkBindingValues: false),
    ))->toThrow(InvalidArgumentException::class, 'This clause names abilities');
});

it('reports an ability list binding whose value names no abilities, at the binding', function (mixed $value, string $got) {
    $diagnostics = mistakesOf(abilityBindingSyntax('they can view, :abilities', ['abilities' => $value]), new ValidationInput(new RegisteredSchemas));

    expect(array_map(fn ($diagnostic) => [$diagnostic->code, $diagnostic->message, $diagnostic->offset], $diagnostics))->toBe([[
        DiagnosticCode::InvalidBindingValue,
        "The binding [:abilities] stands in an ability list, so its value is an ability name or a non-empty list of them; got $got.",
        15,
    ]]);
})->with([
    'an int' => [5, 'int'],
    'an empty list' => [[], 'an empty list'],
    'an array with keys' => [['a' => 'view'], 'an array with keys'],
    'a nested list' => [[['view']], 'a list holding array'],
]);

it('reports a can expression binding whose value is not one ability name', function (string $syntax) {
    expect(abilityBindingMistakes($syntax, ['ability' => ['view']]))->toBe([
        'The binding [:ability] stands for the one ability a can(...) reference asks about, so its value is an ability name; got array.',
    ]);
})->with([
    'bare' => 'if can :ability they can update',
    'across a boundary' => 'if can(:ability for course_sections) they can update',
]);

it('reports a bound ability the schema does not declare', function (string $syntax, array $bindings, string $message) {
    expect(abilityBindingMistakes($syntax, $bindings))->toBe([$message]);
})->with([
    'in a clause' => ['they can view, :more', ['more' => ['update', 'veiw']], 'Ability [veiw] is not declared by the schema.'],
    'in a block header' => ['can they :abilities { they can }', ['abilities' => 'veiw'], 'Ability [veiw] is not declared by the schema.'],
    'in an @include' => ['@include grants_it for :abilities', ['abilities' => 'veiw'], 'Ability [veiw] is not declared by the schema.'],
    'in a can expression' => ['if can :ability they can update', ['ability' => 'veiw'], 'Ability [veiw] is not declared by the schema.'],
    'across a boundary' => ['if can(:ability for course_sections) they can update', ['ability' => 'veiw'], 'Ability [veiw] is not declared by schema [course_sections].'],
]);

it('accepts a bound * as declared', function () {
    expect(abilityBindingMistakes('they can :all', ['all' => '*']))->toBe([]);
});

it('leaves a bound ability unchecked when binding values are not checked', function () {
    expect(abilityBindingMistakes('they can :a if can :b they can update', ['a' => 5, 'b' => 'veiw'], checkValues: false))->toBe([]);
});

// -- duplicates ---------------------------------------------------------------

it('reports an ability list that writes an ability or binding twice, or * beside anything else', function (string $syntax, string $message) {
    expect(abilityBindingMistakes($syntax, ['a' => 'view', 'b' => ['update']], checkValues: false))->toBe([$message]);
})->with([
    'a name twice in a can list' => ['they can view, view', 'Ability [view] is written more than once among the abilities this rule grants; write it once.'],
    'a name in two can clauses' => ['they can view they can view', 'Ability [view] is written more than once among the abilities this rule grants; write it once.'],
    'a name in two cannot clauses' => ['they cannot view they cannot view', 'Ability [view] is written more than once among the abilities this rule denies; write it once.'],
    'a binding twice' => ['they can :a, :a', 'Binding [:a] is written more than once among the abilities this rule grants; write it once.'],
    'a name twice in a block header' => ['can they view, view { they can }', 'Ability [view] is written more than once among the abilities this ability block names; write it once.'],
    'a name twice in an @include' => ['@include grants_it for view, view', 'Ability [view] is written more than once among the abilities this @include names; write it once.'],
    '* beside a name' => ['they can *, view', '`*` stands for every ability, so nothing else is written beside it among the abilities this rule grants.'],
    '* beside a binding' => ['they can :a, *', '`*` stands for every ability, so nothing else is written beside it among the abilities this rule grants.'],
    '* in another can clause' => ['they can * they can view', '`*` stands for every ability, so nothing else is written beside it among the abilities this rule grants.'],
]);

it('judges an ability list as written, not by the values its bindings give it', function (string $syntax, array $bindings) {
    expect(abilityBindingMistakes($syntax, $bindings))->toBe([]);
})->with([
    'a binding naming a name beside it' => ['they can :a, view', ['a' => 'view']],
    'two bindings naming one ability' => ['they can :a, :b', ['a' => 'view', 'b' => ['view']]],
    'a list naming one ability twice' => ['they can :a', ['a' => ['view', 'view']]],
    'a bound * beside a name' => ['they can :a, view', ['a' => '*']],
    'a list holding * and a name' => ['they can :a', ['a' => ['*', 'view']]],
    'one ability granted and denied' => ['they can view they cannot view', []],
]);

// -- expanding ----------------------------------------------------------------

it('puts the ability a binding names in its place, where the binding was written', function () {
    $syntax = 'they can :ability';
    $ability = abilityBindingExpanded($syntax, ['ability' => 'view'])->rules[0]->canClauses[0]->abilities[0];

    expect($ability)->toBeInstanceOf(NameNode::class)
        ->and($ability->name)->toBe('view')
        ->and(substr($syntax, $ability->location->span->offset, $ability->location->span->length()))->toBe(':ability');
});

it('splices a list of abilities into the list where its binding was written', function () {
    $rules = abilityBindingExpanded('they can create, :more, update', ['more' => ['view', 'publish']])->rules;

    expect($rules[0]->canAbilities())->toBe(['create', 'view', 'publish', 'update']);
});

it('puts a bound * in its place', function () {
    expect(abilityBindingExpanded('they cannot :all', ['all' => '*'])->rules[0]->cannotAbilities())->toBe(['*']);
});

it('applies the abilities a block header binds to every clause under it', function () {
    $rules = abilityBindingExpanded('can they :abilities { if is_teacher they can }', ['abilities' => ['view', 'update']])->rules;

    expect($rules[0]->canAbilities())->toBe(['view', 'update']);
});

it('applies the abilities an @include binds to the rules its template expands to', function () {
    $rules = abilityBindingExpanded('@include grants_it for :abilities', ['abilities' => ['view', 'archive']])->rules;

    expect($rules[0]->canAbilities())->toBe(['view', 'archive']);
});

it('puts the ability a can expression binds in its place', function () {
    $can = abilityBindingExpanded('if can(:ability) they can update', ['ability' => 'view'])->rules[0]->conditions;

    expect($can->ability)->toBeInstanceOf(NameNode::class)
        ->and($can->ability->name)->toBe('view');
});

it('rejects an ability list binding whose value names no abilities while expanding', function (mixed $value, string $got) {
    expect(fn () => abilityBindingExpanded('they can :abilities', ['abilities' => $value], checkValues: false))->toThrow(
        InvalidArgumentException::class,
        "The binding [:abilities] stands in an ability list, so its value is an ability name or a non-empty list of them; got $got.",
    );
})->with([
    'an int' => [5, 'int'],
    'an empty list' => [[], 'an empty list'],
    'an array with keys' => [['a' => 'view'], 'an array with keys'],
    'a nested list' => [[['view']], 'a list holding array'],
]);

it('rejects a can expression binding whose value is not one ability name while expanding', function (mixed $value, string $got) {
    expect(fn () => abilityBindingExpanded('if can :ability they can update', ['ability' => $value], checkValues: false))->toThrow(
        InvalidArgumentException::class,
        "The binding [:ability] stands for the one ability a can(...) reference asks about, so its value is an ability name; got $got.",
    );
})->with([
    'a list' => [['view'], 'array'],
    'an int' => [5, 'int'],
]);

// -- writing ------------------------------------------------------------------

it('writes a bound ability as the abilities its value names', function () {
    expect(abilityBindingValid('they can create, :more if can :one they can update', ['more' => ['view', 'publish'], 'one' => 'view'])->toSyntax())
        ->toBe("they can create, view, publish\n\nif can(view)\nthey can update");
});

// -- checking -----------------------------------------------------------------

it('grants the abilities a binding names', function () {
    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    bindWarrantRules('they can :abilities', ['abilities' => ['view', 'update']]);
    $guard = Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(AbilityBindingSchema::class);

    expect($guard->can('view', 'teacher:teacher-role'))->toBeTrue()
        ->and($guard->can('update', 'teacher:teacher-role'))->toBeTrue()
        ->and($guard->can('publish', 'teacher:teacher-role'))->toBeFalse();
});

it('rejects a bound ability the schema does not declare when the rules compile', function () {
    Schema::create('course_sections', fn ($table) => $table->string('id'));
    DB::table('course_sections')->insert([['id' => 'teacher:teacher-role']]);

    bindWarrantRules('they can :ability', ['ability' => 'veiw']);

    expect(fn () => Warrant::guard(makeWarrantTestUser('teacher-role'))->forSchema(AbilityBindingSchema::class)->can('view', 'teacher:teacher-role'))
        ->toThrow(InvalidArgumentException::class, 'Ability [veiw] is not declared by the schema.');
});
