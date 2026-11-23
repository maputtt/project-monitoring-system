{{-- Isi kunci Face ID dipakai overlay (latar gelap) dan halaman login (kartu terang). Bergantung pada state komponen Alpine passkeyLock / passkeyLogin: busy, msg, leaving, unlock(), usePassword(). --}}
@props(['dark' => false])
<div class="w-full max-w-sm text-center">
    <p class="text-3xl font-bold tracking-tight {{ $dark ? 'text-white' : 'text-brand-navy' }}">PMS</p>
    <h2 id="lock-title" class="mt-4 text-lg font-semibold {{ $dark ? 'text-white' : '' }}">Aplikasi terkunci</h2>
    <p class="mt-1.5 text-sm {{ $dark ? 'text-blue-100' : 'text-slate-600' }}">Buka dengan Face ID atau sidik jari untuk melanjutkan.</p>
    <p x-show="msg" x-text="msg" role="status" aria-live="polite" class="mt-4 text-sm font-medium {{ $dark ? 'text-amber-200' : 'text-rose-700' }}"></p>
    <button type="button" x-ref="unlockbtn" @click="unlock()" :disabled="busy || leaving"
            class="mt-6 w-full rounded-2xl px-5 py-4 text-base font-semibold shadow-pop transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:translate-y-px disabled:cursor-not-allowed disabled:opacity-60 {{ $dark ? 'bg-brand-orange text-brand-navy focus-visible:ring-white focus-visible:ring-offset-brand-navy' : 'bg-brand-blue text-white focus-visible:ring-brand-blue' }}">
        <span x-text="busy || leaving ? 'Memverifikasi…' : 'Buka dengan Face ID'"></span>
    </button>
    <button type="button" @click="usePassword()" :disabled="leaving"
            class="mt-4 rounded-md px-2 py-2 text-sm font-medium underline underline-offset-4 focus-visible:ring-2 focus-visible:outline-none {{ $dark ? 'text-white focus-visible:ring-white' : 'text-brand-blue focus-visible:ring-brand-blue' }}">Gunakan password</button>
</div>
