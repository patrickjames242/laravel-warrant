<?php

declare(strict_types=1);

namespace Warrant\Rules;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warrant\Schema\Types\Interfaces\IAbilitySchema;

final readonly class RuleProviderContext
{
    /**
     * @param  class-string<IAbilitySchema>  $schema
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public string $schemaKey,
        public string $schema,
        public ?Authenticatable $user,
        public ?string $model = null,
    ) {}
}
