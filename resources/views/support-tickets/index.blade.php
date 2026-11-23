@php($t = $all ? 'Tiket' : 'Tiket saya')
<x-layouts.app :title="$t">
    <div class="page-head">
        <div class="min-w-0">
            <h1>{{ $t }}</h1>
            <p class="mt-1 text-sm text-slate-600">{{ $all ? 'Laporan masalah dari semua pengguna. Buka tiket untuk mengubah status dan memberi catatan.' : 'Laporan masalah yang Anda kirim dan perkembangannya.' }}</p>
        </div>
        <a href="{{ route('support-tickets.create') }}" class="btn btn-primary ml-auto">+ Laporkan Masalah</a>
    </div>

    <div class="table-card">
        <table class="w-full min-w-[640px]">
            <thead>
                <tr><th class="th">#</th><th class="th">Judul</th>@if ($all)<th class="th">Pelapor</th>@endif<th class="th">Status</th><th class="th">Dilaporkan</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($tickets as $ticket)
                    <tr>
                        <td class="td tabular-nums">{{ $ticket->id }}</td>
                        <td class="td max-w-md"><a href="{{ route('support-tickets.show', $ticket) }}" class="font-semibold text-brand-blue hover:underline">{{ $ticket->title }}</a></td>
                        @if ($all)<td class="td">{{ $ticket->reporter->name }} <span class="block text-xs text-slate-500">{{ $ticket->reporter->role->label() }}</span></td>@endif
                        <td class="td"><x-status-badge :status="$ticket->status" /></td>
                        <td class="td whitespace-nowrap">{{ \App\Support\LocalTime::format($ticket->created_at) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $all ? 5 : 4 }}" class="empty">
                        <div class="empty-msg">
                            <p class="font-semibold text-slate-700">Belum ada tiket.</p>
                            <p class="mt-1">{{ $all ? 'Laporan dari pengguna akan muncul di sini.' : 'Menemukan kendala saat memakai sistem? Kirim laporan agar segera ditangani.' }}</p>
                            @unless ($all)<a href="{{ route('support-tickets.create') }}" class="btn btn-primary mt-4">Laporkan Masalah</a>@endunless
                        </div>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $tickets->links() }}</div>
</x-layouts.app>
