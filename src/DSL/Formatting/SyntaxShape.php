<?php

namespace Warrant\DSL\Formatting;

use Warrant\DSL\Parsing\ASTNodes\INode;
use Warrant\DSL\Parsing\ASTNodes\WarrantSyntax;

/**
 * A tree with where it was written taken out: what two texts must share to say
 * the same thing.
 */
final class SyntaxShape
{
    /**
     * The shape of $syntax: every node's class and properties, all the way down,
     * without positions.
     */
    public static function of(WarrantSyntax $syntax): array
    {
        return array_map(self::shapeOf(...), $syntax->children);
    }

    private static function shapeOf(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::shapeOf(...), $value);
        }

        if (! $value instanceof INode) {
            return $value;
        }

        $shape = ['node' => $value::class];

        foreach (get_object_vars($value) as $property => $propertyValue) {
            if ($property !== 'location') {
                $shape[$property] = self::shapeOf($propertyValue);
            }
        }

        return $shape;
    }
}
