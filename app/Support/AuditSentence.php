<?php

namespace App\Support;

use App\Enums\ApprovalStatus;
use App\Enums\AuditAction;
use App\Enums\BqStatus;
use App\Enums\Department;
use App\Enums\InvoiceStatus;
use App\Enums\PksStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ProjectAdjustmentController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mengubah baris audit_logs menjadi kalimat Indonesia untuk halaman Audit Log: "<aktor> <kata kerja> <objek>" ditambah baris perubahan.
 * Semua teks hasil mentah (belum di-escape); view yang meng-escape. Hanya membaca, tidak pernah menulis audit_logs.
 */
final class AuditSentence
{
    private const NOUN = [
        'PROJECT' => 'project', 'PKS' => 'PKS', 'BOUWHEER_CONTACT' => 'kontak Bouwheer', 'TAX_RATE' => 'tarif pajak', 'USER' => 'pengguna',
        'BQ_ITEM' => 'item BQ', 'BQ_MUTATION' => 'relokasi budget', 'PROJECT_ADJUSTMENT' => 'penyesuaian/koreksi', 'INVOICE' => 'invoice',
        'DOCUMENT' => 'dokumen', 'PROJECT_ASSIGNMENT' => 'penugasan PIC', 'PROGRESS' => 'progres',
    ];

    private const DOC_TYPES = ['NOTA_DISPOSISI' => 'Nota Disposisi', 'BAPP' => 'BAPP', 'BAST' => 'BAST', 'BUKTI_BAYAR' => 'Bukti bayar', 'OTHER' => 'Lainnya'];

    /** Kode nilai non-enum yang sering muncul di baris perubahan. */
    private const VALUES = [
        'FROM_UNALLOCATED_RAB' => 'Dari Sisa RAB', 'TRANSFER_BETWEEN_BQ' => 'Antar item BQ', 'RETURN_TO_UNALLOCATED_RAB' => 'Kembalikan ke Sisa RAB',
        'OPERATIONAL' => 'Operasional', 'NORMAL' => 'Normal', 'UPLOADED' => 'Terunggah', 'VERIFIED' => 'Terverifikasi', 'REPLACED' => 'Diganti',
        'INCLUSIVE_PPN' => 'Termasuk PPN', 'EXCLUSIVE_PPN' => 'Belum termasuk PPN', 'NON_PPN' => 'Tanpa PPN', 'TERMIN' => 'Termin', 'MONTHLY' => 'Bulanan',
    ];

    /** entity_type => [tabel, kolom nama/penunjuk]; satu query per jenis untuk satu halaman (tanpa N+1). */
    private const LOOKUP = [
        'PROJECT' => ['projects', ['project_code']],
        'PKS' => ['pks', ['pks_number']],
        'BOUWHEER_CONTACT' => ['bouwheer_contacts', ['name']],
        'TAX_RATE' => ['tax_rates', ['name', 'effective_from']],
        'USER' => ['users', ['name']],
        'BQ_ITEM' => ['bq_items', ['project_id', 'item_name']],
        'BQ_MUTATION' => ['bq_budget_mutations', ['project_id', 'amount']],
        'PROJECT_ADJUSTMENT' => ['project_adjustments', ['project_id', 'adjustment_type', 'difference_amount']],
        'INVOICE' => ['invoices', ['project_id', 'sequence_number']],
        'DOCUMENT' => ['documents', ['project_id', 'original_filename', 'document_type']],
        'PROJECT_ASSIGNMENT' => ['project_user_assignments', ['project_id', 'user_id']],
        'PROGRESS' => ['project_progress', ['project_id']],
    ];

