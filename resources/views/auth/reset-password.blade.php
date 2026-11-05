<x-layouts.app title="Password baru">
    <form method="POST" action="{{ route('password.update') }}" class="card space-y-5 p-6 shadow-pop sm:p-8">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <h1>Buat password baru</h1>
        <label class="lbl">Email
            <input type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="username" class="inp">
        </label>
        <label class="lbl">Password baru
            <input type="password" name="password" required minlength="8" autocomplete="new-password" class="inp">
            <span class="hint">Minimal 8 karakter.</span>
        </label>
        <label class="lbl">Ulangi password baru
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password" class="inp">
        </label>
        <button type="submit" class="btn btn-primary w-full">Simpan password</button>
    </form>
</x-layouts.app>
