<?php

use Warrant\Schema\Ability;
use Warrant\Schema\DeclaresAbility;
use Warrant\Schema\WarrantSchema;

require_once __DIR__.'/Support/TestSupport.php';

/**
 * An ability attribute of the author's own: it derives the required context from
 * how it was constructed instead of restating a literal list on every constant.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class TenantAbility implements DeclaresAbility
{
    public function __construct(private bool $scopedToBranch = false) {}

    public function requiredContext(): array
    {
        return $this->scopedToBranch ? ['tenant_id', 'branch_id'] : ['tenant_id'];
    }
}

class CustomAttributeSchema extends WarrantSchema
{
    #[Ability] public const VIEW = 'view';
    #[TenantAbility] public const EDIT = 'edit';
    #[TenantAbility(scopedToBranch: true)] public const AUDIT = 'audit';
}

class DoublyDeclaredAbilitySchema extends WarrantSchema
{
    #[Ability]
    #[TenantAbility]
    public const VIEW = 'view';
}

it('recognizes an author-declared ability attribute alongside #[Ability]', function () {
    expect(CustomAttributeSchema::abilityNames())->toBe(['view', 'edit', 'audit']);
});

it('takes the required context from whatever the attribute returns', function () {
    $schema = new CustomAttributeSchema;

    expect($schema->getAbilityDefinition('view')->requiredContext)->toBe([]);
    expect($schema->getAbilityDefinition('edit')->requiredContext)->toBe(['tenant_id']);
    expect($schema->getAbilityDefinition('audit')->requiredContext)->toBe(['tenant_id', 'branch_id']);
});

it('enforces a custom attribute\'s required context like any other', function () {
    expect(CustomAttributeSchema::partitionAbilitiesByContext(
        ['view', 'edit', 'audit'],
        ['tenant_id' => 't-1'],
    ))->toBe([
        'satisfied' => ['view', 'edit'],
        'missing' => ['audit' => ['branch_id']],
    ]);
});

it('rejects a constant carrying two ability attributes', function () {
    expect(fn () => DoublyDeclaredAbilitySchema::abilityDefinitions())
        ->toThrow(InvalidArgumentException::class, 'declares more than one ability attribute');
});