    private const LABELS = [
        'status' => 'Status', 'workflow_status' => 'Status', 'name' => 'Nama', 'email' => 'Email', 'role' => 'Peran', 'department' => 'Divisi',
        'phone_number' => 'Telepon', 'position' => 'Jabatan', 'is_active' => 'Aktif', 'pks_number' => 'Nomor PKS', 'client_name' => 'Klien',
        'company_name' => 'Perusahaan', 'description' => 'Deskripsi', 'issued_date' => 'Tanggal terbit', 'start_date' => 'Mulai', 'end_date' => 'Berakhir',
        'project_code' => 'Kode project', 'project_name' => 'Nama project', 'location' => 'Lokasi', 'spk_number' => 'Nomor SPK', 'spk_date' => 'Tanggal SPK',
        'contract_value' => 'Nilai kontrak', 'initial_contract_value' => 'Nilai kontrak awal', 'initial_rab' => 'RAB awal', 'effective_rab' => 'RAB perubahan',
        'target_margin_pct' => 'Target margin', 'target_margin_amount' => 'Target margin (Rp)', 'actual_profit_amount' => 'Profit aktual',
        'final_sisa_rab' => 'Sisa Akhir (Tutup Buku)', 'closed_bq_count' => 'Item BQ ditutup', 'billing_percentages' => 'Persentase termin', 'planned_billing_dates' => 'Jadwal tagih',
        'item_code' => 'Kode item', 'item_name' => 'Nama item', 'item_type' => 'Jenis item', 'vendor_quote_amount' => 'Penawaran vendor', 'baseline_budget' => 'Nilai RAB awal',
        'effective_budget' => 'Nilai RAB', 'negotiated_value' => 'Nilai Vendor/Subkon', 'margin_amount' => 'Sisa', 'margin_pct' => 'Sisa (%)', 'actual_cost' => 'Realisasi biaya',
        'payment_type' => 'Tipe pembayaran', 'payment_count' => 'Jumlah termin', 'contract_tax_mode' => 'Mode pajak kontrak', 'margin_base_amount' => 'Dasar target margin',
        'unallocated_rab' => 'Sisa RAB', 'planned_billing_date' => 'Rencana tgl tagih', 'role_mode' => 'Peran', 'margin_mode' => 'Mode margin', 'margin_value' => 'Nilai margin',
        'is_overbudget' => 'Melebihi budget', 'amount' => 'Nominal', 'mutation_type' => 'Jenis relokasi', 'reason' => 'Alasan usulan', 'adjustment_type' => 'Jenis penyesuaian',
        'difference_amount' => 'Selisih', 'invoice_number' => 'Nomor invoice', 'bapp_number' => 'Nomor BAPP', 'bast_number' => 'Nomor BAST', 'billing_date' => 'Tanggal tagih',
        'due_date' => 'Jatuh tempo', 'ppn_rate_snapshot' => 'Tarif PPN', 'pph_rate_snapshot' => 'Tarif PPh', 'dpp_amount' => 'DPP', 'ppn_amount' => 'PPN', 'pph_amount' => 'PPh',
        'subtotal_amount' => 'Subtotal', 'net_received_amount' => 'Diterima bersih', 'tax_type' => 'Jenis pajak', 'rate' => 'Tarif', 'effective_from' => 'Berlaku mulai',
        'effective_until' => 'Berlaku sampai', 'assignment_role' => 'Peran penugasan', 'original_filename' => 'Nama berkas', 'document_type' => 'Jenis dokumen', 'source_effective_budget' => 'Budget item sumber', 'destination_effective_budget' => 'Budget item tujuan', 'source_sisa' => 'Sisa item sumber', 'status_path' => 'Jalur status', 'stage' => 'Tahap', 'progress_date' => 'Tanggal', 'is_issue' => 'Kendala', 'note' => 'Catatan',
    ];

    /** Kunci internal/bising yang tidak ditampilkan sebagai baris perubahan. */
    private const SKIP = ['id', 'version', 'old_data', 'new_data', 'checksum', 'mime_type', 'file_size', 'storage_key', 'entity_type', 'termins'];

    private const MAX_LINES = 6;

    public static function entityLabel(string $type): string
    {
        return ucwords(self::NOUN[$type] ?? $type); // Title Case seperti label di layar lain, mis. "Relokasi Budget"
    }

    /** Kata kerja kalimat; kasus khusus pada UPDATE (perubahan status/aktif) dan penutupan buku project. */
    public static function verb(AuditAction $action, string $type, array $before, array $after): string
    {
        return self::special($action, $type, $before, $after)[0]
            ?? match (true) {
                $action === AuditAction::LOCK && $type === 'PROJECT' => 'menutup buku',
                $action === AuditAction::CREATE && $type === 'PROGRESS' => 'mencatat',
                default => $action->verb(),
            };
    }

    /** @return array{0: string, 1: string}|null [kata kerja, kunci yang sudah terwakili oleh kata kerja] */
    private static function special(AuditAction $action, string $type, array $before, array $after): ?array
    {
        if ($action !== AuditAction::UPDATE) {
            return null;
        }
        if (in_array($type, ['USER', 'PROJECT_ASSIGNMENT'], true) && array_key_exists('is_active', $before) && array_key_exists('is_active', $after) && (bool) $before['is_active'] !== (bool) $after['is_active']) {
            return [$after['is_active'] ? 'mengaktifkan' : 'menonaktifkan', 'is_active'];
        }
        foreach (['status', 'workflow_status'] as $key) {
            $to = $after[$key] ?? null;
            if ($to === null || ($before[$key] ?? null) === $to) {
                continue;
            }
            $verb = match (true) {
                $to === 'WITHDRAWN' => 'menarik',
                $to === 'CANCELLED' => 'membatalkan',
                $to === 'ARCHIVED' => 'mengarsipkan',
                $to === 'COMPLETED' => 'menandai selesai',
                $to === 'ACTIVE' && $type === 'PROJECT' => 'mengaktifkan',
                default => null,
            };
            if ($verb !== null) {
                return [$verb, $key];
            }
        }

        return null;
    }

