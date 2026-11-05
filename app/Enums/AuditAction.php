<?php

namespace App\Enums;

/** Nilai audit_logs.action (PRD 16.2.13). Tidak ada REJECT; penolakan memakai REQUEST_REVISION. */
enum AuditAction: string
{
    case CREATE = 'CREATE';
    case UPDATE = 'UPDATE';
    case SUBMIT = 'SUBMIT';
    case APPROVE = 'APPROVE';
    case REQUEST_REVISION = 'REQUEST_REVISION';
    case LOCK = 'LOCK';
    case UNLOCK = 'UNLOCK';
    case REVERSE = 'REVERSE';
    case LOGIN = 'LOGIN'; // tidak lagi dicatat sejak 2026-10-26; baris lama tetap ada dan disembunyikan dari halaman Audit Log

    /** Kata kerja dasar untuk kalimat audit dan label filter; kasus khusus (menarik, membatalkan, ...) ada di AuditSentence. */
    public function verb(): string
    {
        return match ($this) {
            self::CREATE => 'menambahkan',
            self::UPDATE => 'mengubah',
            self::SUBMIT => 'mengajukan',
            self::APPROVE => 'menyetujui',
            self::REQUEST_REVISION => 'meminta revisi',
            self::LOCK => 'mengunci',
            self::UNLOCK => 'membuka kunci',
            self::REVERSE => 'membalik',
            self::LOGIN => 'masuk ke',
        };
    }
}
