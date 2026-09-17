<?php

use Warrant\Schema\Ability;
use Warrant\Schema\Conditions\RowConditionContext;
use Warrant\Facades\Warrant;
use Warrant\Rules\WarrantRuleTemplate;
use Warrant\Schema\RowCondition;
use Warrant\Schema\RuleTemplate;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

class TemplatedSchema extends WarrantSchema
{
    #[Ability] public const VIEW = 'view';

    #[RuleTemplate]
    public function requiresApproval(): string
    {
        return "if not is_approved they cannot because 'Needs approval.'";
    }

    #[RuleTemplate('inherited')]
    public function inheritedFrom(string $relation, int $depth = 1): WarrantRuleTemplate
    {
        return Warrant::ruleTemplate(
            'if is_child_of(:relation, :depth) they can',
            ['relation' => $relation, 'depth' => $depth],
        );
    }

    #[RowCondition]
    public function isApproved(RowConditionContext $c): string
    {
        return '1 = 1';
    }
}

it('discovers a rule template from the method name', function () {
    $definition = (new TemplatedSchema)->getRuleTemplateDefinition('requires_approval');

    expect($definition->key)->toBe('requires_approval');
    expect($definition->methodName)->toBe('requiresApproval');
    expect($definition->requiredArgumentCount)->toBe(0);
});

it('takes an explicit key from the attribute', function () {
    $schema = new TemplatedSchema;

    expect($schema->getRuleTemplateDefinition('inherited')->methodName)->toBe('inheritedFrom');
    expect($schema->getRuleTemplateDefinition('inherited_from'))->toBeNull();
});

it('counts every parameter without a default as a required argument', function () {
    // A template takes no leading context object, so unlike a condition none of
    // its parameters are dropped from the count.
    expect((new TemplatedSchema)->getRuleTemplateDefinition('inherited')->requiredArgumentCount)->toBe(1);
});

it('returns null for a template the schema does not declare', function () {
    expect((new TemplatedSchema)->getRuleTemplateDefinition('nope'))->toBeNull();
});

it('lists every declared template key, sorted', function () {
    expect(TemplatedSchema::ruleTemplateKeys())->toBe(['inherited', 'requires_approval']);
});

it('keeps templates out of the condition vocabulary and conditions out of templates', function () {
    expect(TemplatedSchema::conditionKeys())->toBe(['is_approved']);
    expect((new TemplatedSchema)->getConditionDefinition('requires_approval'))->toBeNull();
    expect((new TemplatedSchema)->getRuleTemplateDefinition('is_approved'))->toBeNull();
});

// -- rejections ---------------------------------------------------------------

class UntypedTemplateSchema extends WarrantSchema
{
    #[RuleTemplate]
    public function loose()
    {
        return 'they can';
    }
}

class TemplateAndConditionSchema extends WarrantSchema
{
    #[RuleTemplate]
    #[RowCondition]
    public function both(RowConditionContext $c): string
    {
        return '1 = 1';
    }
}

class DuplicateTemplateKeySchema extends WarrantSchema
{
    #[RuleTemplate('shared')]
    public function one(): string
    {
        return 'they can';
    }

    #[RuleTemplate('shared')]
    public function two(): string
    {
        return 'they can';
    }
}

it('declares no return type of its own, as a condition does not', function () {
    // What a template answered with is checked at the expansion that reads it,
    // where the value exists; discovery only needs the key and the arity.
    expect(UntypedTemplateSchema::ruleTemplateKeys())->toBe(['loose']);
});

it('rejects a method that is both a condition and a template', function () {
    expect(fn () => TemplateAndConditionSchema::ruleTemplateKeys())
        ->toThrow(InvalidArgumentException::class, 'cannot be both a condition and a rule template');
});

it('rejects two templates sharing a key', function () {
    expect(fn () => DuplicateTemplateKeySchema::ruleTemplateKeys())
        ->toThrow(InvalidArgumentException::class, 'declares rule template [shared] more than once');
});

it('rejects an empty template key on the attribute', function () {
    expect(fn () => new RuleTemplate(''))
        ->toThrow(InvalidArgumentException::class, 'RuleTemplate key cannot be empty');
});

// -- the body -----------------------------------------------------------------

it('answers with a plain string when the body has no placeholders', function () {
    expect((new TemplatedSchema)->requiresApproval())
        ->toBe("if not is_approved they cannot because 'Needs approval.'");
});

it('carries a template body as syntax plus its bindings', function () {
    $body = (new TemplatedSchema)->inheritedFrom('folder', 2);

    expect($body)->toBeInstanceOf(WarrantRuleTemplate::class);
    expect($body->syntax)->toBe('if is_child_of(:relation, :depth) they can');
    expect($body->bindings)->toBe(['relation' => 'folder', 'depth' => 2]);
});

it('builds a body through the facade, with no bindings by default', function () {
    $body = Warrant::ruleTemplate('if not is_approved they cannot');

    expect($body->syntax)->toBe('if not is_approved they cannot');
    expect($body->bindings)->toBe([]);
});

it('takes a closure denial message through a binding', function () {
    // A closure has no inline form; a binding is the only way it reaches the DSL,
    // which is why a template body has to be able to carry one.
    $message = fn () => 'nope';
    $body = Warrant::ruleTemplate('they cannot because :why', ['why' => $message]);

    expect($body->bindings['why'])->toBe($message);
});
