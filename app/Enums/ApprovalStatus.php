<?php

namespace App\Enums;

/** Status project_adjustments dan bq_budget_mutations (PRD 5.3). */
enum ApprovalStatus: string
{
    use HasTransitions;

    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case APPROVED = 'APPROVED';
    case REVISION_REQUIRED = 'REVISION_REQUIRED';
    case REVERSED = 'REVERSED';
    case WITHDRAWN = 'WITHDRAWN'; // usulan ditarik pengaju/approver; terminal

    public static function transitions(): array
    {
        return [
            'DRAFT' => [self::SUBMITTED],
            'SUBMITTED' => [self::APPROVED, self::REVISION_REQUIRED, self::WITHDRAWN],
            'REVISION_REQUIRED' => [self::SUBMITTED, self::WITHDRAWN],
            'APPROVED' => [self::REVERSED], // koreksi lewat reversal, bukan edit
        ];
    }

    /** Teks tampilan (UI); nilai enum tetap dipakai untuk database, filter, dan API. */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draf',
            self::SUBMITTED => 'Diajukan',
            self::APPROVED => 'Disetujui',
            self::REVISION_REQUIRED => 'Perlu Revisi',
            self::REVERSED => 'Dibalik (Reversal)',
            self::WITHDRAWN => 'Ditarik',
        };
    }
}
