<?php

return [
    /*
     * The rule resolver: a class implementing Warrant\RuleResolver.
     *
     * Warrant ships no default — you must provide one. It maps the current user
     * to the WarrantRuleSet that governs their access to an entity, built from
     * wherever your access rules live: role/permission tables, JWT claims, a
     * remote service, config, etc.
     */
    'rule_resolver' => null,

    /*
     * The Warrant schemas registered with the application. Registration is
     * explicit: every schema that governs a resource must be listed here. A
     * schema that is not listed is unknown to access checks and lookups.
     */
    'schemas' => [
        // App\Schemas\PostSchema::class,
    ],

    /*
     * When true, Warrant registers a Gate::before hook so its abilities resolve
     * through Laravel's Gate — $user->can(), @can, Gate::authorize, and the
     * `can:` route middleware. Abilities that no registered schema declares fall
     * through untouched to your own policies. Set false to opt out entirely.
     */
    'register_gate' => true,
];
