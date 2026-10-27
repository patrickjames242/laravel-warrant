<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Warrant\Builders\Build;
use Warrant\Builders\Ref;
use Warrant\Builders\WarrantConditionBuilder;
use Warrant\DSL\Compiling\CompilationContext;
use Warrant\DSL\Compiling\QueryFactory;
use Warrant\DSL\Compiling\RuleSetCompiler;
use Warrant\DSL\Expanding\DerivedConditionNode;
use Warrant\DSL\Parsing\ASTNodes\BooleanNode;
use Warrant\DSL\Parsing\ASTNodes\CanClauseNode;
use Warrant\DSL\Parsing\ASTNodes\ConditionNode;
use Warrant\DSL\Parsing\ASTNodes\CrossSchemaCanNode;
use Warrant\DSL\Parsing\ASTNodes\ExpandedRule;
use Warrant\DSL\Parsing\ASTNodes\ExpandedSyntax;
use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\InvalidSyntax;
use Warrant\DSL\Parsing\ASTNodes\RuleNode;
use Warrant\DSL\Parsing\ASTNodes\ValidSyntax;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\BoundSyntax;
use Warrant\DSL\Parsing\Diagnostic;
use Warrant\DSL\Parsing\Positions\SyntaxLocation;
use Warrant\DSL\Parsing\SyntaxProducer;
use Warrant\DSL\Parsing\SyntaxProducerKind;
use Warrant\DSL\Validation\GenericMode;
use Warrant\DSL\Validation\ValidationInput;
use Warrant\Facades\Warrant;
use Warrant\Rules\IRuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\Abilities\Ability;
use Warrant\Schema\Conditions\Derived\DerivedCondition;
use Warrant\Schema\Conditions\Row\RowCondition;
use Warrant\Schema\Conditions\Row\RowConditionContext;
use Warrant\Schema\RuleTemplates\RuleTemplate;
use Warrant\Schema\Types\Classes\ModelSchema;
use Warrant\WarrantManager;

require_once __DIR__.'/Support/TestSupport.php';

class OriginDoc extends Model
{
    use \Warrant\HasWarrantSchema;

    protected $table = 'origin_docs';

    public static function warrantSchema(): string
    {
        return OriginDocSchema::class;
    }
}

class OriginDocSchema extends ModelSchema
{
    public const model = OriginDoc::class;

    public static Closure $answer;

    #[Ability]
    public const VIEW = 'view';

    #[Ability]
    public const EDIT = 'edit';

    #[RowCondition]
    public function isOwner(RowConditionContext $c)
    {
        return $c->query->whereRaw('1 = 1');
    }

    #[RowCondition]
    public function ownerIs(RowConditionContext $c, string $id)
    {
        return $c->query->whereRaw('1 = 1');
    }

    #[DerivedCondition]
    public function ownedOrNamed(): WarrantConditionBuilder
    {
        return Build::condition()->if('is_owner')->orIf(fn ($c) => $c->if('owner_is', ['u9'])->andIfNot('is_owner'));
    }

    #[DerivedCondition]
    public function ownedByBound(): BoundSyntax
    {
        return warrant('owner_is(:id)', ['id' => 'u3']);
    }

    #[RuleTemplate]
    public function boundRules(): BoundSyntax
    {
        return warrant('if owner_is(:owner) they can', ['owner' => 't2']);
    }

    #[DerivedCondition]
    public function alwaysTrue(): bool
    {
        return true;
    }

    #[RuleTemplate]
    public function ownedRules(): RuleNode
    {
        return Build::rule()->if('is_owner')->orIf(fn ($c) => $c->if('owner_is', ['t1']))->theyCan()->toRule();
    }

    public function rules(RuleProviderContext $context): \Warrant\Builders\WarrantRuleBuilder|BoundSyntax|string
    {
        return (self::$answer)();
    }
}

beforeEach(function () {
    useWarrantSchemas(['origin_docs' => OriginDocSchema::class, 'course_sections' => WarrantTestSchema::class]);
});

