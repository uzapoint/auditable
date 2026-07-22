<?php

namespace Uzapoint\Auditable\Context;

use Illuminate\Support\Str;

class BatchContext
{
    private static ?string $currentBatch = null;

    public static function get(): ?string
    {
        return self::$currentBatch;
    }

    public static function set(?string $uuid = null): string
    {
        self::$currentBatch = $uuid ?? Str::uuid()->toString();
        return self::$currentBatch;
    }

    public static function clear(): void
    {
        self::$currentBatch = null;
    }

    public static function isActive(): bool
    {
        return self::$currentBatch !== null;
    }
}