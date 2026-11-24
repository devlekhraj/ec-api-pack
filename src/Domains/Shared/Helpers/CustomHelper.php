<?php

declare(strict_types=1);

namespace EcApi\Domains\Shared\Helpers;

class CustomHelper
{
    public static function humanFileSize(int|string $bytes, int $decimals = 2): string
    {
        $bytes = (int) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        if ($bytes === 0) {
            return '0 B';
        }

        $factor = (int) floor(log($bytes, 1024));
        $factor = max(0, min($factor, count($units) - 1));

        return sprintf(
            "%." . $decimals . "f %s",
            $bytes / pow(1024, $factor),
            $units[$factor]
        );
    }
}
