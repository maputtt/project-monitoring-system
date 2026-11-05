{{-- Halaman galat bersama (403, 404, 409, 419). Berdiri sendiri: tanpa sidebar dan tanpa membaca user, karena galat bisa tampil untuk tamu atau saat sesi habis. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }} — {{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-canvas bg-[radial-gradient(60rem_26rem_at_80%_-6rem,rgb(34_63_146/0.09),transparent_70%)] bg-no-repeat text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 px-4 py-10">
        <a href="{{ route('home') }}" class="inline-block self-start rounded-2xl bg-white p-3 shadow-pop"><img src="{{ asset('images/logo.svg') }}" alt="Project Monitoring System" class="h-9 w-auto"></a>
        <section class="card space-y-4 p-6 shadow-pop sm:p-8" role="alert">
            <p class="text-sm font-semibold tracking-wider text-brand-blue">Kode {{ $code }}</p>
            <h1>{{ $heading }}</h1>
            <p class="text-sm text-slate-600">{{ $message }}</p>
            <div class="flex flex-wrap gap-3 pt-2">
                <button type="button" class="btn btn-primary" onclick="history.back()">Kembali</button>
                <a href="{{ route('home') }}" class="btn">Ke beranda</a>
            </div>
        </section>
    </main>
</body>
</html>
