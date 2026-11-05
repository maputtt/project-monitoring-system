@php
    use App\Enums\Role;
    use App\Support\Money;
    $user = auth()->user();
    $scope = $user->role->isDivisionScoped() ? 'Divisi '.$user->department?->value : ($department ? 'Divisi '.$department : 'Semua divisi');
    $now = now(config('app.local_timezone'))->locale('id');
    $salam = match (true) { $now->hour < 11 => 'Selamat pagi', $now->hour < 15 => 'Selamat siang', $now->hour < 18 => 'Selamat sore', default => 'Selamat malam' };
    $n = fn ($v) => number_format($v, 0, ',', '.');
    $pct = fn ($v) => str_replace('.', ',', rtrim(rtrim(Money::round($v), '0'), '.')).'%'; // "40.00" menjadi "40%", "33.33" menjadi "33,33%"
    $stripe = 'bg-[repeating-linear-gradient(45deg,#fcd34d_0_4px,#fef3c7_4px_8px)]'; // pola garis supaya "pending" tidak hanya dibedakan warna
    $isManager = $user->role === Role::MANAGER_GM;
    $canInvoices = in_array($user->role, [Role::STAFF_PIC, Role::MANAGER_GM, Role::DIREKSI], true); // /invoices tertutup bagi SYSTEM_ADMIN
    $dq = $department ? ['department' => $department] : []; // filter divisi Direksi ikut ke setiap daftar
    $link = 'text-sm font-medium text-brand-blue hover:underline focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none rounded';
