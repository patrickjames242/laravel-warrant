<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Expanding\ExpandedRuleSet;
use Warrant\DSL\Expanding\RuleSetExpander;
use Warrant\DSL\Parsing\ASTNodes\IncludeInvocationNode;
use Warrant\DSL\Parsing\ASTNodes\RuleSetNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantRuleNode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;
use Warrant\DSL\Parsing\Validation\RuleSetValidator;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Reachability;
use Warrant\Rules\RuleProvider;
use Warrant\Rules\RuleProviderContext;
use Warrant\Schema\RuleTemplate;

class TemplateExpansionModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return TemplateExpansionSchema::class;
    }
}

class TemplateExpansionSchema extends WarrantTestSchema
{
    public const model = TemplateExpansionModel::class;

    #[RuleTemplate]
    public function grantsIt(): string
    {
        return 'they can';
    }

    #[RuleTemplate]
    public function hardDeny(): string
    {
        return 'they cannot';
    }

    #[RuleTemplate]
    public function requiresApproval(): string
    {
        return "if is_advisor they cannot because 'Needs approval.'";
    }

    #[RuleTemplate]
    public function conditionalGrant(): string
    {
        return 'if is_teacher they can';
    }

    #[RuleTemplate]
    public function nestsAnother(): string
    {
        return '@include grants_it';
    }

    #[RuleTemplate]
    public function withRelation(string $relation): WarrantSyntax
    {
        return Warrant::parse('if is_teacher(:relation) they can', ['relation' => $relation]);
    }

    /** Terminates because the argument decreases at every level. */
    #[RuleTemplate]
    public function countsDown(int $n): string|WarrantSyntax
    {
        return $n <= 0
            ? 'they can'
            : Warrant::parse('@include counts_down(:n)', ['n' => $n - 1]);
    }

    /** Never terminates: the same arguments at every level. */
    #[RuleTemplate]
    public function loops(): string
    {
        return '@include loops';
    }

    #[RuleTemplate]
    public function needsTwo(string $a, string $b): string
    {
        return 'they can';
    }

    #[RuleTemplate]
    public function wrongReturn(): int
    {
        return 42;
    }

    #[RuleTemplate]
    public function answersWithARule(): WarrantRuleNode
    {
        return Warrant::rule()->if('is_teacher')->theyCan()->toRule();
    }

    #[RuleTemplate]
    public function answersWithAnInclude(): IncludeInvocationNode
    {
        return new IncludeInvocationNode('grants_it');
    }

    /** Every form at once, nested, in order. */
    #[RuleTemplate]
    public function answersWithAList(): array
    {
        return [
            'if is_teacher they can',
            Warrant::parse('if is_advisor they cannot because :why', ['why' => 'No.']),
            [new IncludeInvocationNode('grants_it')],
        ];
    }

    #[RuleTemplate]
    public function answersWithNothing(): array
    {
        return [];
    }

    #[RuleTemplate]
    public function answersWithANamedRule(): WarrantRuleNode
    {
        return Warrant::rule()->theyCan('view')->toRule();
    }

    #[RuleTemplate]
    public function answersWithANamedInclude(): IncludeInvocationNode
    {
        return new IncludeInvocationNode('grants_it', [], ['view']);
    }

    #[RuleTemplate]
    public function answersWithARuleSet(): RuleSetNode
    {
        return new RuleSetNode('course_sections', []);
    }

    #[RuleTemplate]
    public function answersWithParsedNamedRules(): WarrantSyntax
    {
        return Warrant::parse('if is_teacher they can view');
    }

    #[RuleTemplate]
    public function answersWithParsedBlock(): WarrantSyntax
    {
        return Warrant::parse('can they view { they can }');
    }

    #[RuleTemplate]
    public function answersWithAForBlock(): WarrantSyntax
    {
        return Warrant::parse('for course_sections { they can view }');
    }

    #[RuleTemplate]
    public function opensABlock(): string
    {
        return 'can they view { if is_teacher they can }';
    }

    #[RuleTemplate]
    public function namesAnAbility(): string
    {
        return 'if is_teacher they can view';
    }

    #[RuleTemplate]
    public function namesAnAbilityInACannot(): string
    {
        return "if is_teacher they cannot view because 'nope'";
    }

    #[RuleTemplate]
    public function emptyBody(): string
    {
        return '';
    }

    #[RuleTemplate]
    public function namesAnUnknownCondition(): string
    {
        return 'if no_such_condition they can';
    }

    #[RuleTemplate]
    public function namesTheWildcard(): string
    {
        return 'if is_teacher they can *';
    }