    /**
     * Objek kalimat dalam tiga bagian agar kode project bisa ditautkan di view tanpa HTML mentah.
     *
     * @param  array  $info  baris yang masih ada, dilengkapi snapshot audit bila baris sudah tidak ada
     * @return array{pre: string, code: ?string, post: string}
     */
    public static function object(string $type, int $id, array $info, ?string $code): array
    {
        $noun = self::NOUN[$type] ?? strtolower($type);
        if ($type === 'PROJECT_ADJUSTMENT' && isset(ProjectAdjustmentController::LABELS[$info['adjustment_type'] ?? ''])) {
            $noun = mb_strtolower(ProjectAdjustmentController::LABELS[$info['adjustment_type']]);
        }
        $name = match ($type) {
            'PKS' => $info['pks_number'] ?? null,
            'BOUWHEER_CONTACT', 'USER', 'TAX_RATE' => $info['name'] ?? null,
            'BQ_ITEM' => $info['item_name'] ?? null,
            'DOCUMENT' => isset($info['original_filename']) ? $info['original_filename'].' ('.(self::DOC_TYPES[$info['document_type'] ?? ''] ?? ($info['document_type'] ?? 'dokumen')).')' : null,
            'PROJECT_ASSIGNMENT' => $info['user_name'] ?? null,
            default => null,
        };
        $text = match ($type) {
            'PROJECT', 'BQ_MUTATION', 'PROJECT_ADJUSTMENT' => $noun,
            'PROGRESS' => $noun.(isset($info['sequence_number']) ? ' termin '.$info['sequence_number'] : ''),
            'INVOICE' => $noun.(isset($info['sequence_number']) ? ' termin '.$info['sequence_number'] : " #$id"),
            default => $noun.' '.($name ?? "#$id"),
        };
        $amount = $type === 'BQ_MUTATION' ? ($info['amount'] ?? null) : ($type === 'PROJECT_ADJUSTMENT' ? ($info['difference_amount'] ?? null) : null);
        $post = match (true) {
            $type === 'TAX_RATE' && filled($info['effective_from'] ?? null) => ' (berlaku mulai '.self::date($info['effective_from']).')',
            is_numeric($amount) && Money::compare((string) $amount, '0') !== 0 => ' sebesar '.Money::format((string) $amount),
            default => '',
        };
        if ($type === 'PROJECT') {
            return ['pre' => $text.' ', 'code' => $code ?? "#$id", 'post' => $post];
        }

        return ['pre' => $text.($code === null ? '' : ($type === 'INVOICE' || isset($info['sequence_number']) && $type === 'PROGRESS' ? ' project ' : ' di project ')), 'code' => $code, 'post' => $post];
    }

    /** Baris perubahan "Label: sebelum → sesudah", maksimal MAX_LINES lalu "+N perubahan lain". @return list<string> */
    public static function details(AuditAction $action, string $type, array $before, array $after): array
    {
        $consumed = self::special($action, $type, $before, $after)[1] ?? null;
        // Nama dan nominal yang sudah ada di kalimat tidak diulang pada baris baru (CREATE/SUBMIT).
        $inSentence = $action === AuditAction::CREATE ? ['name', 'pks_number', 'item_name', 'project_code', 'original_filename'] : [];
        if (in_array($action, [AuditAction::CREATE, AuditAction::SUBMIT], true)) {
            $inSentence = [...$inSentence, ...match ($type) {
                'BQ_MUTATION' => ['amount'], 'PROJECT_ADJUSTMENT' => ['difference_amount', 'adjustment_type'], 'DOCUMENT' => ['document_type'], 'PROGRESS' => ['sequence_number'], default => [],
            }];
        }
        $lines = [];
        foreach ($after as $key => $new) {
            $key = (string) $key;
            if ($key === $consumed || in_array($key, $inSentence, true) || self::skipped($key) || (array_key_exists($key, $before) ? $before[$key] == $new : $new === null)) {
                continue;
            }
            $to = self::format($type, $key, $new);
            if ($to === null) {
                continue;
            }
            $label = self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
            $lines[] = $label.': '.(array_key_exists($key, $before) ? self::format($type, $key, $before[$key]).' → '.$to : $to);
        }
        if (count($lines) > self::MAX_LINES) {
            $extra = count($lines) - self::MAX_LINES;
            $lines = [...array_slice($lines, 0, self::MAX_LINES), "+$extra perubahan lain"];
        }

        return $lines;
    }

