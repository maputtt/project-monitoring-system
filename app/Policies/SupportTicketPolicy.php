<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\SupportTicket;
use App\Models\User;

/** Tiket hanya terlihat oleh pelapor dan System Admin. Dipakai juga oleh Story E (penerima notifikasi). */
class SupportTicketPolicy
{
    public function view(User $user, SupportTicket $ticket): bool
    {
        return $user->role === Role::SYSTEM_ADMIN || $ticket->reporter_id === $user->id;
    }
}
