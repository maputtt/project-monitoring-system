<x-layouts.app title="Lupa password">
    <form method="POST" action="{{ route('password.email') }}" class="card space-y-5 p-6 shadow-pop sm:p-8">
        @csrf
        <div>
            <h1>Lupa password</h1>
            <p class="mt-1.5 text-sm text-slate-600">Masukkan email akun Anda. Kami kirim tautan untuk membuat password baru.</p>
        </div>
        <label class="lbl">Email
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="inp">
        </label>
        <button type="submit" class="btn btn-primary w-full">Kirim tautan reset</button>
        <p class="text-center text-sm"><a href="{{ route('login') }}" class="font-medium text-brand-blue underline-offset-4 hover:underline">Kembali ke halaman masuk</a></p>
    </form>
</x-layouts.app>
