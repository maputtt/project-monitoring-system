{{-- Ikon garis 24px. Nama = path menu (/dashboard, dst.) atau salah satu ikon bantu. Nama tak dikenal memakai ikon Dashboard. --}}
@props(['name', 'size' => 'h-5 w-5'])
@php
    $paths = [
        '/dashboard' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        '/projects' => 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        '/invoices' => 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
        '/approvals' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6',
        '/tax-rates' => 'M6 18L18 6M8 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM16 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
        '/master/pks' => 'M4 6c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6',
        '/master/users' => 'M16 20v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM20 20v-1a4 4 0 0 0-3-3.9M16 4.2a3.5 3.5 0 0 1 0 6.6',
        '/audit-logs' => 'M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1zM8 6H6a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1h-2M9 12h6M9 16h6',
        'wallet' => 'M4 7a2 2 0 0 1 2-2h11v3M4 7v10a2 2 0 0 0 2 2h13a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1H6a2 2 0 0 1-2-2zM16 13.5h.01',
        'check' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6',
        'alert' => 'M12 9v4M12 17h.01M10.3 3.9L2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
        'lock' => 'M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3M12 15v2',
        'unlock' => 'M6 11h12v9H6zM8 11V8a4 4 0 0 1 7.5-1.5M12 15v2',
        'download' => 'M12 4v11M7 11l5 5 5-5M5 20h14',
        'print' => 'M7 8V4h10v4M7 17H5a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2M7 14h10v6H7z',
        'chevron' => 'M6 9l6 6 6-6',
        'arrow' => 'M5 12h14M13 6l6 6-6 6',
        'close' => 'M6 6l12 12M18 6L6 18',
        'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
    ];
@endphp
<svg {{ $attributes->class([$size, 'shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['/dashboard'] }}"/></svg>