    #[RuleTemplate]
    public function mixesNaming(): string
    {
        return 'if is_teacher they can  if is_advisor they cannot view';
    }
}

class TemplateHopModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'template_hops';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return TemplateHopSchema::class;
    }
}

/** A second schema with the same templates, for a rule set reached through a hop. */
class TemplateHopSchema extends TemplateExpansionSchema
{
    public const model = TemplateHopModel::class;
}

function expandSyntax(string $syntax): ExpandedRuleSet
{
    return (new RuleSetExpander)->expand(
        WarrantSyntax::parse($syntax)->scopedTo('course_sections'),
        new TemplateExpansionSchema,
    );
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => TemplateExpansionSchema::class]);
});

// -- expansion ----------------------------------------------------------------

it('expands an include into the rules its longhand would produce', function () {
    $expanded = expandSyntax('can they view { @include requires_approval }');
    $longhand = WarrantSyntax::parse("if is_advisor they cannot view because 'Needs approval.'")->scopedTo('course_sections');

    expect($expanded->rules)->toHaveCount(1);
    expect($expanded->rules[0])->toBeInstanceOf(WarrantRuleNode::class);
    expect($expanded->rules[0]->cannotAbilities())->toBe($longhand->entries[0]->cannotAbilities());
    expect($expanded->rules[0]->messageFor('view'))->toBe('Needs approval.');
});

it('opens an ability block, applying its header to every rule and include in it', function () {
    $expanded = expandSyntax(<<<'WARRANT'
        can they view, publish {
            if is_teacher they can
            if is_advisor they cannot because 'Locked.'
            @include grants_it
        }
        WARRANT);

    expect($expanded->rules)->toHaveCount(3);
    expect($expanded->rules[0]->canAbilities())->toBe(['view', 'publish']);
    expect($expanded->rules[1]->cannotAbilities())->toBe(['view', 'publish']);
    expect($expanded->rules[1]->messageFor('view'))->toBe('Locked.');
    expect($expanded->rules[2]->canAbilities())->toBe(['view', 'publish']);
});

it('opens an empty ability block to no rules', function () {
    expect(expandSyntax('can they view { }')->rules)->toBe([]);
});

it('gives the expanded rules the abilities the include named', function () {
    $expanded = expandSyntax('@include grants_it for view, publish');

    expect($expanded->rules[0]->canAbilities())->toBe(['view', 'publish']);
});

it('splices the expansion in where the include was written', function () {
    $expanded = expandSyntax(<<<'WARRANT'
        if is_teacher they can view
        @include grants_it for publish
        if is_advisor they can archive
        WARRANT);

    expect($expanded->rules)->toHaveCount(3);
    expect($expanded->rules[0]->conditions->conditionKey)->toBe('is_teacher');
    expect($expanded->rules[1]->canAbilities())->toBe(['publish']);
    expect($expanded->rules[1]->conditions)->toBeNull();
    expect($expanded->rules[2]->conditions->conditionKey)->toBe('is_advisor');
});

it('hands a template its arguments through bindings', function () {
    $expanded = expandSyntax("@include with_relation('folder') for view");

    expect($expanded->rules[0]->conditions->parameters)->toBe(['folder']);
});

it('expands a template that includes another', function () {
    $expanded = expandSyntax('@include nests_another for view');

    expect($expanded->rules)->toHaveCount(1);
    expect($expanded->rules[0])->toBeInstanceOf(WarrantRuleNode::class);
    expect($expanded->rules[0]->canAbilities())->toBe(['view']);
});

it('terminates a recursion whose argument decreases per level', function () {
    $expanded = expandSyntax('@include counts_down(3) for view');

    expect($expanded->rules)->toHaveCount(1);
    expect($expanded->rules[0]->canAbilities())->toBe(['view']);
});

it('leaves a set holding no includes exactly as it was', function () {
    $set = WarrantSyntax::parse("if is_teacher they can view\nthey cannot archive")->scopedTo('course_sections');

    $expanded = (new RuleSetExpander)->expand($set, new TemplateExpansionSchema);

    expect($expanded->schemaKey)->toBe($set->schemaKey);
    expect($expanded->rules)->toBe($set->entries);
});

it('refuses an expanded rule set holding anything but rules that name their abilities', function () {
    // What the compiler, reachability and diagnosis read can never hold shorthand
    // nobody expanded: an include, or a clause with no abilities to be about.
    expect(fn () => new ExpandedRuleSet('course_sections', [new IncludeInvocationNode('grants_it', [], ['view'])]))
        ->toThrow(InvalidArgumentException::class, 'holds rules alone');

    $generic = WarrantSyntax::parse('can they view { if is_teacher they can }')->scopedTo('course_sections')
        ->entries[0]->entries[0];

    expect(fn () => new ExpandedRuleSet('course_sections', [$generic]))
        ->toThrow(InvalidArgumentException::class, 'names the abilities it applies to');
});

