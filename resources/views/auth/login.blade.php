<x-layouts.app title="Login">
    {{-- Di ponsel yang dikenal server, muat penuh /login menampilkan kunci Face ID; form password tetap tersedia lewat "Gunakan password". --}}
    <div x-data="passkeyLogin" @if ($errors->any() || old('email') || session('status')) data-password-only @endif>
        <div x-show="mode === 'lock'" x-cloak class="card p-6 shadow-pop sm:p-8">
            <x-passkey-unlock />
        </div>
        <p x-show="mode === 'checking'" x-cloak class="text-center text-sm text-slate-600" role="status">Memeriksa perangkat…</p>
        <form x-show="mode === 'password'" method="POST" action="{{ route('login') }}" class="card space-y-5 p-6 shadow-pop sm:p-8">
            @csrf
            <div>
                <h1>Masuk</h1>
                {{-- Laravel menyimpan halaman tujuan saat user yang belum login atau sesinya habis dialihkan ke sini. --}}
                @if (session()->has('url.intended'))
                    <p class="mt-1.5 text-sm text-slate-600">Silakan masuk untuk melanjutkan. Sesi berakhir setelah 60 menit tanpa aktivitas.</p>
                @endif
            </div>
            <label class="lbl">Email
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="inp">
            </label>
            <label class="lbl">Password
                <input type="password" name="password" required autocomplete="current-password" class="inp">
            </label>
            <button type="submit" class="btn btn-primary w-full">Masuk</button>
            <p class="text-center text-sm"><a href="{{ route('password.request') }}" class="font-medium text-brand-blue underline-offset-4 hover:underline">Lupa password?</a></p>
        </form>
    </div>
</x-layouts.app>
