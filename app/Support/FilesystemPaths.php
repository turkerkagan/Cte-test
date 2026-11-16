<?php

namespace App\Support;

use InvalidArgumentException;

/** Normalize filesystem-generated paths; user input must still pass its path guards. */
final class FilesystemPaths
{
    public static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function relativeTo(string $root, string $path): string
    {
        $prefix = rtrim(self::normalize($root), '/').'/';
        $normalized = self::normalize($path);
        if (! str_starts_with($normalized, $prefix)) {
            throw new InvalidArgumentException('File is outside the expected directory.');
        }

        return substr($normalized, strlen($prefix));
    }
}