/**
 * The rules a fresh guard expands when the schema's rules() answers with what
 * $answer returns.
 *
 * @return list<RuleNode>
 */
function originRules(Closure $answer): array
{
    OriginDocSchema::$answer = $answer;
    app(WarrantManager::class)->flush();

    return Warrant::guard(makeWarrantTestUser('origin'))->forSchema(OriginDocSchema::class)->expandedRuleSet()->ruleNodes();
}

/**
 * Every node of $node, as its class's short name, a name or value it holds, and
 * the kind of producer its source has.
 *
 * @return list<string>
 */
function producersOf(INode $node): array
{
    $nodes = [$node];

    for ($i = 0; $i < count($nodes); $i++) {
        array_push($nodes, ...$nodes[$i]->children());
    }

    return array_map(static function (INode $node): string {
        $held = match (true) {
            property_exists($node, 'name') && is_string($node->name) => '('.$node->name.')',
            $node instanceof \Warrant\DSL\Parsing\ASTNodes\LiteralNode || $node instanceof BooleanNode => '('.var_export($node->value, true).')',
            default => '',
        };

        return (new ReflectionClass($node))->getShortName().$held.' '.($node->location->source->producer()?->kind->name ?? 'none');
    }, $nodes);
}

// -- what each producer says ------------------------------------------------------

it('describes each producer the way an error message opens', function (Closure $producer, string $description) {
    expect($producer()->describe())->toBe($description);
})->with([
    'a schema\'s rules' => [fn () => SyntaxProducer::schemaRules('docs', 'App\\DocSchema'), 'The rules() of schema [App\\DocSchema]'],
    'the rule provider' => [fn () => SyntaxProducer::ruleProvider('docs', 'App\\Rules'), 'The rule provider'],
    'a rule template' => [
        fn () => SyntaxProducer::ruleTemplate('docs', 'App\\DocSchema', 'owned', 'ownedRules', new \Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode(nameNode('owned'), [], [], SyntaxLocation::noProducer())),
        'Rule template [App\\DocSchema::ownedRules]',
    ],
    'a derived condition' => [
        fn () => SyntaxProducer::derivedCondition('docs', 'App\\DocSchema', 'owned', 'ownedOrNamed', new ConditionNode(nameNode('owned'), [], SyntaxLocation::noProducer())),
        'Derived condition [App\\DocSchema::ownedOrNamed]',
    ],
    'a raw condition' => [fn () => SyntaxProducer::rawCondition(), 'A raw condition'],
    'an editor' => [fn () => SyntaxProducer::editor('/app/rules.warrant'), 'The rule text in [/app/rules.warrant]'],
]);

it('says what a syntax is validated as by the kind of its producer', function () {
    expect(SyntaxProducer::schemaRules('docs', 'X')->genericMode())->toBe(GenericMode::RequireNotGeneric)
        ->and(SyntaxProducer::ruleProvider('docs', 'X')->genericMode())->toBe(GenericMode::RequireNotGeneric)
        ->and(SyntaxProducer::editor('x')->genericMode())->toBe(GenericMode::Either)
        ->and(SyntaxProducer::rawCondition()->genericMode())->toBe(GenericMode::Either);
});

it('names the producer an answer came from when it makes a mistake', function () {
    expect(fn () => originRules(fn () => 'for course_sections { they can view }'))
        ->toThrow(InvalidArgumentException::class, 'The rules() of schema ['.OriginDocSchema::class.'] returned a rule set for schema [course_sections]');
});

// -- the producer the backend sets ------------------------------------------------

it('gives every node of a schema\'s rule text the schema\'s producer', function () {
    expect(producersOf(originRules(fn () => 'if owner_is(\'u1\') they can view')[0]))->each->toEndWith('SchemaRules');
});

