<?php

namespace App\Enums;

use App\Exceptions\InvalidTransition;

/** Dipakai enum status. Peta transisi di tiap enum adalah satu-satunya sumber perpindahan status yang sah. */
trait HasTransitions
{
    /** @return array<string, list<self>> nama status asal ke daftar status tujuan */
    abstract public static function transitions(): array;

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, static::transitions()[$this->name] ?? [], true);
    }

    /** Mengembalikan status tujuan, atau melempar InvalidTransition bila perpindahannya tidak sah. */
    public function transitionTo(self $to): self
    {
        if (! $this->canTransitionTo($to)) {
            throw new InvalidTransition("Status {$this->label()} tidak dapat berubah menjadi {$to->label()}.");
        }

        return $to;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
