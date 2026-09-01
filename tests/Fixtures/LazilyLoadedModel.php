<?php

declare(strict_types=1);

namespace Warrant\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Warrant\HasWarrantSchema;

/**
 * Lives in its own autoloaded file, and is referenced by nothing except
 * {@see LazilyLoadedSchema} and the laziness test, so whether it has been loaded
 * is a meaningful assertion.
 */
class LazilyLoadedModel extends Model
{
    use HasWarrantSchema;

    protected $table = 'course_sections';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function warrantSchema(): string
    {
        return LazilyLoadedSchema::class;
    }
}
