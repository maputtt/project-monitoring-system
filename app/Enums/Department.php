<?php

namespace App\Enums;

/** Divisi, department, dan unit bermakna sama (PRD FD-16). */
enum Department: string
{
    case BM = 'BM';
    case CP = 'CP';
    case ITBS = 'ITBS';

    public function label(): string
    {
        return match ($this) {
            self::BM => 'Building Management',
            self::CP => 'Construction & Property',
            self::ITBS => 'IT Business Solution',
        };
    }
}
