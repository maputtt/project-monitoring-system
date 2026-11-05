<?php

namespace App\Enums;

/** PRD 5.3 dan 16.2.7. */
enum BqStatus: string
{
    use HasTransitions;

    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case REVISION_REQUIRED = 'REVISION_REQUIRED';
    case APPROVED = 'APPROVED';
    case CLOSED = 'CLOSED';
    case ARCHIVED = 'ARCHIVED';

    public static function transitions(): array
    {
        return [
            'DRAFT' => [self::SUBMITTED, self::ARCHIVED],
            'SUBMITTED' => [self::APPROVED, self::REVISION_REQUIRED],
            'REVISION_REQUIRED' => [self::SUBMITTED, self::ARCHIVED],
            'APPROVED' => [self::CLOSED], // saat financial closing
        ];
    }

    /** Teks tampilan (UI); nilai enum tetap dipakai untuk database, filter, dan API. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::REVISION_REQUIRED => 'Perlu Revisi',
            self::APPROVED => 'Disetujui',
            self::CLOSED => 'Ditutup',
            self::ARCHIVED => 'Diarsipkan',
        };
    }
}