// -- failures -----------------------------------------------------------------

it('bounds a recursion that cannot terminate, naming the chain', function () {
    expect(fn () => expandSyntax('@include loops for view'))
        ->toThrow(RuntimeException::class, 'maximum nesting depth');

    // The chain is reported so the templates responsible can be found.
    expect(fn () => expandSyntax('@include loops for view'))
        ->toThrow(RuntimeException::class, 'Expansion chain (outermost first)');
});

it('rejects an include naming a template the schema does not declare', function () {
    expect(fn () => expandSyntax('@include nope for view'))
        ->toThrow(InvalidArgumentException::class, 'declares no rule template [nope]');
});

it('rejects an include supplying too few arguments', function () {
    expect(fn () => expandSyntax("@include needs_two('a') for view"))
        ->toThrow(InvalidArgumentException::class, 'requires 2 argument(s), but the @include supplies 1');
});

// -- what a template may answer with --------------------------------------------

it('expands a template answering with a rule that names no abilities', function () {
    $expanded = expandSyntax('@include answers_with_a_rule for view');

    expect($expanded->rules[0]->canAbilities())->toBe(['view']);
    expect($expanded->rules[0]->conditions->conditionKey)->toBe('is_teacher');
});

it('expands a template answering with an include that names no abilities', function () {
    $expanded = expandSyntax('@include answers_with_an_include for view');

    expect($expanded->rules)->toHaveCount(1);
    expect($expanded->rules[0]->canAbilities())->toBe(['view']);
});

it('expands a template answering with an iterable of every form, in order', function () {
    $expanded = expandSyntax('@include answers_with_a_list for view');

    expect($expanded->rules)->toHaveCount(3);
    expect($expanded->rules[0]->conditions->conditionKey)->toBe('is_teacher');
    expect($expanded->rules[0]->canAbilities())->toBe(['view']);
    expect($expanded->rules[1]->cannotAbilities())->toBe(['view']);
    expect($expanded->rules[1]->messageFor('view'))->toBe('No.');
    expect($expanded->rules[2]->conditions)->toBeNull();
    expect($expanded->rules[2]->canAbilities())->toBe(['view']);
});

it('expands a template answering with an empty iterable to no rules', function () {
    expect(expandSyntax('@include answers_with_nothing for view')->rules)->toBe([]);
});

it('rejects a template answering with a rule that names abilities', function () {
    expect(fn () => expandSyntax('@include answers_with_a_named_rule for view'))
        ->toThrow(
            RuntimeException::class,
            'Rule template [TemplateExpansionSchema::answersWithANamedRule] answered with a rule that names '
                .'abilities; the @include that expands a template names the abilities its rules and includes take.',
        );
});

it('rejects a template answering with an include that names abilities', function () {
    expect(fn () => expandSyntax('@include answers_with_a_named_include for view'))
        ->toThrow(RuntimeException::class, 'answered with an @include that names abilities');
});

it('rejects a template answering with a rule set', function () {
    expect(fn () => expandSyntax('@include answers_with_a_rule_set for view'))
        ->toThrow(RuntimeException::class, 'answered with a rule set, which names abilities');
});

it('rejects a template answering with parsed rules that name abilities', function () {
    expect(fn () => expandSyntax('@include answers_with_parsed_named_rules for view'))
        ->toThrow(RuntimeException::class, 'answered with a rule that names abilities');
});

it('rejects a template answering with a parsed ability block', function () {
    expect(fn () => expandSyntax('@include answers_with_parsed_block for view'))
        ->toThrow(RuntimeException::class, 'answered with an ability block, which names abilities');
});

it('rejects a template answering with a for block', function () {
    expect(fn () => expandSyntax('@include answers_with_a_for_block for view'))
        ->toThrow(RuntimeException::class, 'answered with rule text that is not unscoped rules and includes');
});

it('rejects a template answering with something that is not rules', function () {
    expect(fn () => expandSyntax('@include wrong_return for view'))
        ->toThrow(
            RuntimeException::class,
            'Rule template [TemplateExpansionSchema::wrongReturn] must answer with rule text, a '
                .WarrantSyntax::class.', a rule or @include that names no abilities, or an iterable of them; got int.',
        );
});

