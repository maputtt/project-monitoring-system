{{-- Status selalu tampil sebagai teks ditambah warna, tidak pernah warna saja (PRD 8.1). Menerima enum (teks dari label() enum) atau string; label mengganti teks yang tampil. Status string tanpa enum (dokumen, aksi audit) memakai peta di bawah atau nilainya apa adanya. --}}
@props(['status', 'label' => null])
@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $text = $label ?? ($status instanceof \BackedEnum && method_exists($status, 'label') ? $status->label() : match ($value) {
        'UPLOADED' => 'Terunggah', 'VERIFIED' => 'Terverifikasi', 'REVISION_REQUIRED' => 'Perlu Revisi', 'REPLACED' => 'Diganti',
        default => $value,
    });
    $tone = match (true) {
        str_contains($value, 'REVISION') => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        str_contains($value, 'SUBMITTED'), $value === 'UPLOADED' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        str_contains($value, 'WAITING') => 'bg-purple-50 text-purple-700 ring-purple-600/20',
        str_contains($value, 'CLOSED') => 'bg-slate-800 text-white ring-slate-900/20',
        in_array($value, ['PAID', 'APPROVED', 'VERIFIED', 'INVOICE_VERIFIED', 'ACTIVE', 'ACHIEVED', 'COMPLETED'], true) => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        in_array($value, ['DELAYED', 'REPLACED', 'CANCELLED', 'REVERSED'], true) => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        $value === 'WITHDRAWN' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        default => '',
    };
@endphp
<span {{ $attributes->class(['badge', $tone]) }}>{{ $text }}</span>
