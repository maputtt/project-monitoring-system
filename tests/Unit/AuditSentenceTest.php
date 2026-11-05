<?php

namespace Tests\Unit;

use App\Enums\AuditAction;
use App\Support\AuditSentence;
use PHPUnit\Framework\TestCase;

// Kalimat halaman Audit Log: kata kerja per aksi/entitas, objek, dan baris perubahan (murni, tanpa database).
class AuditSentenceTest extends TestCase
{
    private function sentence(AuditAction $action, string $type, array $before, array $after, int $id, array $info, ?string $code): string
    {
        $o = AuditSentence::object($type, $id, $info, $code);

        return AuditSentence::verb($action, $type, $before, $after).' '.$o['pre'].($o['code'] ?? '').$o['post'];
    }

    public function test_every_action_has_a_base_verb(): void
    {
        $expected = ['CREATE' => 'menambahkan', 'UPDATE' => 'mengubah', 'SUBMIT' => 'mengajukan', 'APPROVE' => 'menyetujui', 'REQUEST_REVISION' => 'meminta revisi',
            'LOCK' => 'mengunci', 'UNLOCK' => 'membuka kunci', 'REVERSE' => 'membalik', 'LOGIN' => 'masuk ke'];
        foreach (AuditAction::cases() as $action) {
            $this->assertSame($expected[$action->value], AuditSentence::verb($action, 'INVOICE', [], []));
        }
    }

    public function test_sentences_for_each_entity(): void
    {
        $u = AuditAction::UPDATE;
        $this->assertSame('mengubah PKS PKS/BM/2026/014', $this->sentence($u, 'PKS', ['status' => 'DRAFT'], ['status' => 'ACTIVE'], 1, ['pks_number' => 'PKS/BM/2026/014'], null));
        $this->assertSame('menambahkan tarif pajak PPN 12% (berlaku mulai 01/01/2027)',
            $this->sentence(AuditAction::CREATE, 'TAX_RATE', [], [], 2, ['name' => 'PPN 12%', 'effective_from' => '2027-01-01'], null));
        $this->assertSame('mengajukan relokasi budget di project BM-2026-102 sebesar Rp25.000.000,00',
            $this->sentence(AuditAction::SUBMIT, 'BQ_MUTATION', [], [], 3, ['amount' => '25000000.00'], 'BM-2026-102'));
        $this->assertSame('menyetujui invoice termin 1 project BM-2026-102',
            $this->sentence(AuditAction::APPROVE, 'INVOICE', [], [], 4, ['sequence_number' => 1], 'BM-2026-102'));
        $this->assertSame('meminta revisi penyesuaian target margin di project BM-2026-102',
            $this->sentence(AuditAction::REQUEST_REVISION, 'PROJECT_ADJUSTMENT', [], [], 5, ['adjustment_type' => 'TARGET_MARGIN', 'difference_amount' => '0.00'], 'BM-2026-102'));
        $this->assertSame('mengajukan koreksi nilai tertagih di project BM-2026-102 sebesar -Rp1.500,00',
            $this->sentence(AuditAction::SUBMIT, 'PROJECT_ADJUSTMENT', [], [], 5, ['adjustment_type' => 'CONTRACT_VALUE', 'difference_amount' => '-1500.00'], 'BM-2026-102'));
        $this->assertSame('menutup buku project BM-2026-102', $this->sentence(AuditAction::LOCK, 'PROJECT', [], [], 6, [], 'BM-2026-102'));
        $this->assertSame('mengunci invoice termin 2 project BM-2026-102', $this->sentence(AuditAction::LOCK, 'INVOICE', [], [], 7, ['sequence_number' => 2], 'BM-2026-102'));
        $this->assertSame('membuka kunci invoice termin 2 project X', $this->sentence(AuditAction::UNLOCK, 'INVOICE', [], [], 7, ['sequence_number' => 2], 'X'));
        $this->assertSame('menambahkan item BQ Besi 10mm di project X', $this->sentence(AuditAction::CREATE, 'BQ_ITEM', [], [], 8, ['item_name' => 'Besi 10mm'], 'X'));
        $this->assertSame('menambahkan dokumen bapp.pdf (BAPP) di project X',
            $this->sentence(AuditAction::CREATE, 'DOCUMENT', [], [], 9, ['original_filename' => 'bapp.pdf', 'document_type' => 'BAPP'], 'X'));
        $this->assertSame('menambahkan penugasan PIC Rina di project X', $this->sentence(AuditAction::CREATE, 'PROJECT_ASSIGNMENT', [], [], 10, ['user_name' => 'Rina'], 'X'));
        $this->assertSame('menambahkan kontak Bouwheer Dewi', $this->sentence(AuditAction::CREATE, 'BOUWHEER_CONTACT', [], [], 11, ['name' => 'Dewi'], null));
        $this->assertSame('mengajukan progres di project X', $this->sentence(AuditAction::SUBMIT, 'PROGRESS', [], [], 12, [], 'X'));
        $this->assertSame('mengubah pengguna #99', $this->sentence($u, 'USER', [], [], 99, [], null)); // baris sudah hilang dan tanpa snapshot
        $this->assertSame('mengajukan invoice #5 project #3', $this->sentence(AuditAction::SUBMIT, 'INVOICE', [], [], 5, ['project_id' => 3], '#3'));
    }

