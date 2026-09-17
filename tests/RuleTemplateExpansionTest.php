<?php

require_once __DIR__.'/Support/TestSupport.php';

use Illuminate\Database\Eloquent\Model;
use Warrant\DSL\Parsing\WarrantSyntaxException;
use Warrant\Facades\Warrant;
use Warrant\HasWarrantSchema;
use Warrant\Reachability;
use Warrant\Rules\IncludeInvocation;
use Warrant\Rules\IncludeTrail;
use Warrant\Rules\RuleTemplateExpander;
use Warrant\Rules\WarrantRule;
use Warrant\Rules\WarrantRuleSet;
use Warrant\Rules\WarrantRuleTemplate;
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
    public function withRelation(string $relation): WarrantRuleTemplate
    {
        return Warrant::ruleTemplate('if is_teacher(:relation) they can', ['relation' => $relation]);
    }

    /** Terminates because the argument decreases at every level. */
    #[RuleTemplate]
    public function countsDown(int $n): string|WarrantRuleTemplate
    {
        return $n <= 0
            ? 'they can'
            : Warrant::ruleTemplate('@include counts_down(:n)', ['n' => $n - 1]);
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
    public function wrongReturn(): array
    {
        return [];
    }

    #[RuleTemplate]
    public function opensABlock(): string
    {
        return 'view { if is_teacher they can }';
    }

    #[RuleTemplate]
    public function namesAnAbility(): string
    {
        return 'if is_teacher they can view';
    }
}

/**
 * A trail with a bound and a message of its own, standing in for the compiling
 * one so the seam is exercised without a compile.
 */
final readonly class ShallowTrail implements IncludeTrail
{
    public function __construct(public int $depth = 0)
    {
    }

    public function entering(IncludeInvocation $include): static
    {
        if ($this->depth >= 3) {
            throw new RuntimeException("ShallowTrail stopped at depth 3 in [{$include->templateKey}].");
        }

        return new self($this->depth + 1);
    }
}

function expandSyntax(string $syntax): WarrantRuleSet
{
    return (new RuleTemplateExpander)->expand(
        WarrantRuleSet::fromSyntax($syntax, 'course_sections'),
        new TemplateExpansionSchema,
    );
}

beforeEach(function () {
    useWarrantSchemas(['course_sections' => TemplateExpansionSchema::class]);
});

// -- expansion ----------------------------------------------------------------

it('expands an include into the rules its longhand would produce', function () {
    $expanded = expandSyntax('view { @include requires_approval }');
    $longhand = WarrantRuleSet::fromSyntax("if is_advisor they cannot view because 'Needs approval.'", 'course_sections');

    expect($expanded->rules)->toHaveCount(1);
    expect($expanded->rules[0])->toBeInstanceOf(WarrantRule::class);
    expect($expanded->rules[0]->cannotAbilities())->toBe($longhand->rules[0]->cannotAbilities());
    expect($expanded->rules[0]->messageFor('view'))->toBe('Needs approval.');
});

it('gives the expanded rules the abilities the include named', function () {
    $expanded = expandSyntax('@include grants_it for view, publish');

    expect($expanded->rules[0]->canAbilities)->toBe(['view', 'publish']);
});

it('splices the expansion in where the include was written', function () {
    $expanded = expandSyntax(<<<'WARRANT'
        if is_teacher they can view
        @include grants_it for publish
        if is_advisor they can archive
        WARRANT);

    expect($expanded->rules)->toHaveCount(3);
    expect($expanded->rules[0]->conditions->conditionKey)->toBe('is_teacher');
    expect($expanded->rules[1]->canAbilities)->toBe(['publish']);
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
    expect($expanded->rules[0])->toBeInstanceOf(WarrantRule::class);
    expect($expanded->rules[0]->canAbilities)->toBe(['view']);
});

it('terminates a recursion whose argument decreases per level', function () {
    $expanded = expandSyntax('@include counts_down(3) for view');

    expect($expanded->rules)->toHaveCount(1);
    expect($expanded->rules[0]->canAbilities)->toBe(['view']);
});

it('leaves a set holding no includes exactly as it was', function () {
    $set = WarrantRuleSet::fromSyntax("if is_teacher they can view\nthey cannot archive", 'course_sections');

    $expanded = (new RuleTemplateExpander)->expand($set, new TemplateExpansionSchema);

    expect($expanded->schemaKey)->toBe($set->schemaKey);
    expect($expanded->rules)->toBe($set->rules);
});

// -- failures -----------------------------------------------------------------

it('bounds a recursion that cannot terminate, naming the chain', function () {
    expect(fn () => expandSyntax('@include loops for view'))
        ->toThrow(RuntimeException::class, 'maximum nesting depth');

    // The chain is reported so the templates responsible can be found.
    expect(fn () => expandSyntax('@include loops for view'))
        ->toThrow(RuntimeException::class, 'Include chain (outermost first)');
});

it('rejects an include naming a template the schema does not declare', function () {
    expect(fn () => expandSyntax('@include nope for view'))
        ->toThrow(RuntimeException::class, 'declares no rule template [nope]');
});

it('rejects an include supplying too few arguments', function () {
    expect(fn () => expandSyntax("@include needs_two('a') for view"))
        ->toThrow(RuntimeException::class, 'requires 2 argument(s), but the @include supplies 1');
});

it('rejects a template answering with neither a string nor a body', function () {
    expect(fn () => expandSyntax('@include wrong_return for view'))
        ->toThrow(RuntimeException::class, 'must answer with a string or a');
});

it('rejects an ability block inside a template body', function () {
    // A body is headless for the same reason a block's clauses are: the abilities
    // are settled by the reference, so the body has nothing to open a block over.
    expect(fn () => expandSyntax('@include opens_a_block for view'))
        ->toThrow(WarrantSyntaxException::class, 'may not contain another');
});

it('rejects a clause inside a template body naming its own abilities', function () {
    expect(fn () => expandSyntax('@include names_an_ability for view'))
        ->toThrow(WarrantSyntaxException::class, 'may not name abilities');
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

it('takes a caller\'s own trail, letting it bound and report the descent', function () {
    $set = WarrantRuleSet::fromSyntax('@include loops for view', 'course_sections');

    expect(fn () => (new RuleTemplateExpander)->expand($set, new TemplateExpansionSchema, new ShallowTrail))
        ->toThrow(RuntimeException::class, 'ShallowTrail stopped at depth 3');
});

it('derives a fresh trail per branch rather than sharing one', function () {
    // Two includes side by side each descend one level; neither sees the other's,
    // so a shallow bound that admits one admits both.
    $set = WarrantRuleSet::fromSyntax(<<<'WARRANT'
        @include grants_it for view
        @include grants_it for publish
        WARRANT, 'course_sections');

    $expanded = (new RuleTemplateExpander)->expand($set, new TemplateExpansionSchema, new ShallowTrail);

    expect($expanded->rules)->toHaveCount(2);
    expect($expanded->rules[0]->canAbilities)->toBe(['view']);
    expect($expanded->rules[1]->canAbilities)->toBe(['publish']);
});
