<?php

namespace App\Enums;

enum Role: string
{
    case STAFF_PIC = 'STAFF_PIC';
    case MANAGER_GM = 'MANAGER_GM';
    case DIREKSI = 'DIREKSI';
    case SYSTEM_ADMIN = 'SYSTEM_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::STAFF_PIC => 'Staff/PIC',
            self::MANAGER_GM => 'Manager/GM',
            self::DIREKSI => 'Direksi',
            self::SYSTEM_ADMIN => 'System Admin',
        };
    }

    /** STAFF_PIC dan MANAGER_GM dibatasi pada divisinya sendiri. */
    public function isDivisionScoped(): bool
    {
        return in_array($this, [self::STAFF_PIC, self::MANAGER_GM], true);
    }

    /**
     * Menu utama per role. Hanya untuk tampilan; hak akses tetap diperiksa di server.
     *
     * @return list<array{label: string, path: string}>
     */
    public function menu(): array
    {
        $all = [
            ['Dashboard', '/dashboard', [self::STAFF_PIC, self::MANAGER_GM, self::DIREKSI, self::SYSTEM_ADMIN]],
            ['PKS & Projects', '/projects', [self::STAFF_PIC, self::MANAGER_GM, self::DIREKSI]],
            ['Finance & Invoicing', '/invoices', [self::STAFF_PIC, self::MANAGER_GM, self::DIREKSI]],
            ['Approvals', '/approvals', [self::MANAGER_GM]],
            ['Tarif Pajak', '/tax-rates', [self::STAFF_PIC, self::MANAGER_GM]],
            ['Data Master', '/master/pks', [self::STAFF_PIC]],
            ['Pengguna', '/master/users', [self::SYSTEM_ADMIN]],
            ['Audit Log', '/audit-logs', [self::MANAGER_GM, self::DIREKSI, self::SYSTEM_ADMIN]],
        ];

        return array_values(array_map(
            fn (array $item) => ['label' => $item[0], 'path' => $item[1]],
            array_filter($all, fn (array $item) => in_array($this, $item[2], true)),
        ));
    }
}
