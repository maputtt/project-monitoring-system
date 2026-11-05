{{--
    Modal konfirmasi untuk setiap aksi finansial (PRD 8.1): merangkum dampak sebelum dikirim.
    Slot "trigger" adalah tombol pembuka, slot utama adalah ringkasan, slot "fields" untuk input tambahan.
    Formulir dikirim biasa, jadi pesan sukses baru muncul setelah server menjawab.
--}}
@props(['title', 'action', 'method' => 'POST', 'confirmLabel' => 'Konfirmasi', 'note' => null, 'requireReason' => false])
<span x-data="{ open: false, opener: null, close() { this.open = false; this.$nextTick(() => this.opener?.focus()) } }" @keydown.escape.window="open && close()">
    <span @click="opener = $event.target.closest('button, a'); open = true; $nextTick(() => requestAnimationFrame(() => ($refs.dialog.querySelector('textarea, select, input:not([type=hidden])') ?? $refs.cancel).focus()))">{{ $trigger }}</span>

    <div x-show="open" x-cloak class="fixed inset-0 z-40 flex items-end justify-center bg-brand-navy/55 backdrop-blur-sm motion-safe:animate-fade sm:items-center sm:p-4" @click.self="close()">
        <form x-ref="dialog" method="POST" action="{{ $action }}" role="dialog" aria-modal="true" aria-label="{{ $title }}"
              class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-3xl bg-white p-6 text-left shadow-pop motion-safe:animate-pop sm:rounded-3xl">
            @csrf
            @if (strtoupper($method) !== 'POST')
                @method($method)
            @endif
            <h2 class="text-lg">{{ $title }}</h2>
            @if ($note)
                <p class="mt-1.5 text-sm text-slate-600">{{ $note }}</p>
            @endif
            @if ($slot->isNotEmpty())
                <dl class="mt-4 space-y-1.5 rounded-xl bg-slate-50 p-4 text-sm ring-1 ring-slate-100">{{ $slot }}</dl>
            @endif
            @isset($fields)
                <div class="mt-4 space-y-3">{{ $fields }}</div>
            @endisset
            @if ($requireReason)
                <label class="lbl mt-4">Alasan (wajib)
                    <textarea name="reason" rows="3" required class="inp"></textarea>
                </label>
            @endif
            <div class="mt-6 flex justify-end gap-2">
                <button type="button" class="btn" x-ref="cancel" @click="close()">Batal</button>
                <button type="submit" class="btn btn-primary">{{ $confirmLabel }}</button>
            </div>
        </form>
    </div>
</span>
