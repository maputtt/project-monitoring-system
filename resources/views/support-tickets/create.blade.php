<x-layouts.app title="Laporkan Masalah">
    <a href="{{ route('support-tickets.index') }}" class="btn mb-3">← Kembali ke tiket</a>
    <div class="mb-6">
        <h1>Laporkan Masalah</h1>
        <p class="mt-1 text-sm text-slate-600">Ceritakan kendala yang Anda temui agar tim System Admin dapat menindaklanjuti.</p>
    </div>

    <form method="POST" action="{{ route('support-tickets.store') }}" enctype="multipart/form-data" class="card max-w-2xl space-y-5">
        @csrf
        <input type="hidden" name="page_url" value="{{ old('page_url', $pageUrl) }}">

        <div role="note" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Jangan menulis kata sandi atau data finansial yang tidak perlu (nilai kontrak, rekening, NPWP) pada judul, uraian, maupun tangkapan layar. Cukup sebutkan nomor atau nama halaman yang bermasalah.
        </div>

        <div>
            <label class="lbl" for="title">Judul</label>
            <input id="title" name="title" value="{{ old('title') }}" required maxlength="150" aria-describedby="title-hint @error('title') title-err @enderror" class="inp">
            <span id="title-hint" class="hint">Ringkas, maksimal 150 karakter.</span>
            @error('title')<p id="title-err" class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="lbl" for="description">Uraian masalah</label>
            <textarea id="description" name="description" rows="6" required maxlength="5000" aria-describedby="description-hint @error('description') description-err @enderror" class="inp">{{ old('description') }}</textarea>
            <span id="description-hint" class="hint">Apa yang Anda lakukan, apa yang terjadi, dan apa yang Anda harapkan. Maksimal 5000 karakter.</span>
            @error('description')<p id="description-err" class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="lbl" for="screenshot">Tangkapan layar (opsional)</label>
            <input id="screenshot" type="file" name="screenshot" accept="image/png,image/jpeg" aria-describedby="screenshot-hint @error('screenshot') screenshot-err @enderror" class="inp file:mr-3 file:rounded-lg file:border-0 file:bg-brand-blue-soft file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-blue">
            <span id="screenshot-hint" class="hint">Format JPG atau PNG, maksimal 5 MB.</span>
            @error('screenshot')<p id="screenshot-err" class="mt-1 text-sm text-rose-700" role="alert">{{ $message }}</p>@enderror
        </div>
        @if (old('page_url', $pageUrl))
            <p class="text-sm text-slate-600">Halaman asal: <span class="break-all text-slate-800">{{ old('page_url', $pageUrl) }}</span></p>
        @endif

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Kirim laporan</button>
            <a href="{{ route('support-tickets.index') }}" class="btn">Batal</a>
        </div>
    </form>
</x-layouts.app>
