<?php

namespace Warrant\LanguageServer\Documents;

/**
 * Converts between the `file://` URIs an editor names documents by and paths on
 * disk.
 */
final class Uri
{
    /**
     * The path a `file://` URI names, or null for a URI of another scheme.
     *
     * @param string $uri The URI.
     */
    public static function toPath(string $uri): ?string
    {
        if (! str_starts_with($uri, 'file://')) {
            return null;
        }

        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        // A Windows drive path arrives as `/c:/...`.
        if (preg_match('#^/[A-Za-z]:#', $path) === 1) {
            $path = substr($path, 1);
        }

        return $path;
    }

    /**
     * The `file://` URI of $path.
     *
     * @param string $path An absolute path.
     */
    public static function fromPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (preg_match('#^[A-Za-z]:#', $path) === 1) {
            $path = '/'.$path;
        }

        return 'file://'.implode('/', array_map(
            static fn (string $segment): string => preg_match('#^/?[A-Za-z]:$#', $segment) === 1 ? $segment : rawurlencode($segment),
            explode('/', $path),
        ));
    }
}
