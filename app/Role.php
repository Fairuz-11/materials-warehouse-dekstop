<?php

namespace App;

enum Role: string
{
    case AdminGudang = 'admin_gudang';
    case AdminKasir = 'admin_kasir';

    /**
     * All role values as an array — useful for validation rules.
     *
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
