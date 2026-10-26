<?php

namespace Warrant\Schema\Context;

/**
 * A schema's context policy: the context a check gets without being given it,
 * and the keys a check must have.
 */
interface IProvidesContext
{
    /**
     * Context merged under whatever context a check is given, so explicit values
     * win. It is how a check that takes no context, such as route middleware or a
     * query scope, still receives a tenant id.
     *
     * @return array<string, mixed>
     */
    public function defaultContext(): array;

    /**
     * Keys every check against this schema must have, after the default context
     * is merged in.
     *
     * @return list<string>
     */
    public function requiredContextKeys(): array;
}