@endphp
<x-layouts.app title="Dashboard">
    {{-- Sambutan: nama, peran, tanggal. Filter divisi hanya untuk DIREKSI dan SYSTEM_ADMIN (STAFF_PIC dan MANAGER_GM terkunci pada divisinya). --}}
    <header class="relative mb-6 overflow-hidden rounded-3xl bg-linear-to-br from-brand-navy via-brand-blue-dark to-brand-blue p-5 text-white shadow-card sm:p-7">
        <x-motif class="-right-8 -bottom-10 h-36 w-36 opacity-35 sm:-right-10 sm:-bottom-16 sm:h-60 sm:w-60" />
        <div class="relative flex flex-col gap-5">
            <div class="min-w-0">
                <p class="text-sm font-medium text-blue-100"><time datetime="{{ $now->toDateString() }}">{{ $now->translatedFormat('l, j F Y') }}</time></p>
                <h1 class="mt-1 text-2xl break-words text-white sm:text-3xl">{{ $salam }}, {{ $user->name }}</h1>
                <p class="mt-3 flex flex-wrap items-center gap-2 text-xs font-medium">
                    <span class="rounded-full bg-white/12 px-3 py-1 ring-1 ring-white/20">{{ $user->role->label() }}</span>
                    <span class="rounded-full bg-white/12 px-3 py-1 ring-1 ring-white/20">{{ $scope }}</span>
                </p>
            </div>
            @if ($departments)
                <nav class="flex w-fit max-w-full flex-wrap gap-1 rounded-2xl bg-white/10 p-1 text-sm font-medium ring-1 ring-white/15" aria-label="Filter divisi">
                    @php $tab = 'inline-flex min-h-10 items-center rounded-xl px-3.5 transition-colors focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none sm:px-4'; @endphp
                    <a href="{{ route('dashboard') }}" @if (! $department) aria-current="page" @endif @class([$tab, 'bg-white font-semibold text-brand-navy shadow-sm' => ! $department, 'text-blue-100 hover:bg-white/10 hover:text-white' => $department])>Semua</a>
                    @foreach ($departments as $d)
                        <a href="{{ route('dashboard', ['department' => $d->value]) }}" @if ($department === $d->value) aria-current="page" @endif @class([$tab, 'bg-white font-semibold text-brand-navy shadow-sm' => $department === $d->value, 'text-blue-100 hover:bg-white/10 hover:text-white' => $department !== $d->value])>{{ $d->value }}</a>
                    @endforeach
                </nav>
            @endif
        </div>
    </header>
    @error('department')<p class="mb-4 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror

    {{-- Hanya MANAGER_GM: jumlah yang menunggu keputusannya, menaut ke antrean (halaman itu sendiri yang bertindak; dashboard tetap read-only). --}}
    @if ($isManager)
        <section @class(['relative mb-6 flex flex-col gap-4 overflow-hidden rounded-2xl border p-5 shadow-card sm:flex-row sm:items-center sm:justify-between sm:p-6', 'border-amber-200 bg-linear-to-r from-amber-50 to-white' => $kpi['pending'] > 0, 'border-emerald-200 bg-linear-to-r from-emerald-50 to-white' => $kpi['pending'] === 0]) aria-labelledby="menunggu">
            <div class="flex items-center gap-4">
                <span @class(['flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl ring-1 ring-inset', 'bg-amber-100 text-amber-800 ring-amber-600/20' => $kpi['pending'] > 0, 'bg-emerald-100 text-emerald-700 ring-emerald-600/20' => $kpi['pending'] === 0])><x-icon name="/approvals" size="h-6 w-6" /></span>
                <div class="min-w-0">
                    <h2 id="menunggu" class="flex items-center gap-1">Menunggu persetujuan Anda <x-info-tip label="Menunggu persetujuan Anda">Pengajuan yang menunggu keputusan Anda.</x-info-tip></h2>
                    @if ($kpi['pending'] > 0)
                        <p class="mt-0.5 text-sm text-slate-700"><span class="text-2xl font-semibold text-slate-900 tabular-nums">{{ $n($kpi['pending']) }}</span> item menunggu keputusan di {{ $scope }}.</p>
                    @else
                        <p class="mt-0.5 text-sm text-slate-700">Tidak ada yang menunggu. Semua pengajuan di {{ $scope }} sudah diputuskan.</p>
                    @endif
                </div>
            </div>
            <a href="{{ route('approvals.index') }}" @class(['btn shrink-0', 'btn-primary' => $kpi['pending'] > 0])>Buka antrean persetujuan <x-icon name="arrow" size="h-4 w-4" /></a>
        </section>
    @endif

    @if ($kpi['activated'] === 0 && $prospects['count'] === 0)
        <div class="card empty mb-6" role="status"><p>Belum ada project di jangkauan Anda.</p></div>
    @endif

    {{-- Ringkasan finansial. Tiap kartu menaut ke daftar asalnya (drill-down); semua angka dari controller. --}}
    <section class="mb-6 space-y-4" aria-labelledby="ringkasan">
        <h2 id="ringkasan">Ringkasan finansial</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-kpi-card label="Total Nilai Project" tip="Total nilai SPK project Berjalan, Selesai, dan Tutup Buku. Draf dan Diajukan tidak dihitung." :value="$kpi['contract']" icon="wallet" tone="blue" :context="'Dari '.$n($kpi['activated']).' project (berjalan, selesai, tutup buku)'" :href="route('dashboard.projects', ['group' => 'activated'] + $dq)" />
            <x-kpi-card label="RAB Perubahan" tip="Batas biaya terkini. Rumus: Dasar Margin − Target Margin. Berubah jika penyesuaian margin disetujui." :value="$kpi['rab']" icon="/invoices" tone="purple" context="Batas biaya setelah target margin" :href="route('dashboard.projects', ['group' => 'activated'] + $dq)" />
            <x-kpi-card label="Target Margin" tip="Rumus: Total Target Margin ÷ Total Dasar Margin × 100%. Dasar Margin = Nilai Project (+PPN jika Exclusive PPN)." :value="$kpi['margin']" icon="check" tone="emerald" :context="$pct($kpi['margin_pct']).' dari dasar margin (rata-rata tertimbang)'" :href="route('dashboard.projects', ['group' => 'activated'] + $dq)" />
            <x-kpi-card label="Margin Realisasi" tip="Laba aktual project Tutup Buku. Rumus: Target Margin + Sisa Akhir (Tutup Buku). Persen = Total Laba Aktual ÷ Total Dasar Margin × 100%." :value="$kpi['realized']" icon="check" :tone="Money::compare($kpi['realized'], 0) < 0 ? 'rose' : 'emerald'" :context="$kpi['realized_count'] > 0 ? $pct($kpi['realized_pct']).' dari dasar margin · '.$n($kpi['realized_count']).' project tutup buku' : 'Belum ada project tutup buku'" :href="route('dashboard.margin', $dq)" />
            <x-kpi-card label="Tagihan Belum Lunas" tip="Invoice yang sudah ditagih tapi belum dibayar." :value="$kpi['outstanding']" icon="/invoices" tone="orange" context="Sudah ditagih, belum dibayar" :href="$canInvoices ? route('invoices.index', ['outstanding' => 1] + $dq) : null" />
            <x-kpi-card label="Uang Diterima (Nett)" tip="Uang masuk dari invoice Lunas, setelah dipotong PPh." :value="$kpi['nett']" icon="check" tone="emerald" context="dari invoice Lunas, setelah dipotong PPh" :href="$canInvoices ? route('invoices.index', ['status' => 'PAID'] + $dq) : null" />
        </div>
    </section>

    <section class="mb-6 space-y-4" aria-labelledby="status-project">
        <h2 id="status-project">Status project</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-kpi-card label="Project Aktif/Berjalan" tip="Project yang sedang berjalan." :value="$n($kpi['projects'])" :money="false" icon="/projects" tone="blue" :context="$scope" :href="route('dashboard.projects', ['group' => 'active'] + $dq)" />
            <x-kpi-card label="Project Selesai" tip="Project selesai dikerjakan, belum tutup buku." :value="$n($kpi['completed'])" :money="false" icon="check" tone="purple" context="belum tutup buku" :href="route('dashboard.projects', ['group' => 'completed'] + $dq)" />
            {{-- Himpunan sama dengan Margin Realisasi: FINANCIALLY_CLOSED dan ARCHIVED dengan laba aktual terisi. --}}
            <x-kpi-card label="Project Tutup Buku" tip="Project yang laba akhirnya sudah dikunci." :value="$n($kpi['realized_count'])" :money="false" icon="lock" tone="emerald" context="laba final terkunci" :href="route('dashboard.margin', $dq)" />
            {{-- Calon kontrak: project DRAFT/SUBMITTED. Sengaja di luar KPI uang dan di luar "Alur Tagihan" (itu tagihan invoice). --}}
            <x-kpi-card label="Calon Kontrak" tip="Project Draf atau Diajukan, belum aktif. Tidak masuk angka keuangan." :value="$n($prospects['count'])" :money="false" icon="/projects" tone="slate" :context="Money::format($prospects['total']).' · draf dan menunggu aktivasi'" :href="route('dashboard.projects', ['group' => 'prospects'] + $dq)" />
        </div>
    </section>

    <section class="mb-6 space-y-4" aria-labelledby="perhatian">
        <h2 id="perhatian">Perlu perhatian</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-kpi-card label="BQ overbudget" tip="Item BQ yang biayanya melebihi Nilai RAB-nya." :value="$n($kpi['overbudget'])" :money="false" icon="alert" :tone="$kpi['overbudget'] > 0 ? 'rose' : 'slate'" :context="$kpi['overbudget'] > 0 ? 'Item BQ melewati budget' : 'Tidak ada item BQ yang melewati budget'" :href="route('dashboard.overbudget', $dq)" />
            {{-- Antrean persetujuan hanya ada untuk MANAGER_GM; peran lain melihat angkanya tanpa tautan. --}}
            <x-kpi-card label="Approval pending" tip="Pengajuan yang menunggu keputusan Manager/GM." :value="$n($kpi['pending'])" :money="false" icon="/approvals" :tone="$kpi['pending'] > 0 ? 'amber' : 'slate'" context="Menunggu keputusan Manager/GM" :href="$isManager ? route('approvals.index') : null" />
            {{-- Termin PLANNED yang tanggal rencananya sudah lewat; menaut ke daftar invoice terfilter. Netral bila 0. --}}
            <x-kpi-card label="Termin terlambat ditagih" tip="Termin yang belum ditagih padahal tanggal rencana tagihnya sudah lewat." :value="$n($late_to_bill)" :money="false" icon="alert" :tone="$late_to_bill > 0 ? 'amber' : 'slate'" :context="$late_to_bill > 0 ? 'Lewat tanggal rencana tagih. Lihat daftar' : 'Tidak ada termin yang lewat tanggal rencana tagih'"
                :href="$canInvoices ? route('invoices.index', ['late_to_bill' => 1] + $dq) : null" :aria="$n($late_to_bill).' termin terlambat ditagih, buka daftar invoice'" />
            {{-- Termin yang catatan progres penagihan terbarunya bertanda kendala (belum Lunas/Dibatalkan). --}}
            <x-kpi-card label="Kendala aktif" tip="Termin yang progres terakhirnya ditandai kendala dan belum lunas." :value="$n($issues)" :money="false" icon="alert" :tone="$issues > 0 ? 'amber' : 'slate'" :context="$issues > 0 ? 'Termin dengan kendala penagihan. Lihat daftar' : 'Tidak ada kendala penagihan'" :href="route('dashboard.kendala', $dq)" />
        </div>
    </section>

    {{-- Dua ringkasan visual dari CSS biasa. Tiap batang punya padanan teks (aria-label dan daftar di bawahnya). --}}
    <div class="mb-8 grid gap-4 lg:grid-cols-2">
        @php
            $a = $allocation;
            $sisa = Money::compare($a['available'], 0) < 0 ? 'text-rose-700' : 'text-slate-900';
        @endphp
        <section class="card" aria-labelledby="alokasi-rab">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 id="alokasi-rab" class="flex items-center gap-1">Alokasi RAB <x-info-tip label="Alokasi RAB">Pemakaian RAB project Berjalan dan Selesai: Terpakai (BQ disetujui), Pending (BQ diajukan), dan Sisa.</x-info-tip></h2>
                    <p class="mt-0.5 text-sm text-slate-600">Dari RAB Perubahan <span class="font-semibold text-slate-900 tabular-nums">{{ Money::format($a['rab']) }}</span> · project berjalan dan selesai</p>
                </div>
                <div class="flex items-center gap-3">
                    @if ($a['over'])<x-status-badge status="CANCELLED" label="Melebihi RAB" />@endif
                    <a href="{{ route('dashboard.projects', ['group' => 'open'] + $dq) }}" class="{{ $link }}" aria-label="Lihat project dasar Alokasi RAB">Lihat project</a>
                </div>
            </div>
            <div role="img" aria-label="Alokasi RAB: terpakai {{ Money::format($a['committed']) }} ({{ $pct($a['committed_pct']) }}), pending {{ Money::format($a['pending']) }} ({{ $pct($a['pending_pct']) }}), sisa tersedia {{ Money::format($a['available']) }}" class="mt-5 flex h-4 overflow-hidden rounded-full bg-slate-100 ring-1 ring-slate-200 ring-inset">
                <div class="h-full bg-brand-blue" style="width: {{ $a['committed_pct'] }}%"></div>
                <div class="h-full {{ $stripe }}" style="width: {{ $a['pending_pct'] }}%"></div>
            </div>
            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex items-center gap-3"><span class="h-3 w-3 shrink-0 rounded-sm bg-brand-blue" aria-hidden="true"></span><dt class="min-w-0 flex-1 text-slate-700">Terpakai <span class="text-slate-500">· {{ $pct($a['committed_pct']) }} dari RAB</span></dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($a['committed']) }}</dd></div>
                <div class="flex items-center gap-3"><span class="h-3 w-3 shrink-0 rounded-sm {{ $stripe }} ring-1 ring-amber-400/60" aria-hidden="true"></span><dt class="min-w-0 flex-1 text-slate-700">Pending <span class="text-slate-500">· {{ $pct($a['pending_pct']) }} · belum resmi</span></dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($a['pending']) }}</dd></div>
                <div class="flex items-center gap-3 border-t border-slate-100 pt-3"><span class="h-3 w-3 shrink-0 rounded-sm bg-slate-100 ring-1 ring-slate-300" aria-hidden="true"></span><dt class="min-w-0 flex-1 text-slate-700">Sisa tersedia</dt><dd class="font-semibold tabular-nums {{ $sisa }}">{{ Money::format($a['available']) }}</dd></div>
            </dl>
        </section>

        @php
            $l = $pipeline;
            $semua = Money::add($l['paid'], $l['outstanding'], $l['planned']);
        @endphp
        <section class="card" aria-labelledby="alur-tagihan">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 id="alur-tagihan" class="flex items-center gap-1">Alur tagihan <x-info-tip label="Alur tagihan">Tagihan project Berjalan dan Selesai: Dibayar, Belum Lunas, dan Terencana (belum ditagih).</x-info-tip></h2>
                    <p class="mt-0.5 text-sm text-slate-600">Total nilai tagihan <span class="font-semibold text-slate-900 tabular-nums">{{ Money::format($semua) }}</span> · project berjalan dan selesai</p>
                </div>
                <a href="{{ route('dashboard.projects', ['group' => 'open'] + $dq) }}" class="{{ $link }}" aria-label="Lihat project dasar Alur tagihan">Lihat project</a>
            </div>
            <div role="img" aria-label="Alur tagihan: dibayar {{ Money::format($l['paid']) }} ({{ $pct($l['paid_pct']) }}), tagihan belum lunas {{ Money::format($l['outstanding']) }} ({{ $pct($l['outstanding_pct']) }}), terencana {{ Money::format($l['planned']) }} ({{ $pct($l['planned_pct']) }})" class="mt-5 flex h-4 overflow-hidden rounded-full bg-slate-100 ring-1 ring-slate-200 ring-inset">
                <div class="h-full bg-emerald-600" style="width: {{ $l['paid_pct'] }}%"></div>
                <div class="h-full bg-brand-orange" style="width: {{ $l['outstanding_pct'] }}%"></div>
                <div class="h-full bg-slate-400" style="width: {{ $l['planned_pct'] }}%"></div>
            </div>
            <dl class="mt-5 space-y-3 text-sm">
                <div class="flex items-center gap-3"><span class="h-3 w-3 shrink-0 rounded-sm bg-emerald-600" aria-hidden="true"></span><dt class="min-w-0 flex-1 text-slate-700">Dibayar <span class="text-slate-500">· {{ $pct($l['paid_pct']) }} · {{ \App\Enums\InvoiceStatus::PAID->label() }}</span></dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($l['paid']) }}</dd></div>
                <div class="flex items-center gap-3"><span class="h-3 w-3 shrink-0 rounded-sm bg-brand-orange" aria-hidden="true"></span><dt class="min-w-0 flex-1 text-slate-700">Tagihan Belum Lunas <span class="text-slate-500">· {{ $pct($l['outstanding_pct']) }} · ditagih</span></dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($l['outstanding']) }}</dd></div>
                <div class="flex items-center gap-3"><span class="h-3 w-3 shrink-0 rounded-sm bg-slate-400" aria-hidden="true"></span><dt class="min-w-0 flex-1 text-slate-700">Terencana <span class="text-slate-500">· {{ $pct($l['planned_pct']) }} · belum ditagih</span></dt><dd class="font-semibold text-slate-900 tabular-nums">{{ Money::format($l['planned']) }}</dd></div>
            </dl>
        </section>
    </div>
</x-layouts.app>
