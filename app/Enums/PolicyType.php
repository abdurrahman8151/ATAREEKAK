<?php

namespace App\Enums;

enum PolicyType: string
{
    case PRIVACY = 'privacy';
    case CANCELLATION = 'cancellation';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