    public function test_status_and_active_changes_get_their_own_verb_and_skip_the_redundant_line(): void
    {
        $u = AuditAction::UPDATE;
        $cases = [
            ['BQ_MUTATION', ['status' => 'SUBMITTED'], ['status' => 'WITHDRAWN'], 'menarik'],
            ['PROJECT_ADJUSTMENT', ['status' => 'REVISION_REQUIRED'], ['status' => 'WITHDRAWN'], 'menarik'],
            ['INVOICE', ['workflow_status' => 'PLANNED'], ['workflow_status' => 'CANCELLED'], 'membatalkan'],
            ['PKS', ['status' => 'DRAFT'], ['status' => 'CANCELLED'], 'membatalkan'],
            ['BQ_ITEM', ['status' => 'DRAFT'], ['status' => 'ARCHIVED'], 'mengarsipkan'],
            ['PKS', ['status' => 'ACTIVE'], ['status' => 'ARCHIVED'], 'mengarsipkan'],
            ['PROJECT', ['status' => 'SUBMITTED'], ['status' => 'ACTIVE'], 'mengaktifkan'],
            ['PROJECT', ['status' => 'ACTIVE'], ['status' => 'COMPLETED'], 'menandai selesai'],
            ['USER', ['is_active' => 1], ['is_active' => 0], 'menonaktifkan'],
            ['USER', ['is_active' => 0], ['is_active' => 1], 'mengaktifkan'],
            ['PROJECT_ASSIGNMENT', ['is_active' => true], ['is_active' => false], 'menonaktifkan'],
        ];
        foreach ($cases as [$type, $before, $after, $verb]) {
            $this->assertSame($verb, AuditSentence::verb($u, $type, $before, $after), "$type $verb");
            $this->assertSame([], AuditSentence::details($u, $type, $before, $after), "$type $verb: baris perubahan sudah terwakili kata kerja");
        }
        // PKS ACTIVE bukan "mengaktifkan"; tetap mengubah dengan baris status. Status sama (tidak berubah) juga tidak khusus.
        $this->assertSame('mengubah', AuditSentence::verb($u, 'PKS', ['status' => 'DRAFT'], ['status' => 'ACTIVE']));
        $this->assertSame('mengubah', AuditSentence::verb($u, 'PROJECT', ['status' => 'ARCHIVED'], ['status' => 'ARCHIVED']));
        // Aksi selain UPDATE tidak memakai kata kerja khusus.
        $this->assertSame('menyetujui', AuditSentence::verb(AuditAction::APPROVE, 'PROJECT', ['status' => 'SUBMITTED'], ['status' => 'ACTIVE']));
    }

