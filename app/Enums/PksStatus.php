<?php

namespace App\Enums;

/** PRD 16.2.2. PKS adalah data master tanpa alur persetujuan; statusnya diatur System Admin. */
enum PksStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case EXPIRED = 'EXPIRED';
    case ARCHIVED = 'ARCHIVED';
    case CANCELLED = 'CANCELLED';

    /** Teks tampilan (UI); nilai enum tetap dipakai untuk database, filter, dan API. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::ACTIVE => 'Aktif',
            self::EXPIRED => 'Kedaluwarsa',
            self::ARCHIVED => 'Diarsipkan',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
