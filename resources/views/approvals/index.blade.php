@php
    use App\Support\Money;
    $dmy = fn ($date) => \App\Support\LocalTime::format($date, false); // tanggal WIB, format sama dengan detail project
    $total = $counts->sum();
@endphp
<x-layouts.app title="Approvals">
    <div class="page-head">
        <div class="min-w-0">
            <h1>Approvals</h1>
            <p class="mt-1 text-sm text-slate-600">Pengajuan di divisi Anda yang menunggu keputusan. Keputusan diambil di halaman project.</p>
        </div>
    </div>

    {{-- Hitungan per jenis; ikon dan nada hanya pembeda visual, teks label tetap yang utama. --}}
    <section class="mb-6 space-y-3" aria-labelledby="menunggu">
        <h2 id="menunggu">Menunggu keputusan ({{ $total }})</h2>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
            @php
                $look = [
                    'project' => ['/projects', 'text-brand-blue'], 'bq' => ['/invoices', 'text-purple-700'], 'invoice' => ['/invoices', 'text-brand-orange'],
                    'payment' => ['wallet', 'text-emerald-700'], 'adjustment' => ['alert', 'text-amber-700'], 'mutation' => ['arrow', 'text-slate-700'],
                ];
            @endphp
            @foreach ($types as $key => $label)
                <x-stat-card :label="$label" :value="number_format($counts[$key] ?? 0, 0, ',', '.')" :money="false" :icon="$look[$key][0]" :tone="$look[$key][1]" />
            @endforeach
        </div>
    </section>

    <h2 class="mb-3" id="daftar-approval">Daftar pengajuan</h2>
    @if ($items->isEmpty())
        <div class="card empty" role="status"><p>Tidak ada item yang menunggu keputusan Anda.</p></div>
    @else
        {{-- Ponsel: kartu bertumpuk seperti daftar lain; mulai md: tabel. Read-only: hanya tautan Tinjau ke bagian terkait di detail project. --}}
        <div class="grid gap-3 md:hidden">
            @foreach ($items as $item)
                <a href="{{ route('projects.show', $item['project_id']) }}{{ $item['anchor'] }}" @class(['rounded-xl border p-4 transition duration-150 hover:border-brand-blue/30 hover:shadow-pop focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none', 'border-slate-200/80 bg-white' => ! $item['own'], 'border-slate-200 bg-slate-50' => $item['own']])>
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <x-status-badge status="SUBMITTED" :label="$item['type_label']" />
                            <p class="mt-2 text-xs text-slate-600">{{ $item['project_code'] }}</p>
                            <p class="font-semibold break-words text-slate-900">{{ $item['project_name'] }}</p>
                            <p class="mt-1 text-xs break-words text-slate-600">{{ $item['summary'] }}</p>
                        </div>
                        <span class="shrink-0 text-sm font-semibold text-slate-900 tabular-nums">{{ Money::format($item['amount']) }}</span>
                    </div>
                    <p class="mt-3 text-xs text-slate-600">Diajukan {{ $item['by_name'] ?? '—' }} · {{ $dmy($item['at']) }}</p>
                    @if ($item['own'])
                        <p class="mt-1 flex items-start gap-1.5 text-xs font-medium text-slate-600"><x-icon name="lock" size="h-4 w-4" />Diajukan oleh Anda — tidak bisa Anda setujui</p>
                    @endif
                    <p class="mt-3 inline-flex items-center gap-1.5 text-sm font-semibold text-brand-blue">Tinjau <x-icon name="arrow" size="h-4 w-4" /></p>
                </a>
            @endforeach
        </div>

        <div class="table-card hidden md:block">
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="th px-3">Jenis</th>
                        <th class="th px-3">Project</th>
                        <th class="th px-3">Ringkasan</th>
                        <th class="th num px-3">Nilai</th>
                        <th class="th px-3">Diajukan</th>
                        <th class="th px-3"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr @class(['bg-slate-50/80' => $item['own']])>
                            <td class="td px-3"><x-status-badge status="SUBMITTED" :label="$item['type_label']" /></td>
                            <td class="td min-w-44 px-3">
                                <span class="font-semibold text-slate-900">{{ $item['project_code'] }}</span>
                                <span class="block text-xs text-slate-600">{{ $item['project_name'] }}</span>
                            </td>
                            <td class="td px-3">{{ $item['summary'] }}</td>
                            <td class="td num whitespace-nowrap px-3">{{ Money::format($item['amount']) }}</td>
                            <td class="td min-w-40 px-3">
                                {{ $item['by_name'] ?? '—' }}
                                <span class="block text-xs text-slate-600">{{ $dmy($item['at']) }}</span>
                                @if ($item['own'])
                                    <span class="mt-1 flex items-start gap-1 text-xs font-medium text-slate-600"><x-icon name="lock" size="h-4 w-4" />Diajukan oleh Anda — tidak bisa Anda setujui</span>
                                @endif
                            </td>
                            <td class="td px-3"><a href="{{ route('projects.show', $item['project_id']) }}{{ $item['anchor'] }}" class="btn" aria-label="Tinjau {{ $types[$item['type']] }} {{ $item['project_code'] }}">Tinjau <x-icon name="arrow" size="h-4 w-4" /></a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mt-4">{{ $items->links() }}</div>
</x-layouts.app>