    public function test_detail_lines_format_values_and_skip_internal_keys(): void
    {
        $lines = AuditSentence::details(AuditAction::UPDATE, 'PKS', ['status' => 'DRAFT', 'end_date' => '2026-12-31', 'version' => 1, 'approved_at' => 'x'],
            ['status' => 'ACTIVE', 'end_date' => '2027-06-30', 'version' => 2, 'approved_at' => 'y']);
        $this->assertSame(['Status: Draf → Aktif', 'Berakhir: 31/12/2026 → 30/06/2027'], $lines);

        $lines = AuditSentence::details(AuditAction::UPDATE, 'BQ_ITEM', ['baseline_budget' => '1000000.00', 'margin_pct' => '12.5000', 'is_overbudget' => 0, 'approved_by' => 1],
            ['baseline_budget' => '1250000.50', 'margin_pct' => '11.0000', 'is_overbudget' => 1, 'approved_by' => 2]);
        $this->assertSame(['Nilai RAB awal: Rp1.000.000,00 → Rp1.250.000,50', 'Sisa (%): 12,5% → 11%', 'Melebihi budget: Tidak → Ya'], $lines);

        // Kode project diterjemahkan, label tidak jatuh ke nama kolom bahasa Inggris.
        $lines = AuditSentence::details(AuditAction::UPDATE, 'PROJECT', ['contract_tax_mode' => 'INCLUSIVE_PPN', 'payment_type' => 'TERMIN', 'margin_base_amount' => '100.00'],
            ['contract_tax_mode' => 'EXCLUSIVE_PPN', 'payment_type' => 'MONTHLY', 'margin_base_amount' => '111.00']);
        $this->assertSame(['Mode pajak kontrak: Termasuk PPN → Belum termasuk PPN', 'Tipe pembayaran: Termin → Bulanan', 'Dasar target margin: Rp100,00 → Rp111,00'], $lines);

        // SUBMIT relokasi: nominal sudah di kalimat; kode jenis diterjemahkan.
        $this->assertSame(['Jenis relokasi: Antar item BQ', 'Status: Diajukan'], AuditSentence::details(AuditAction::SUBMIT, 'BQ_MUTATION', [],
            ['amount' => '25000000.00', 'mutation_type' => 'TRANSFER_BETWEEN_BQ', 'status' => 'SUBMITTED']));

        $this->assertSame(['Jalur status: Invoice Diajukan, Invoice Terverifikasi'], AuditSentence::details(AuditAction::APPROVE, 'INVOICE', [], ['status_path' => ['INVOICE_SUBMITTED', 'INVOICE_VERIFIED']]));

        // CREATE: kode/nama yang sudah ada di kalimat tidak diulang; nilai null tidak ditampilkan; daftar persentase/tanggal diformat per elemen; struktur bersarang dilewati.
        $lines = AuditSentence::details(AuditAction::CREATE, 'PROJECT', [], ['project_code' => 'BM-1', 'project_name' => 'Gedung A', 'location' => null, 'billing_percentages' => ['30.00', '70.00'],
            'planned_billing_dates' => ['2026-11-01', null], 'termins' => [['a' => 1]], 'department' => 'BM']);
        $this->assertSame(['Nama project: Gedung A', 'Persentase termin: 30%, 70%', 'Jadwal tagih: 01/11/2026, —', 'Divisi: Building Management'], $lines);
    }

    public function test_detail_lines_are_capped(): void
    {
        $after = [];
        foreach (range(1, 9) as $i) {
            $after["field_$i"] = "nilai $i";
        }
        $lines = AuditSentence::details(AuditAction::CREATE, 'PKS', [], $after);
        $this->assertCount(7, $lines);
        $this->assertSame('Field 1: nilai 1', $lines[0]);
        $this->assertSame('+3 perubahan lain', $lines[6]);
    }

    public function test_entity_labels_are_indonesian(): void
    {
        $this->assertSame('Relokasi Budget', AuditSentence::entityLabel('BQ_MUTATION'));
        $this->assertSame('Kontak Bouwheer', AuditSentence::entityLabel('BOUWHEER_CONTACT'));
        $this->assertSame('Pengguna', AuditSentence::entityLabel('USER'));
    }
}
