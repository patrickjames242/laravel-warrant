<?php

declare(strict_types=1);

namespace Warrant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Warrant\Schema\WarrantSchema;

final readonly class RuleResolutionContext
{
    /**
     * @param  class-string<WarrantSchema>  $schema
     * @param  class-string<Model>|null  $model
     */
    public function __construct(
        public string $schemaKey,
        public string $schema,
        public ?Authenticatable $user,
        public ?string $model = null,
    ) {}
}