it('rejects an ability block in a template body', function () {
    // A body is generic for the same reason a block's clauses are: the abilities
    // are settled by the reference, so the body has nothing to open a block over.
    expect(fn () => expandSyntax('@include opens_a_block for view'))->toThrow(
        RuntimeException::class,
        'Rule template [TemplateExpansionSchema::opensABlock] answered with an ability block, which names abilities',
    );
});

it('rejects a clause in a template body naming its own abilities', function () {
    expect(fn () => expandSyntax('@include names_an_ability for view'))->toThrow(
        RuntimeException::class,
        'Rule template [TemplateExpansionSchema::namesAnAbility] answered with a rule that names abilities',
    );
});

it('rejects abilities named on a cannot clause in a template body', function () {
    expect(fn () => expandSyntax('@include names_an_ability_in_a_cannot for view'))
        ->toThrow(RuntimeException::class, 'answered with a rule that names abilities');
});

it('rejects the wildcard named in a template body', function () {
    expect(fn () => expandSyntax('@include names_the_wildcard for view'))
        ->toThrow(RuntimeException::class, 'answered with a rule that names abilities');
});

it('rejects a template body mixing generic and named rules where the text mixes them', function () {
    expect(fn () => expandSyntax('@include mixes_naming for view'))
        ->toThrow(WarrantSyntaxException::class, 'This clause names abilities, but the rules before it name none');
});

// -- reachability -------------------------------------------------------------

it('counts an ability granted only through a template as reachable', function () {
    bindWarrantRules('@include grants_it for publish');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    // Without expansion the decision table would see no `can` at all and say NEVER.
    expect($guard->reachabilityOf('publish'))->toBe(Reachability::ALWAYS);
});

it('counts a conditional grant from a template as MAYBE', function () {
    bindWarrantRules('@include conditional_grant for view');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->reachabilityOf('view'))->toBe(Reachability::MAYBE);
});

it('counts an unconditional deny from a template as NEVER', function () {
    bindWarrantRules("they can archive\n@include hard_deny for archive");
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->reachabilityOf('archive'))->toBe(Reachability::NEVER);
});

it('recurses through nested templates when analyzing reachability', function () {
    bindWarrantRules('@include nests_another for publish');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->reachabilityOf('publish'))->toBe(Reachability::ALWAYS);
});

it('still reports an ability no template grants as NEVER', function () {
    bindWarrantRules('@include grants_it for publish');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->reachabilityOf('archive'))->toBe(Reachability::NEVER);
});

// -- the trail ----------------------------------------------------------------

it('derives a fresh trail per branch rather than sharing one', function () {
    // Each include side by side descends 41 levels, within the bound of 64. Shared,
    // the two would count 82 and be rejected; neither may see the other's descent.
    $set = WarrantSyntax::parse(<<<'WARRANT'
        @include counts_down(40) for view
        @include counts_down(40) for publish
        WARRANT)->scopedTo('course_sections');

    $expanded = (new RuleSetExpander)->expand($set, new TemplateExpansionSchema);

    expect($expanded->rules)->toHaveCount(2);
    expect($expanded->rules[0]->canAbilities())->toBe(['view']);
    expect($expanded->rules[1]->canAbilities())->toBe(['publish']);
});

// -- compiling ----------------------------------------------------------------

it('compiles a rule set through a template to the same SQL as its longhand', function () {
    bindWarrantRules('@include conditional_grant for view');
    $viaTemplate = warrantTestQuery();
    Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class)->filterQuery($viaTemplate, 'view');

    bindWarrantRules('if is_teacher they can view');
    $longhand = warrantTestQuery();
    Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class)->filterQuery($longhand, 'view');

    expect(normalizeWarrantSql($viaTemplate->toSql()))->toBe(normalizeWarrantSql($longhand->toSql()));
});

it('lets a template deny, the same as a rule written out', function () {
    bindWarrantRules("they can view\n@include hard_deny for view");
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->can('view'))->toBeFalse();
});

it('grants through a template', function () {
    bindWarrantRules('@include grants_it for publish');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->can('publish'))->toBeTrue();
});

it('compiles a recursion that terminates', function () {
    bindWarrantRules('@include counts_down(4) for publish');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->can('publish'))->toBeTrue();
});

it('bounds a runaway template in the guard\'s own rule set by the expansion depth limit', function () {
    /* The guard expands its rule set once, before any ability is compiled: the
       chain of templates is the whole story. */
    bindWarrantRules('@include loops for publish');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect(fn () => $guard->can('publish'))
        ->toThrow(RuntimeException::class, 'Expansion chain (outermost first)');
});

