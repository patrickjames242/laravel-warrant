<?php

use Warrant\DSL\Parsing\WarrantParser;
use Warrant\LanguageServer\Positions\CursorContext;
use Warrant\LanguageServer\Positions\SlotKind;
use Warrant\LanguageServer\Vocabulary\IndexedSchema;
use Warrant\LanguageServer\Vocabulary\VocabularyIndex;

/**
 * The context at the `|` in $source, which is taken out before it is read.
 *
 * @param string|null $schemaKey The schema rules with no `for` header are about.
 */
function contextAtBar(string $source, ?VocabularyIndex $catalog = null, ?string $schemaKey = null): CursorContext
{
    $offset = strpos($source, '|');
    $text = str_replace('|', '', $source);

    return CursorContext::at(WarrantParser::analyze($text)->syntax, (int) $offset, $catalog, $schemaKey);
}

/**
 * The slot, name and declaring schema of a context.
 *
 * @return array{0: ?SlotKind, 1: ?string, 2: ?string}
 */
function slotOf(CursorContext $context): array
{
    return [$context->slot, $context->name, $context->nameSchemaKey];
}

it('names a condition, and the schema of the body it is written in', function () {
    expect(slotOf(contextAtBar('for docs { if is_ow|ner they can view }')))
        ->toBe([SlotKind::Condition, 'is_owner', 'docs']);
});

it('names a condition in rules with no header after the schema given for them', function () {
    expect(slotOf(contextAtBar('if is_owner| they can view', schemaKey: 'docs')))
        ->toBe([SlotKind::Condition, 'is_owner', 'docs'])
        ->and(slotOf(contextAtBar('if is_owner| they can view')))
        ->toBe([SlotKind::Condition, 'is_owner', null]);
});

it('names an ability in a clause, a block header and an include as the schema in scope', function () {
    expect(slotOf(contextAtBar('for docs { they can view, ed|it }')))->toBe([SlotKind::Ability, 'edit', 'docs'])
        ->and(slotOf(contextAtBar('for docs { can they vi|ew { if a they can } }')))->toBe([SlotKind::Ability, 'view', 'docs'])
        ->and(slotOf(contextAtBar('for docs { @include owners for sha|re }')))->toBe([SlotKind::Ability, 'share', 'docs']);
});

it('names the names in a when block, and in its condition, as the schema in scope', function () {
    expect(slotOf(contextAtBar('for docs { when is_ow|ner { they can view } }')))->toBe([SlotKind::Condition, 'is_owner', 'docs'])
        ->and(slotOf(contextAtBar('for docs { when is_owner { if in_te|am they can view } otherwise { they cannot view } }')))->toBe([SlotKind::Condition, 'in_team', 'docs'])
        ->and(slotOf(contextAtBar('for docs { when a { they can view } otherwise { they cannot ed|it } }')))->toBe([SlotKind::Ability, 'edit', 'docs']);
});

it('names the ability of a can(...) as the schema it crosses to, or the one in scope', function () {
    expect(slotOf(contextAtBar('for docs { if can(vi|ew for folders(1)) they can view }')))->toBe([SlotKind::Ability, 'view', 'folders'])
        ->and(slotOf(contextAtBar('for docs { if can(ed|it) they can view }')))->toBe([SlotKind::Ability, 'edit', 'docs']);
});

it('names schemas, templates and context keys', function () {
    expect(slotOf(contextAtBar('for do|cs { they can view }')))->toBe([SlotKind::Schema, 'docs', null])
        ->and(slotOf(contextAtBar('for docs { if can(view for fold|ers) they can view }')))->toBe([SlotKind::Schema, 'folders', null])
        ->and(slotOf(contextAtBar('for docs { @include own|ers for view }')))->toBe([SlotKind::Template, 'owners', 'docs'])
        ->and(slotOf(contextAtBar('for docs { if in_org(@context o|rg) they can view }')))->toBe([SlotKind::ContextKey, 'org', 'docs'])
        ->and(slotOf(contextAtBar('for docs { if can(view for folders with te|am = 1) they can view }')))->toBe([SlotKind::ContextKey, 'team', 'folders']);
});

it('reads a check(...) predicate as about the schema the check names', function () {
    $context = contextAtBar('for docs { if check(is_sha|red for folders(1)) they can view }');

    expect(slotOf($context))->toBe([SlotKind::Condition, 'is_shared', 'folders'])
        ->and($context->scope->schemaKey)->toBe('folders')
        ->and($context->scope->frames)->toBe(['docs' => 'docs', 'folders' => 'folders']);
});

it('puts a frame a check(...) names under its alias, keeping the outer one', function () {
    $context = contextAtBar('for docs { if check(owned_by(@column d2.ow|ner) for docs(1) as d2) they can view }');

    expect(slotOf($context))->toBe([SlotKind::Column, 'owner', 'docs'])
        ->and($context->scope->frames)->toBe(['docs' => 'docs', 'd2' => 'docs'])
        ->and(slotOf(contextAtBar('for docs { if check(owned_by(@column d|2.owner) for docs(1) as d2) they can view }')))
        ->toBe([SlotKind::Alias, 'd2', 'docs']);
});

it('reads a handle\'s arguments in the scope around it', function () {
    expect(contextAtBar('for docs { if check(is_shared for folders(@column ow|ner)) they can view }')->scope->schemaKey)
        ->toBe('docs');
});

it('makes no frame of a schema with no rows', function () {
    $catalog = new VocabularyIndex(['billing' => new IndexedSchema('billing', 'Billing', hasRows: false)]);

    expect(contextAtBar('for billing { if is_pa|id they can view }', $catalog)->scope->frames)->toBe([])
        ->and(contextAtBar('for billing { if is_pa|id they can view }', $catalog)->scope->rowSchemaKey)->toBeNull();
});

it('is on no slot over a keyword or between entries, but still knows the scope there', function () {
    $context = contextAtBar("for docs {\n  if a they can view\n  |\n}");

    expect(slotOf($context))->toBe([null, null, null])
        ->and($context->scope->schemaKey)->toBe('docs')
        ->and(slotOf(contextAtBar('for docs { i|f a they can view }')))->toBe([null, null, null]);
});
