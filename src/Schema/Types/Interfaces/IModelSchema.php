<?php

namespace Warrant\Schema\Types\Interfaces;

use Illuminate\Database\Eloquent\Model;

/**
 * A schema whose rows are an Eloquent model's table.
 */
interface IModelSchema extends IRowSchema
{
    /**
     * The model whose table holds the rows.
     *
     * Static, because the registry and {@see \Warrant\HasWarrantSchema} pair a
     * model class with its schema class without building either.
     *
     * @return class-string<Model>
     */
    public static function model(): string;
}
