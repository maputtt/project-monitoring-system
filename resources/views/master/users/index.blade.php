<x-layouts.app title="Pengguna">
    <x-master-tabs />
    <div class="page-head">
        <h1>Pengguna</h1>
        <form method="GET" class="flex gap-2">
            <input type="search" name="q" value="{{ $search }}" placeholder="Cari nama atau email" aria-label="Cari pengguna" class="inp mt-0 w-56">
            <button type="submit" class="btn">Cari</button>
        </form>
        <x-form-modal name="user-new" title="Tambah pengguna" subtitle="Akun baru langsung bisa masuk dengan password awal." icon="/master/users"
                      :action="route('master.users.store')" submit-label="Tambah pengguna">
            <x-slot:trigger><a href="{{ route('master.users.create') }}" class="btn btn-primary ml-auto" aria-haspopup="dialog">+ Tambah pengguna</a></x-slot:trigger>
            @include('master.users._fields', ['user' => new \App\Models\User, 'editing' => false, 'modal' => 'user-new'])
        </x-form-modal>
    </div>

    <div class="table-card">
        <table class="w-full min-w-[760px]">
            <thead>
                <tr><th class="th">Nama</th><th class="th">Email</th><th class="th">Role</th><th class="th">Divisi</th><th class="th">Status</th><th class="th">Masuk terakhir</th><th class="th">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $user)
                    <tr>
                        <td class="td"><div class="flex items-center gap-3"><x-avatar :name="$user->name" /><div class="font-medium text-slate-900">{{ $user->name }}@if ($user->position)<p class="text-xs font-normal text-slate-600">{{ $user->position }}</p>@endif</div></div></td>
                        <td class="td">{{ $user->email }}</td>
                        <td class="td">{{ $user->role->label() }}</td>
                        <td class="td">{{ $user->department?->value ?? '—' }}</td>
                        <td class="td"><x-status-badge :status="$user->is_active ? 'ACTIVE' : 'INACTIVE'" :label="$user->is_active ? 'Aktif' : 'Nonaktif'" /></td>
                        <td class="td whitespace-nowrap">{{ $user->last_login_at ? \App\Support\LocalTime::format($user->last_login_at) : 'Belum pernah' }}</td>
                        <td class="td">
                            <div class="flex flex-wrap gap-1">
                                <x-form-modal :name="'user-'.$user->id" title="Ubah pengguna" :subtitle="$user->name.' · '.$user->email" icon="/master/users"
                                              :action="route('master.users.update', $user)" method="PUT" submit-label="Simpan perubahan">
                                    <x-slot:trigger><a href="{{ route('master.users.edit', $user) }}" class="btn" aria-haspopup="dialog">Ubah</a></x-slot:trigger>
                                    @include('master.users._fields', ['user' => $user, 'editing' => true, 'modal' => 'user-'.$user->id])
                                </x-form-modal>
                                @if ($user->is(auth()->user()))
                                    <span class="self-center text-xs text-slate-600">Akun Anda</span>
                                @elseif ($user->is_active)
                                    <x-confirm-modal :title="'Nonaktifkan '.$user->name" :action="route('master.users.deactivate', $user)" confirm-label="Nonaktifkan"
                                                     note="Pengguna langsung dikeluarkan dan tidak bisa masuk lagi. Data dan riwayatnya tetap tersimpan.">
                                        <x-slot:trigger><button type="button" class="btn btn-warn">Nonaktifkan</button></x-slot:trigger>
                                    </x-confirm-modal>
                                @else
                                    <x-confirm-modal :title="'Aktifkan '.$user->name" :action="route('master.users.activate', $user)" confirm-label="Aktifkan"
                                                     note="Pengguna bisa masuk kembali dengan password lamanya.">
                                        <x-slot:trigger><button type="button" class="btn">Aktifkan</button></x-slot:trigger>
                                    </x-confirm-modal>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty"><div class="empty-msg">{{ $search === '' ? 'Belum ada pengguna.' : 'Tidak ada pengguna yang cocok dengan pencarian.' }}</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $users->links() }}</div>
    <p class="hint mt-4">Pengguna tidak bisa dihapus, hanya dinonaktifkan, supaya riwayat transaksi dan audit tetap utuh.</p>
</x-layouts.app>
