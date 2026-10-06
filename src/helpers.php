<?php

use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

if (! function_exists('warrant')) {
    /**
     * Parse Warrant rule text of any form. The same call as `Warrant::parse()`.
     *
     *     warrant('is_owner or in_team(:team)', ['team' => $team])->conditionExpression()
     *
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     */
    function warrant(string $syntax, array $bindings = []): WarrantSyntax
    {
        return WarrantSyntax::parse($syntax, $bindings);
    }
}

if (! function_exists('warrant_file')) {
    /**
     * Parse the Warrant rule text in a file. The same call as `Warrant::parseFile()`:
     * the `.warrant` extension may be left off.
     *
     *     warrant_file(resource_path('warrant/timesheets'))->ruleSet()
     *
     * @param array<int|string, mixed> $bindings Values for `:name` / `?` placeholders.
     */
    function warrant_file(string $path, array $bindings = []): WarrantSyntax
    {
        return WarrantSyntax::parseFile($path, $bindings);
    }
}