it('gives every node a builder made the producer of the answer it is in, through the source it is resolved in', function () {
    $rule = originRules(fn () => Build::rule()
        ->if('is_owner')
        ->orIf(fn ($c) => $c->if('owner_is', ['u1'])->andIfNot('is_owner'))
        ->orIfCan(Build::can('view')->for(OriginDocSchema::class, Ref::column('id')))
        ->ifRaw('owner_is(:owner)', ['owner' => 'u2'])
        ->theyCan('view')
        ->theyCannotBecause('edit', 'Locked.'))[0];

    expect(array_values(array_filter(producersOf($rule), static fn (string $node): bool => ! str_ends_with($node, 'SchemaRules'))))->toBe([
        'ConditionNode RawCondition',
        'ColumnRef none',
        'NameNode(owner_is) RawCondition',
        "LiteralNode('u2') RawCondition",
        'NameNode(id) none',
    ]);
});

it('places every node of a builder, its groups and predicates included, in the one source it is resolved in', function () {
    $builder = Build::rule()
        ->if('is_owner')
        ->orIf(fn ($c) => $c->if('owner_is', ['u1']))
        ->ifCheck(fn ($p) => $p->if('is_owner')->for(OriginDocSchema::class))
        ->theyCan('view');
    $rule = $builder->toRule();
    $nodes = [$rule];

    for ($i = 0; $i < count($nodes); $i++) {
        array_push($nodes, ...$nodes[$i]->children());
    }

    expect(array_unique(array_map(static fn (INode $node): int => spl_object_id($node->location->source), $nodes)))
        ->toBe([spl_object_id($rule->location->source)]);
});

it('gives a derived condition\'s builder answer its producer, and a constant it answers with none', function () {
    $rules = originRules(fn () => 'if owned_or_named or always_true they can view');
    $derived = array_values(array_filter(
        [$rules[0]->conditions->leftSide, $rules[0]->conditions->rightSide],
        static fn (INode $node): bool => $node instanceof DerivedConditionNode,
    ));

    expect(producersOf($derived[0]->body))->each->toEndWith('DerivedCondition')
        ->and(producersOf($derived[1]->body))->toBe(['BooleanNode(true) none']);
});

it('gives a template\'s built answer its producer, the abilities the include named keeping the caller\'s', function () {
    expect(array_values(array_filter(
        producersOf(originRules(fn () => '@include owned_rules for view')[0]),
        static fn (string $node): bool => ! str_ends_with($node, 'RuleTemplate'),
    )))->toBe(['NameNode(view) SchemaRules']);
});

it('gives the rule provider\'s answer its producer', function () {
    app()->instance(IRuleProvider::class, new class implements IRuleProvider
    {
        public function rules(RuleProviderContext $context): string
        {
            return $context->schemaKey === 'origin_docs' ? 'if is_owner they can edit' : '';
        }
    });

    $rules = originRules(fn () => '');

    expect($rules[0]->location->source->producer()->kind)->toBe(SyntaxProducerKind::RuleProvider);
});

it('reads rule text answered with its values, from rules(), a template or a derived condition, as its producer\'s', function () {
    $rules = originRules(fn () => warrant("if owner_is(:id) they can view\n@include bound_rules for edit\nif owned_by_bound they can edit", ['id' => 'u1']));

    expect(array_map(static fn (RuleNode $rule): array => [
        $rule->location->source->producer()->kind,
        argumentValues($rule->conditions instanceof DerivedConditionNode ? $rule->conditions->body->parameters : $rule->conditions->parameters),
    ], $rules))->toBe([
        [SyntaxProducerKind::SchemaRules, ['u1']],
        [SyntaxProducerKind::RuleTemplate, ['t2']],
        [SyntaxProducerKind::SchemaRules, ['u3']],
    ])->and($rules[2]->conditions->body->location->source->producer()->kind)->toBe(SyntaxProducerKind::DerivedCondition);
});

// -- binding checks with and without values ----------------------------------------

/**
 * The mistakes $source with $bindings makes, by message, with binding values
 * checked or not.
 *
 * @param array<int|string, mixed> $bindings
 * @return list<string>
 */
function bindingMistakes(string $source, array $bindings, bool $checkBindingValues): array
{
    $checked = ValidSyntax::check(WarrantSyntax::parse(new BoundSyntax($source, $bindings)), new ValidationInput(null, $checkBindingValues));

    return $checked instanceof InvalidSyntax
        ? array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $checked->diagnostics)
        : [];
}

