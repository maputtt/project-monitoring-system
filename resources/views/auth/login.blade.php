<x-layouts.app title="Login">
    <form method="POST" action="{{ route('login') }}" class="card space-y-5 p-6 shadow-pop sm:p-8">
        @csrf
        <div>
            <h1>Masuk</h1>
            {{-- Laravel menyimpan halaman tujuan saat user yang belum login atau sesinya habis dialihkan ke sini. --}}
            @if (session()->has('url.intended'))
                <p class="mt-1.5 text-sm text-slate-600">Silakan masuk untuk melanjutkan. Sesi berakhir setelah 30 menit tanpa aktivitas.</p>
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
</x-layouts.app>
