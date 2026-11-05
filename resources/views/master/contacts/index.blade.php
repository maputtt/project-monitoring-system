<x-layouts.app title="PIC Bouwheer">
    <x-master-tabs />
    <div class="page-head">
        <h1>PIC Bouwheer</h1>
        <form method="GET" class="flex gap-2">
            <input type="search" name="q" value="{{ $search }}" placeholder="Cari nama atau perusahaan" aria-label="Cari PIC Bouwheer" class="inp mt-0 w-60">
            <button type="submit" class="btn">Cari</button>
        </form>
        <x-form-modal name="contact-new" title="Tambah PIC Bouwheer" subtitle="Kontak di sisi klien yang bisa dipilih untuk project baru." icon="/master/users"
                      :action="route('master.bouwheer-contacts.store')" submit-label="Tambah PIC">
            <x-slot:trigger><a href="{{ route('master.bouwheer-contacts.create') }}" class="btn btn-primary ml-auto" aria-haspopup="dialog">+ Tambah PIC</a></x-slot:trigger>
            @include('master.contacts._fields', ['contact' => new \App\Models\BouwheerContact(['is_active' => true]), 'modal' => 'contact-new'])
        </x-form-modal>
    </div>

    <div class="table-card">
        <table class="w-full min-w-[760px]">
            <thead>
                <tr><th class="th">Nama</th><th class="th">Perusahaan</th><th class="th">Jabatan</th><th class="th">Telepon</th><th class="th">Email</th><th class="th">Status</th><th class="th num">Project</th><th class="th">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($contacts as $contact)
                    <tr>
                        <td class="td"><div class="flex items-center gap-3"><x-avatar :name="$contact->name" /><span class="font-medium text-slate-900">{{ $contact->name }}</span></div></td>
                        <td class="td">{{ $contact->company_name }}</td>
                        <td class="td">{{ $contact->position ?? '—' }}</td>
                        <td class="td whitespace-nowrap">{{ $contact->phone_number ?? '—' }}</td>
                        <td class="td">{{ $contact->email ?? '—' }}</td>
                        <td class="td"><x-status-badge :status="$contact->is_active ? 'ACTIVE' : 'INACTIVE'" :label="$contact->is_active ? 'Aktif' : 'Nonaktif'" /></td>
                        <td class="td num">{{ $contact->projects_count }}</td>
                        <td class="td">
                            <x-form-modal :name="'contact-'.$contact->id" title="Ubah PIC Bouwheer" :subtitle="$contact->name.' · '.$contact->company_name" icon="/master/users"
                                          :action="route('master.bouwheer-contacts.update', $contact)" method="PUT" submit-label="Simpan perubahan">
                                <x-slot:trigger><a href="{{ route('master.bouwheer-contacts.edit', $contact) }}" class="btn" aria-haspopup="dialog">Ubah</a></x-slot:trigger>
                                @include('master.contacts._fields', ['contact' => $contact, 'modal' => 'contact-'.$contact->id])
                            </x-form-modal>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty"><div class="empty-msg">{{ $search === '' ? 'Belum ada PIC Bouwheer. Tambahkan PIC lebih dulu sebelum membuat project.' : 'Tidak ada PIC yang cocok dengan pencarian.' }}</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $contacts->links() }}</div>
    <p class="hint mt-4">PIC tidak bisa dihapus. PIC yang nonaktif tidak bisa dipilih untuk project baru, tetapi tetap tampil di project lama.</p>
</x-layouts.app>
