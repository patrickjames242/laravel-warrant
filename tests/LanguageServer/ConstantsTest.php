<?php

require_once __DIR__.'/Support.php';

use Warrant\LanguageServer\Features\CodeActions;
use Warrant\LanguageServer\Features\Completion;
use Warrant\LanguageServer\Features\Definition;
use Warrant\LanguageServer\Features\Diagnostics;
use Warrant\LanguageServer\Features\Hover;
use Warrant\LanguageServer\Features\Usages;

/**
 * What hovering at the `|` in the `.warrant` text $source shows.
 */
function constantHover(string $source): ?string
{
    [$text, $position] = positionOfBar($source);
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    return Hover::at($workspace, $document, $position)['contents']['value'] ?? null;
}

/**
 * The labels completion offers at the `|` in the `.warrant` text $source.
 *
 * @return list<string>
 */
function constantCompletions(string $source): array
{
    [$text, $position] = positionOfBar($source);
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    return array_column(Completion::at($workspace, $document, $position), 'label');
}

/**
 * The titles of the quick fixes for the diagnostics of the `.warrant` text $text.
 *
 * @return list<string>
 */
function constantFixes(string $text): array
{
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    return array_column(CodeActions::for($workspace, $document, ['diagnostics' => Diagnostics::of($workspace, $document)]), 'title');
}

it('offers the constants a bare name in an argument may mean, abilities included', function () {
    $labels = constantCompletions('for documents { if in_team(| }');

    expect($labels)->toContain('@context', 'tenant_source', 'view', 'edit')
        ->not->toContain('invoice_prefix');
});

it('offers a schema\'s own constants and abilities after its key and a dot', function () {
    expect(constantCompletions('for documents { if in_team(billing.| }'))
        ->toBe(['invoice_prefix', 'ledger', 'managers', 'computed', 'status', 'manage']);
});

it('offers the abilities and the constants that may name them where abilities go', function () {
    expect(constantCompletions('for billing { they can | }'))->toBe(['manage', 'managers', 'computed', 'status', '*']);
});

it('describes a constant on hover, with its value and docblock', function () {
    expect(constantHover('for documents { if in_team(billing.invoice_pre|fix) they can view }'))
        ->toBe("**constant `invoice_prefix` on `billing`**\n\n`'inv'`\n\nThe prefix every invoice number starts with.")
        ->and(constantHover('for documents { if in_team(tenant_sour|ce) they can view }'))
        ->toBe("**constant `tenant_source` on global schema `App\\Schemas\\TenancySchema`**\n\n`'tenancy'`")
        ->and(constantHover('for documents { if in_team(billing.compu|ted) they can view }'))
        ->toBe('**constant `computed` on `billing`**');
});

it('describes a group in an ability list as the constant it is, and a prefixed ability as the ability', function () {
    expect(constantHover('for billing { if in_current_tenant they can manag|ers }'))
        ->toBe("**constant `managers` on `billing`**\n\n`['manage', 'audit', 'review']`")
        ->and(constantHover('for documents { if in_team(billing.man|age) they can view }'))
        ->toBe('**ability `manage` on `billing`**');
});

it('jumps to the PHP constant a constant names, bare in an ability list or prefixed', function () {
    foreach (['for billing { if in_current_tenant they can manag|ers }', 'for documents { if in_team(billing.manag|ers) they can view }'] as $source) {
        [$text, $position] = positionOfBar($source);
        [$workspace, $document] = openInFixture('rules.warrant', $text);
        $location = Definition::at($workspace, $document, $position);

        expect($location['uri'])->toEndWith('BillingSchema.php')
            ->and($location['range']['start']['line'])->toBe(23);
    }
});

it('finds every use of a constant, bare in an ability list and prefixed in an argument', function () {
    [$workspace] = openInFixture(
        'open.warrant',
        "for billing { if in_current_tenant they can managers }\nfor documents { if in_team(billing.managers) they can view }",
    );

    $found = Usages::of($workspace, fixtureRoot().'/app/Schemas/BillingSchema.php', 'MANAGERS');

    expect($found['kind'])->toBe('constant')
        ->and($found['name'])->toBe('managers')
        ->and($found['taken'])->toBe([])
        ->and(array_map(static fn (array $usage): array => $usage['range']['start'], $found['usages']))->toBe([
            ['line' => 0, 'character' => 44],
            ['line' => 1, 'character' => 35],
        ]);
});

it('names the constant a name in an ability list stands for as its declaration', function () {
    [$text, $position] = positionOfBar('for billing { if in_current_tenant they can manag|ers }');
    [$workspace, $document] = openInFixture('rules.warrant', $text);

    expect(Usages::declarationAt($workspace, $document, $position))->toMatchArray(['kind' => 'constant', 'name' => 'managers', 'member' => 'MANAGERS']);
});

it('offers the closest constants for one that is not declared, bare or prefixed', function () {
    expect(constantFixes('for documents { if in_team(tenant_sourc) they can view }'))->toBe(["Change to 'tenant_source'"])
        ->and(constantFixes('for documents { if in_team(billing.invoice_prefx) they can view }'))->toBe(["Change to 'invoice_prefix'"]);
});
