{{-- Tombol "i" berisi definisi singkat: muncul saat hover (desktop), tap, atau fokus keyboard; tutup dengan Escape, klik di luar, atau scroll. Posisi fixed dijepit ke lebar layar supaya tidak keluar viewport di ponsel. Slot = teks definisi. --}}
@props(['label'])
@php $id = 'tip-'.\Illuminate\Support\Str::random(6); @endphp
<span x-data="{ open: false, x: 8, y: 0, w: 256,
        show() { const r = $refs.b.getBoundingClientRect(); this.w = Math.min(256, innerWidth - 16); this.x = Math.max(8, Math.min(r.right - this.w, innerWidth - this.w - 8)); this.y = r.bottom + 6; this.open = true; this.$nextTick(() => { const h = this.$refs.t.offsetHeight; if (this.y + h > innerHeight - 8) this.y = Math.max(8, r.top - h - 6); }); } }"
    @mouseenter="show()" @mouseleave="open = false" @keydown.escape.window="open = false" @click.outside="open = false" @scroll.window="open = false" :class="open && 'z-30'"
    {{ $attributes->class(['inline-flex']) }}>
    <button type="button" x-ref="b" aria-label="Penjelasan {{ $label }}" aria-describedby="{{ $id }}" :aria-expanded="open.toString()"
        @click="show()" @focus="$el.matches(':focus-visible') && show()" @blur="open = false"
        class="flex h-7 w-7 items-center justify-center rounded-full text-slate-500 hover:bg-slate-100 hover:text-brand-blue focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:outline-none">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
    </button>
    <span id="{{ $id }}" x-ref="t" role="tooltip" x-cloak x-show="open" :style="`left:${x}px;top:${y}px;width:${w}px`"
        class="fixed z-50 rounded-xl bg-slate-900 px-3 py-2 text-left text-xs font-normal leading-relaxed text-white shadow-pop">{{ $slot }}</span>
</span>