it('checks what needs binding values only when values are checked', function (string $source, array $bindings, string $mistake) {
    expect(bindingMistakes($source, $bindings, true))->toBe([$mistake])
        ->and(bindingMistakes($source, $bindings, false))->toBe([]);
})->with([
    'a named binding with no value' => ['if a(:x) they can view', [], 'No binding provided for ":x".'],
    'a value never used' => ['if a they can view', ['x' => 1], 'Binding(s) provided but never used: x.'],
    'an @sql value that is no string' => ['if a(@sql :q) they can view', ['q' => 5], 'An @sql binding must resolve to a string, got int.'],
    'a message that is no string or closure' => ['they cannot view because :why', ['why' => 5], 'A denial message must be a string or a closure, got int.'],
    'a row selector bound to null' => [
        'if can(view for docs(:id)) they can edit',
        ['id' => null],
        'A can(...) reference to schema [docs] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
    ],
]);

// -- validating with no schemas -----------------------------------------------------

/**
 * The mistakes $source makes validated with no schemas, or against $catalog, by
 * message.
 *
 * @return list<string>
 */
function schemaFreeMistakes(string $source, ?\Warrant\DSL\ISchemaCatalog $catalog = null): array
{
    $checked = ValidSyntax::check(WarrantSyntax::parse($source), new ValidationInput($catalog));

    return $checked instanceof InvalidSyntax
        ? array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message, $checked->diagnostics)
        : [];
}

it('holds text validated with no schemas to every rule of the language that needs none', function (string $source, string $mistake) {
    expect(schemaFreeMistakes($source))->toBe([$mistake]);
})->with([
    'a with key written twice' => ['if can(view for docs with a = 1, a = 2) they can edit', "Duplicate key 'a' in the 'with' map."],
    'a literal null row target' => [
        'if can(view for docs(null)) they can edit',
        'A can(...) reference to schema [docs] specifies a row target that is null; supply a row id or a @context reference, or drop the row selector.',
    ],
    'an alias on a handle with no row' => [
        'if can(view for docs as d) they can edit',
        'A can(...) reference to schema [docs] is aliased [as d] but selects no row, so the alias names nothing; add a row selector like docs(@context id) as d, or drop the alias.',
    ],
]);

it('looks up no name and no @column scope with no schemas', function () {
    expect(schemaFreeMistakes('if no_such_condition(@column nowhere.id) they can no_such_ability'))->toBe([]);
});

it('still checks the targets the text names against a catalog that holds no schema for its rules', function () {
    expect(schemaFreeMistakes('if can(view for nowhere) they can edit', catalogOf(['course_sections' => new WarrantTestSchema])))
        ->toBe(['A can(...) reference targets unknown schema [nowhere].']);
});

// -- the compiler holds the same line -----------------------------------------------

it('rejects a with key written twice in the compiler too', function () {
    $handle = new CrossSchemaCanNode(
        nameNode('course_sections'),
        nameNode('view'),
        false,
        [],
        [...withEntries(['a' => 1]), ...withEntries(['a' => 2])],
        null,
        SyntaxLocation::noProducer(),
    );
    $rule = new RuleNode($handle, [new CanClauseNode([nameNode('view')], null, SyntaxLocation::noProducer())], [], SyntaxLocation::noProducer());

    expect(fn () => (new RuleSetCompiler(new OriginDocSchema, 'origin_docs', app(WarrantManager::class)))->compile(
        CompilationContext::ability(
            QueryFactory::for(DB::table('origin_docs')),
            makeWarrantTestUser('origin'),
            'view',
            Closure::bind(static fn () => new ExpandedSyntax('origin_docs', [new ExpandedRule($rule)], new ValidationInput(null)), null, ExpandedSyntax::class)(),
        ),
    ))->toThrow(InvalidArgumentException::class, "Duplicate key 'a' in the 'with' map.");
});
