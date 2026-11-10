<?php

use Warrant\DSL\Parsing\BoundSyntax;

if (! function_exists('warrant')) {
    /**
     * Rule text with the values for its placeholders, as a schema's `rules()`, the
     * rule provider, a rule template or a derived condition may answer with.
     * `Warrant::parse()` reads one into a tree.
     *
     *     return warrant('if in_team(:team) they can view', ['team' => $team]);
     *
     * Where it is called is kept, so an error in the text can point at the line
     * of PHP that wrote it, wherever that is.
     *
     * @param array<string, mixed> $bindings Values for `:name` placeholders, by name.
     */
    function warrant(string $syntax, array $bindings = []): BoundSyntax
    {
        $call = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];

        return new BoundSyntax($syntax, $bindings, calledInFile: $call['file'] ?? null, calledOnLine: $call['line'] ?? null);
    }
}

if (! function_exists('warrant_file')) {
    /**
     * The rule text in a file, with the values for its placeholders: the
     * `.warrant` extension may be left off. `Warrant::parseFile()` reads one
     * into a tree.
     *
     *     return warrant_file(resource_path('warrant/timesheets'));
     *
     * @param array<string, mixed> $bindings Values for `:name` placeholders, by name.
     */
    function warrant_file(string $path, array $bindings = []): BoundSyntax
    {
        return BoundSyntax::fromFile($path, $bindings);
    }
}