    private static function skipped(string $key): bool
    {
        return in_array($key, self::SKIP, true) || preg_match('/(_id|_at|_by|_hash|_reason)$/', $key) === 1;
    }

    /** null berarti nilai tidak layak ditampilkan (struktur bersarang). */
    private static function format(string $type, string $key, mixed $value): ?string
    {
        if (is_array($value)) {
            $parts = array_map(fn ($v) => is_array($v) ? null : self::format($type, $key, $v), $value);

            return in_array(null, $parts, true) ? null : implode(', ', $parts);
        }
        if ($value === null || $value === '') {
            return '—';
        }
        if (str_starts_with($key, 'is_')) {
            return $value ? 'Ya' : 'Tidak';
        }
        $enum = match (true) {
            $key === 'workflow_status', $key === 'status_path' => InvoiceStatus::class,
            $key === 'status' => match ($type) {
                'PROJECT' => ProjectStatus::class, 'PKS' => PksStatus::class, 'BQ_ITEM' => BqStatus::class,
                'PROJECT_ADJUSTMENT', 'BQ_MUTATION' => ApprovalStatus::class, default => null,
            },
            $key === 'role' => Role::class,
            $key === 'department' => Department::class,
            default => null,
        };
        if ($key === 'stage') {
            return InvoiceController::PROGRESS_STAGES[$value] ?? (string) $value;
        }
        if ($enum !== null) {
            return $enum::tryFrom((string) $value)?->label() ?? (string) $value;
        }
        if (is_numeric($value)) {
            if (preg_match('/(_amount|_budget|_value|_cost|_rab)$/', $key) || $key === 'amount') {
                return Money::format((string) $value);
            }
            if (preg_match('/(_pct|_rate|_rate_snapshot|_percentages?)$/', $key) || $key === 'rate') {
                return Money::pct((string) $value).'%';
            }
        }
        if (is_string($value) && (preg_match('/_dates?$/', $key) || in_array($key, ['effective_from', 'effective_until'], true))) {
            return self::date($value);
        }

        $text = (string) $value;

        return self::VALUES[$text] ?? self::DOC_TYPES[$text] ?? Str::limit($text, 80);
    }

    private static function date(string $value): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) ? "$m[3]/$m[2]/$m[1]" : $value;
    }

    /**
     * Melengkapi setiap baris halaman dengan verb, pre/code/post, href, details. Lookup nama dilakukan per jenis entitas (batch).
     * Tautan hanya ke halaman project yang masih ada; query halaman sudah membatasi baris yang boleh dilihat peran peminta.
     */
    public static function decorate(iterable $logs): void
    {
        $logs = collect($logs);
        $decode = fn ($json) => is_array($d = json_decode((string) $json, true)) ? $d : [];
        $logs->each(function ($log) use ($decode) {
            $log->before = $decode($log->data_before ?? null);
            $log->after = $decode($log->data_after ?? null);
        });

        $found = [];
        foreach (self::LOOKUP as $type => [$table, $columns]) {
            $ids = $logs->where('entity_type', $type)->pluck('entity_id')->unique()->all();
            $found[$type] = $ids === [] ? [] : DB::table($table)->whereIn('id', $ids)->get(['id', ...$columns])->keyBy('id')->map(fn ($row) => (array) $row)->all();
        }
        $infos = $logs->mapWithKeys(function ($log) use ($found) {
            $info = ($found[$log->entity_type][$log->entity_id] ?? []) + $log->after + $log->before;
            if ($log->entity_type === 'PROJECT') {
                $info['project_id'] = $log->entity_id;
            }

            return [$log->id => $info];
        });
        $codes = DB::table('projects')->whereIn('id', $infos->pluck('project_id')->filter()->unique()->all())->pluck('project_code', 'id');
        $userIds = $logs->where('entity_type', 'PROJECT_ASSIGNMENT')->map(fn ($log) => $infos[$log->id]['user_id'] ?? null)->filter()->unique()->all();
        $users = $userIds === [] ? collect() : DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        $logs->each(function ($log) use ($infos, $codes, $users) {
            $info = $infos[$log->id];
            $info['user_name'] = $users[$info['user_id'] ?? 0] ?? null;
            $projectId = $info['project_id'] ?? null;
            $code = $codes[$projectId] ?? ($log->entity_type === 'PROJECT' ? ($info['project_code'] ?? null) : ($projectId ? "#$projectId" : null));
            $log->verb = self::verb(AuditAction::from($log->action), $log->entity_type, $log->before, $log->after);
            $log->object = self::object($log->entity_type, $log->entity_id, $info, $code);
            $log->href = isset($codes[$projectId]) ? route('projects.show', $projectId) : null;
            $log->details = self::details(AuditAction::from($log->action), $log->entity_type, $log->before, $log->after);
        });
    }
}
