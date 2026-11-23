<?php

namespace App\Enums;

/** Status tiket laporan masalah (Story C). System Admin boleh pindah ke status mana pun. */
enum SupportTicketStatus: string
{
    case NEW = 'NEW';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED = 'RESOLVED';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Baru',
            self::IN_PROGRESS => 'Diproses',
            self::RESOLVED => 'Selesai',
        };
    }
}
