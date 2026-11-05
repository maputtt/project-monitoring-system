<x-layouts.app title="PKS">
    <x-master-tabs />
    <div class="page-head">
        <h1>PKS</h1>
        <form method="GET" class="flex gap-2">
            <input type="search" name="q" value="{{ $search }}" placeholder="Cari nomor, nama, atau klien" aria-label="Cari PKS" class="inp mt-0 w-60">
            <button type="submit" class="btn">Cari</button>
        </form>
        <x-form-modal name="pks-new" title="Tambah PKS" subtitle="Perjanjian kerja sama yang menjadi dasar project." icon="/master/pks" size="lg"
                      :action="route('master.pks.store')" submit-label="Tambah PKS">
            <x-slot:trigger><a href="{{ route('master.pks.create') }}" class="btn btn-primary ml-auto" aria-haspopup="dialog">+ Tambah PKS</a></x-slot:trigger>
            @include('master.pks._fields', ['pks' => new \App\Models\Pks(['status' => \App\Enums\PksStatus::ACTIVE]), 'modal' => 'pks-new'])
        </x-form-modal>
    </div>

    <div class="table-card">
        <table class="w-full min-w-[820px]">
            <thead>
                <tr><th class="th">Nomor PKS</th><th class="th">Nama</th><th class="th">Klien / Bouwheer</th><th class="th">Terbit</th><th class="th">Periode</th><th class="th">Status</th><th class="th num">Project</th><th class="th">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($pks as $row)
                    <tr>
                        <td class="td font-medium whitespace-nowrap">{{ $row->pks_number }}</td>
                        <td class="td">{{ $row->name }}</td>
                        <td class="td">{{ $row->client_name }}</td>
                        <td class="td whitespace-nowrap">{{ $row->issued_date->format('d/m/Y') }}</td>
                        <td class="td whitespace-nowrap">{{ $row->start_date?->format('d/m/Y') ?? '—' }} s.d. {{ $row->end_date?->format('d/m/Y') ?? '—' }}</td>
                        <td class="td"><x-status-badge :status="$row->status" /></td>
                        <td class="td num">{{ $row->projects_count }}</td>
                        <td class="td">
                            <x-form-modal :name="'pks-'.$row->id" title="Ubah PKS" :subtitle="$row->pks_number.' · '.$row->name" icon="/master/pks" size="lg"
                                          :action="route('master.pks.update', $row)" method="PUT" submit-label="Simpan perubahan">
                                <x-slot:trigger><a href="{{ route('master.pks.edit', $row) }}" class="btn" aria-haspopup="dialog">Ubah</a></x-slot:trigger>
                                @include('master.pks._fields', ['pks' => $row, 'modal' => 'pks-'.$row->id])
                            </x-form-modal>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty"><div class="empty-msg">{{ $search === '' ? 'Belum ada PKS. Tambahkan PKS lebih dulu sebelum membuat project.' : 'Tidak ada PKS yang cocok dengan pencarian.' }}</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $pks->links() }}</div>
    <p class="hint mt-4">PKS tidak bisa dihapus. PKS yang tidak dipakai lagi diberi status Diarsipkan atau Dibatalkan.</p>
</x-layouts.app>
