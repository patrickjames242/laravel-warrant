<?php

declare(strict_types=1);

namespace Warrant\Rules;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;

/**
 * What a rule provider is told about the rules it is asked for: the schema they
 * govern and the user they are resolved for.
 *
 * `user` is null when the rules are resolved for no particular user, as they are
 * for a filter over users, which answers for every user at once. Rules that
 * differ from one user to the next can only answer for one user at a time, so a
 * provider gets the most out of Warrant by returning the same rules for everyone
 * and leaving the user to the conditions those rules name.
 */
final readonly class RuleProviderContext
{
    /**
     * @param  class-string<IAbilitySchema>  $schema
     * @param  Authenticatable|null  $user  The user the rules are resolved for;
     *   null when they are resolved for no particular user.
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public string $schemaKey,
        public string $schema,
        public ?Authenticatable $user,
        public ?string $model = null,
    ) {}
}
