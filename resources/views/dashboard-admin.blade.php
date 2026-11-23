{{-- Dashboard SYSTEM_ADMIN (keputusan PM 2026-11-16): administrasi sistem saja, tanpa angka finansial. Story B menambah ringkasan sistem. --}}
@php($ctl = \App\Http\Controllers\AdminDashboardController::class)
<x-layouts.app title="Dashboard Admin">
    <div class="page-head">
        <div class="min-w-0">
            <h1>Dashboard Admin</h1>
            <p class="mt-1 text-sm text-slate-600">Halo, {{ auth()->user()->name }}. Akun System Admin dipakai untuk administrasi sistem, bukan untuk melihat data project atau keuangan.</p>
        </div>
    </div>

    @php($ticketCounts = $ticketCounts ?? [])
    <section class="space-y-4" aria-labelledby="ringkasan">
        <x-section-heading id="ringkasan" icon="/dashboard">Ringkasan</x-section-heading>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-kpi-card label="Pengguna online" :value="$users->where('is_online')->count()" :money="false" icon="/master/users" tone="emerald" :context="'Aktif dalam '.$ctl::ONLINE_MINUTES.' menit terakhir'" />
            <x-kpi-card label="Total pengguna aktif" :value="$users->where('is_active', true)->count()" :money="false" icon="/master/users" tone="blue" context="Akun dengan status aktif" :href="route('master.users.index')" />
            <x-kpi-card label="Tiket baru" :value="$ticketCounts['NEW'] ?? '—'" :money="false" icon="/support-tickets" tone="amber" context="Menunggu ditangani" :href="route('support-tickets.index', ['status' => 'NEW'])" />
            <x-kpi-card label="Sedang diproses" :value="$ticketCounts['IN_PROGRESS'] ?? '—'" :money="false" icon="/support-tickets" tone="purple" context="Tiket yang sedang ditangani" :href="route('support-tickets.index', ['status' => 'IN_PROGRESS'])" />
        </div>
    </section>

    <section class="mt-8 space-y-4" aria-labelledby="kesehatan">
        <x-section-heading id="kesehatan" icon="alert">Kesehatan sistem</x-section-heading>
        <div class="card">
            <div class="flex flex-wrap items-center gap-3">
                <x-status-badge :status="$health['tone']" :label="'Status: '.$health['label']" class="px-3.5 py-1.5 text-sm" />
                <x-info-tip label="status kesehatan sistem">
                    Dihitung dari request 60 menit terakhir.
                    Stabil: rata-rata di bawah {{ $ctl::SLOW_AVG_MS }} ms dan tanpa error 5xx.
                    Lambat: rata-rata mulai {{ $ctl::SLOW_AVG_MS }} ms, atau ada error 5xx.
                    Bermasalah: rata-rata mulai {{ $ctl::BAD_AVG_MS }} ms, atau error 5xx mencapai {{ $ctl::BAD_ERROR_RATE_PCT }}% dari request.
                </x-info-tip>
                <p class="min-w-0 text-sm text-slate-600">
                    @if ($health['requests'] > 0)
                        60 menit terakhir: {{ number_format($health['requests'], 0, ',', '.') }} request, rata-rata {{ number_format($health['avg'], 0, ',', '.') }} ms, {{ $health['errors'] }} error 5xx.
                    @else
                        Belum ada request tercatat dalam 60 menit terakhir.
                    @endif
                </p>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <p class="text-xs font-semibold text-slate-600">Waktu respons per jam, 24 jam terakhir (WIB)</p>
                <ul class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
                    <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-brand-blue" aria-hidden="true"></span>Rata-rata</li>
                    <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-blue-100 ring-1 ring-blue-200 ring-inset" aria-hidden="true"></span>Maksimum</li>
                    <li class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-rose-200 ring-1 ring-rose-300 ring-inset" aria-hidden="true"></span>Jam dengan error 5xx</li>
                </ul>
            </div>
            {{-- Bagan digeser mendatar di dalam kartu pada layar sempit; halaman tidak ikut meluber. --}}
            <div class="mt-3 overflow-x-auto pb-1" x-data x-init="$el.scrollLeft = $el.scrollWidth">
                <div class="min-w-[720px]">
                    <p class="mb-1 text-[11px] text-slate-500" aria-hidden="true">Puncak {{ number_format($scale, 0, ',', '.') }} ms</p>
                    <div class="flex h-40 items-end gap-1 border-b border-slate-200" aria-hidden="true">
                        @foreach ($hours as $h)
                            <div class="flex h-full flex-1 flex-col justify-end" title="{{ $h['label'] }} WIB: {{ $h['requests'] }} request, rata-rata {{ $h['avg'] }} ms, maks {{ $h['max'] }} ms, {{ $h['errors'] }} error 5xx">
                                <div class="relative w-full rounded-t {{ $h['errors'] > 0 ? 'bg-rose-200' : 'bg-blue-100' }}" style="height: {{ round($h['max'] / $scale * 100, 1) }}%">
                                    <div class="absolute inset-x-0 bottom-0 rounded-t bg-brand-blue" style="height: {{ $h['max'] > 0 ? round($h['avg'] / $h['max'] * 100, 1) : 0 }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex gap-1 text-[10px] text-slate-600 tabular-nums" aria-hidden="true">
                        @foreach ($hours as $h)<span class="flex-1 text-center">{{ substr($h['label'], 0, 2) }}</span>@endforeach
                    </div>
                </div>
            </div>
            <div class="sr-only"><table>
                <caption>Request per jam, 24 jam terakhir (WIB)</caption>
                <thead><tr><th>Jam</th><th>Request</th><th>Rata-rata ms</th><th>Maksimum ms</th><th>Error 5xx</th></tr></thead>
                <tbody>
                    @foreach ($hours as $h)
                        <tr><td>{{ $h['label'] }}</td><td>{{ $h['requests'] }}</td><td>{{ $h['avg'] }}</td><td>{{ $h['max'] }}</td><td>{{ $h['errors'] }}</td></tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    </section>

    <section class="mt-8 space-y-4" aria-labelledby="pengguna-online">
        <x-section-heading id="pengguna-online" icon="/master/users">Aktivitas pengguna</x-section-heading>
        <div class="table-card">
            <table class="w-full min-w-[680px]">
                <thead>
                    <tr><th class="th">Nama</th><th class="th">Role</th><th class="th">Divisi</th><th class="th">Status</th><th class="th">Login terakhir</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($users as $u)
                        <tr>
                            <td class="td font-medium text-slate-900">{{ $u->name }}</td>
                            <td class="td">{{ $u->role->label() }}</td>
                            <td class="td">{{ $u->department?->value ?? '—' }}</td>
                            <td class="td">
                                <span class="inline-flex items-center gap-2 font-medium {{ $u->is_online ? 'text-emerald-700' : 'text-slate-600' }}">
                                    <span class="h-2.5 w-2.5 rounded-full {{ $u->is_online ? 'bg-emerald-500 ring-4 ring-emerald-100' : 'bg-slate-300' }}" aria-hidden="true"></span>{{ $u->is_online ? 'Online' : 'Offline' }}
                                </span>
                                @unless ($u->is_online)
                                    <span class="block text-xs text-slate-500">{{ $u->last_seen_at ? 'terakhir aktif '.$u->last_seen_at->diffForHumans() : 'belum pernah aktif' }}</span>
                                @endunless
                            </td>
                            <td class="td whitespace-nowrap">{{ $u->last_login_at ? $u->last_login_at->timezone(config('app.local_timezone'))->format('d/m/Y H:i') : 'Belum pernah' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-8 space-y-4" aria-labelledby="administrasi">
        <x-section-heading id="administrasi" icon="/master/users">Administrasi</x-section-heading>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <a href="{{ route('master.users.index') }}" class="card block p-5 transition hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:outline-none">
                <p class="font-semibold text-brand-blue">Pengguna</p>
                <p class="mt-1 text-sm text-slate-600">Tambah, ubah, dan aktif/nonaktifkan akun pengguna.</p>
            </a>
            <a href="{{ route('support-tickets.index') }}" class="card block p-5 transition hover:shadow-md focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:outline-none">
                <p class="font-semibold text-brand-blue">Tiket</p>
                <p class="mt-1 text-sm text-slate-600">Tinjau laporan masalah dari pengguna dan atur statusnya.</p>
            </a>
        </div>
    </section>
</x-layouts.app>
