<?php

namespace App\Enums;

/** PRD 5.1. */
enum ProjectStatus: string
{
    use HasTransitions;

    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case ACTIVE = 'ACTIVE';
    case COMPLETED = 'COMPLETED';
    case FINANCIALLY_CLOSED = 'FINANCIALLY_CLOSED';
    case ARCHIVED = 'ARCHIVED';
    case CANCELLED = 'CANCELLED';

    public static function transitions(): array
    {
        return [
            'DRAFT' => [self::SUBMITTED, self::CANCELLED],
            'SUBMITTED' => [self::ACTIVE, self::DRAFT, self::CANCELLED], // DRAFT = diminta revisi (asumsi kerja)
            'ACTIVE' => [self::COMPLETED, self::CANCELLED],
            'COMPLETED' => [self::FINANCIALLY_CLOSED], // CANCELLED hanya sebelum COMPLETED
            'FINANCIALLY_CLOSED' => [self::ARCHIVED],
        ];
    }

    /** Baseline finansial terkunci sejak ACTIVE. */
    public function isBaselineLocked(): bool
    {
        return ! in_array($this, [self::DRAFT, self::SUBMITTED], true);
    }

    /** Daftar label untuk pesan galat: "Draf, Berjalan, atau Selesai". */
    public static function labelList(array $cases): string
    {
        $labels = array_map(fn (self $c) => $c->label(), $cases);
        $last = array_pop($labels);

        return $labels ? implode(', ', $labels).(count($labels) > 1 ? ',' : '').' atau '.$last : $last;
    }

    /** Teks tampilan (UI); nilai enum tetap dipakai untuk database, filter, dan API. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::ACTIVE => 'Berjalan',
            self::COMPLETED => 'Selesai',
            self::FINANCIALLY_CLOSED => 'Tutup Buku',
            self::ARCHIVED => 'Diarsipkan',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