it('bounds a runaway template reached through a hop by the same expansion depth limit', function () {
    useWarrantSchemas([
        'course_sections' => TemplateExpansionSchema::class,
        'template_hops' => TemplateHopSchema::class,
    ]);

    $sets = [
        'course_sections' => WarrantSyntax::parse('if can(publish for template_hops) they can publish')
            ->scopedTo('course_sections'),
        'template_hops' => WarrantSyntax::parse('@include loops for publish')->scopedTo('template_hops'),
    ];

    app()->instance(RuleProvider::class, new class($sets) implements RuleProvider {
        /** @param array<string, RuleSetNode> $sets */
        public function __construct(private array $sets) {}

        public function rules(RuleProviderContext $context): RuleSetNode
        {
            return $this->sets[$context->schemaKey];
        }
    });

    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    /* The hop's rule set is expanded on its own guard, exactly as a top-level one
       is, so the error is the same whichever check first asked for it. */
    expect(fn () => $guard->can('publish'))
        ->toThrow(RuntimeException::class, 'Expansion chain (outermost first)');
});

it('expands a guard\'s rule set once, however many abilities are asked about', function () {
    bindWarrantRules('@include grants_it for view, publish');
    $guard = Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class);

    expect($guard->expandedRuleSet())->toBe($guard->expandedRuleSet());
    expect($guard->can(['view', 'publish']))->toBeTrue();
    expect($guard->reachabilityOf('publish'))->toBe(Reachability::ALWAYS);
});


// -- validation ---------------------------------------------------------------

function validateSyntax(string $syntax): void
{
    (new RuleSetValidator(new TemplateExpansionSchema, 'course_sections'))
        ->validate(WarrantSyntax::parse($syntax)->scopedTo('course_sections'));
}

it('accepts an include naming a template the schema declares', function () {
    expect(fn () => validateSyntax('can they view { @include requires_approval }'))->not->toThrow(Exception::class);
});

it('rejects an unknown template from rule text, before anything is expanded', function () {
    // The same rejection the expansion would make, reached without calling a
    // template or resolving an argument.
    expect(fn () => validateSyntax('@include nope for view'))
        ->toThrow(InvalidArgumentException::class, 'declares no rule template [nope]');
});

it('rejects too few include arguments from rule text', function () {
    expect(fn () => validateSyntax("@include needs_two('a') for view"))
        ->toThrow(InvalidArgumentException::class, 'requires 2 argument(s), but the @include supplies 1');
});

it('rejects an undeclared ability in an include for list', function () {
    expect(fn () => validateSyntax('@include grants_it for not_an_ability'))
        ->toThrow(InvalidArgumentException::class, 'Ability [not_an_ability] is not declared');
});

it('rejects an undeclared ability on an include whose template expands to nothing', function () {
    // No rule carries the ability, so it is checked as the include names it.
    expect(fn () => validateSyntax('@include empty_body for not_an_ability'))
        ->toThrow(InvalidArgumentException::class, 'Ability [not_an_ability] is not declared');
});

it('rejects an undeclared ability on an empty block', function () {
    expect(fn () => validateSyntax('can they not_an_ability { }'))
        ->toThrow(InvalidArgumentException::class, 'Ability [not_an_ability] is not declared');
});

it('rejects an undeclared ability on an include whose template expands to nothing, through the guard', function () {
    bindWarrantRules('@include empty_body for not_an_ability');

    expect(fn () => Warrant::guard(makeWarrantTestUser())->forSchema(TemplateExpansionSchema::class)->can('view'))
        ->toThrow(InvalidArgumentException::class, 'Ability [not_an_ability] is not declared');
});

it('rejects an undeclared ability on the block an include sits in', function () {
    expect(fn () => validateSyntax('can they not_an_ability { @include grants_it }'))
        ->toThrow(InvalidArgumentException::class, 'Ability [not_an_ability] is not declared');
});

it('still validates the rules around an include', function () {
    expect(fn () => validateSyntax(<<<'WARRANT'
        can they view {
            @include requires_approval
            if no_such_condition they can
        }
        WARRANT))
        ->toThrow(InvalidArgumentException::class, 'no_such_condition');
});

it('reports a mistake inside a template body, which it reads through the expansion', function () {
    expect(fn () => validateSyntax('@include opens_a_block for view'))
        ->toThrow(RuntimeException::class, 'answered with an ability block, which names abilities');

    expect(fn () => validateSyntax('@include names_an_unknown_condition for view'))
        ->toThrow(InvalidArgumentException::class, 'Condition [no_such_condition] is not declared by the schema');
});
