<?php

namespace Tests\Unit;

use App\Enums\ApprovalStatus;
use App\Enums\BqStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\InvalidTransition;
use PHPUnit\Framework\TestCase;

class StatusTransitionTest extends TestCase
{
    public function test_invoice_follows_the_two_stage_workflow(): void
    {
        $path = [InvoiceStatus::PLANNED, InvoiceStatus::INVOICE_SUBMITTED, InvoiceStatus::INVOICE_VERIFIED, InvoiceStatus::WAITING_FOR_PAYMENT, InvoiceStatus::PAYMENT_SUBMITTED, InvoiceStatus::PAID];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $this->assertSame($path[$i + 1], $path[$i]->transitionTo($path[$i + 1]));
        }

        // Tidak ada jalan pintas menuju PAID: hanya dari PAYMENT_SUBMITTED.
        foreach (InvoiceStatus::cases() as $from) {
            $this->assertSame($from === InvoiceStatus::PAYMENT_SUBMITTED, $from->canTransitionTo(InvoiceStatus::PAID), $from->value);
        }

        // Revisi kembali ke tahapnya sendiri.
        $this->assertTrue(InvoiceStatus::INVOICE_REVISION_REQUIRED->canTransitionTo(InvoiceStatus::INVOICE_SUBMITTED));
        $this->assertFalse(InvoiceStatus::INVOICE_REVISION_REQUIRED->canTransitionTo(InvoiceStatus::PAYMENT_SUBMITTED));
        $this->assertTrue(InvoiceStatus::PAYMENT_REVISION_REQUIRED->canTransitionTo(InvoiceStatus::PAYMENT_SUBMITTED));

        // PAID terkunci; satu-satunya jalan keluar adalah controlled unlock.
        $this->assertTrue(InvoiceStatus::PAID->isLocked());
        $this->assertSame([InvoiceStatus::PAYMENT_REVISION_REQUIRED], InvoiceStatus::transitions()['PAID']);
    }

    public function test_project_lifecycle(): void
    {
        $this->assertTrue(ProjectStatus::DRAFT->canTransitionTo(ProjectStatus::SUBMITTED));
        $this->assertFalse(ProjectStatus::DRAFT->canTransitionTo(ProjectStatus::ACTIVE)); // harus lewat SUBMITTED
        $this->assertTrue(ProjectStatus::SUBMITTED->canTransitionTo(ProjectStatus::DRAFT)); // diminta revisi
        $this->assertFalse(ProjectStatus::ACTIVE->canTransitionTo(ProjectStatus::FINANCIALLY_CLOSED)); // harus COMPLETED dulu
        $this->assertFalse(ProjectStatus::COMPLETED->canTransitionTo(ProjectStatus::CANCELLED)); // batal hanya sebelum COMPLETED
        $this->assertFalse(ProjectStatus::FINANCIALLY_CLOSED->canTransitionTo(ProjectStatus::ACTIVE)); // closing tidak bisa dibuka
        $this->assertSame([], ProjectStatus::transitions()['CANCELLED'] ?? []);

        $this->assertFalse(ProjectStatus::SUBMITTED->isBaselineLocked());
        $this->assertTrue(ProjectStatus::ACTIVE->isBaselineLocked());
    }

    public function test_bq_and_approval_workflows(): void
    {
        $this->assertFalse(BqStatus::DRAFT->canTransitionTo(BqStatus::APPROVED)); // harus lewat SUBMITTED
        $this->assertTrue(BqStatus::SUBMITTED->canTransitionTo(BqStatus::REVISION_REQUIRED));
        $this->assertFalse(BqStatus::APPROVED->canTransitionTo(BqStatus::DRAFT)); // baseline approved tidak dibuka lagi
        $this->assertFalse(BqStatus::CLOSED->canTransitionTo(BqStatus::APPROVED));

        $this->assertFalse(ApprovalStatus::APPROVED->canTransitionTo(ApprovalStatus::SUBMITTED)); // approved tidak diedit
        $this->assertTrue(ApprovalStatus::APPROVED->canTransitionTo(ApprovalStatus::REVERSED));
        $this->assertTrue(ApprovalStatus::SUBMITTED->canTransitionTo(ApprovalStatus::WITHDRAWN)); // tarik pengajuan
        $this->assertTrue(ApprovalStatus::REVISION_REQUIRED->canTransitionTo(ApprovalStatus::WITHDRAWN));
        $this->assertFalse(ApprovalStatus::APPROVED->canTransitionTo(ApprovalStatus::WITHDRAWN));
        $this->assertArrayNotHasKey('WITHDRAWN', ApprovalStatus::transitions()); // terminal
        $this->assertSame('Ditarik', ApprovalStatus::WITHDRAWN->label());
    }

    public function test_invalid_transition_throws_and_no_status_is_called_rejected(): void
    {
        foreach ([ProjectStatus::class, BqStatus::class, InvoiceStatus::class, ApprovalStatus::class] as $enum) {
            $this->assertNotContains('REJECTED', $enum::values(), $enum);
            foreach (array_keys($enum::transitions()) as $from) {
                $this->assertContains($from, $enum::values(), "{$enum}: status asal {$from} tidak dikenal");
            }
        }

        $this->expectException(InvalidTransition::class);
        InvoiceStatus::PLANNED->transitionTo(InvoiceStatus::PAID);
    }

    public function test_menu_per_role(): void
    {
        $labels = fn (Role $role) => array_column($role->menu(), 'label');

        $this->assertContains('Tarif Pajak', $labels(Role::STAFF_PIC));
        $this->assertContains('Approvals', $labels(Role::MANAGER_GM));
        $this->assertNotContains('Approvals', $labels(Role::STAFF_PIC));
        $this->assertNotContains('Tarif Pajak', $labels(Role::DIREKSI));
        $this->assertSame(['Dashboard', 'Pengguna', 'Audit Log'], $labels(Role::SYSTEM_ADMIN));
        $this->assertSame(['/dashboard', '/master/users', '/audit-logs'], array_column(Role::SYSTEM_ADMIN->menu(), 'path'));
        $dataMaster = array_values(array_filter(Role::STAFF_PIC->menu(), fn (array $item) => $item['label'] === 'Data Master'));
        $this->assertSame([['label' => 'Data Master', 'path' => '/master/pks']], $dataMaster);
        $this->assertNotContains('Data Master', $labels(Role::MANAGER_GM));
        $this->assertNotContains('Data Master', $labels(Role::DIREKSI));

        $this->assertTrue(Role::MANAGER_GM->isDivisionScoped());
        $this->assertFalse(Role::DIREKSI->isDivisionScoped());
    }
}
