<?php

namespace App\Enums;

/** PRD 5.5: file invoice dan bukti pembayaran diverifikasi pada tahap berbeda. */
enum InvoiceStatus: string
{
    use HasTransitions;

    case PLANNED = 'PLANNED';
    case INVOICE_SUBMITTED = 'INVOICE_SUBMITTED';
    case INVOICE_REVISION_REQUIRED = 'INVOICE_REVISION_REQUIRED';
    case INVOICE_VERIFIED = 'INVOICE_VERIFIED';
    case WAITING_FOR_PAYMENT = 'WAITING_FOR_PAYMENT';
    case PAYMENT_SUBMITTED = 'PAYMENT_SUBMITTED';
    case PAYMENT_REVISION_REQUIRED = 'PAYMENT_REVISION_REQUIRED';
    case PAID = 'PAID';
    case CANCELLED = 'CANCELLED';

    public static function transitions(): array
    {
        return [
            'PLANNED' => [self::INVOICE_SUBMITTED, self::CANCELLED],
            'INVOICE_SUBMITTED' => [self::INVOICE_VERIFIED, self::INVOICE_REVISION_REQUIRED],
            'INVOICE_REVISION_REQUIRED' => [self::INVOICE_SUBMITTED],
            'INVOICE_VERIFIED' => [self::WAITING_FOR_PAYMENT],
            'WAITING_FOR_PAYMENT' => [self::PAYMENT_SUBMITTED],
            'PAYMENT_SUBMITTED' => [self::PAID, self::PAYMENT_REVISION_REQUIRED],
            'PAYMENT_REVISION_REQUIRED' => [self::PAYMENT_SUBMITTED],
            'PAID' => [self::PAYMENT_REVISION_REQUIRED], // hanya lewat controlled unlock (asumsi kerja)
        ];
    }

    public function isLocked(): bool
    {
        return $this === self::PAID;
    }

    /** Teks tampilan (UI); nilai enum tetap dipakai untuk database, filter, dan API. */
    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Direncanakan',
            self::INVOICE_SUBMITTED => 'Invoice Diajukan',
            self::INVOICE_REVISION_REQUIRED => 'Revisi Invoice',
            self::INVOICE_VERIFIED => 'Invoice Terverifikasi',
            self::WAITING_FOR_PAYMENT => 'Menunggu Pembayaran',
            self::PAYMENT_SUBMITTED => 'Pembayaran Diajukan',
            self::PAYMENT_REVISION_REQUIRED => 'Revisi Pembayaran',
            self::PAID => 'Lunas',
            self::CANCELLED => 'Dibatalkan',
        };
    }
}
