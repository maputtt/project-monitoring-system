{{--
    Popup formulir bersama (master data; juga dipakai subform halaman project).
    - Slot "trigger": tautan/tombol pembuka. Pakai <a href="..."> ke halaman formulir penuh supaya tanpa JS atau saat dibuka di tab baru tetap berfungsi.
    - Bisa juga dibuka dari mana saja: $dispatch('open-form-modal', 'nama-modal').
    - Slot utama: isian formulir. Slot "footer" (opsional) mengganti tombol Batal/Simpan bawaan.
    - Formulir dikirim biasa (tanpa AJAX); server tetap yang memvalidasi dan memberi izin.
    - Hidden input _modal ikut ter-flash sebagai old(); bersama $errors->any() ini membuka ulang popup yang benar setelah validasi gagal.
      Isian di dalam slot harus memakai old() hanya bila old('_modal') === name (lihat _fields partial), supaya popup baris lain tidak ikut terisi.
    - bag: nama error bag Laravel bila server memvalidasi dengan validateWithBag (subform halaman project). Popup dibuka ulang oleh pemanggil lewat open (bag khusus per popup, tanpa bergantung pada _modal); daftar galat diambil dari bag itu.
    - files: tambahkan enctype multipart untuk unggah file. data: ekspresi x-data (mis. adjustPreview({...})) untuk isi popup; aktif juga di footer.
    - disable-when: ekspresi Alpine yang menonaktifkan tombol kirim bawaan (mis. total persentase belum pas).
--}}
@props(['name', 'title', 'action', 'method' => 'POST', 'subtitle' => null, 'icon' => '/dashboard', 'submitLabel' => 'Simpan', 'size' => 'md', 'open' => false, 'bag' => 'default', 'files' => false, 'data' => null, 'disableWhen' => null])
@php
    $messages = $errors->getBag($bag)->all();
    $failed = $bag === 'default' ? old('_modal') === $name && $messages !== [] : $open && $messages !== [];
    $open = $open || $failed;
    $width = $size === 'lg' ? 'sm:max-w-4xl' : 'sm:max-w-2xl';
@endphp
<span class="contents" x-data="formModal(@js($open))" @open-form-modal.window="$event.detail === @js($name) && show({ target: document.activeElement })"
      @click="if (! $event.ctrlKey && ! $event.metaKey && ! $event.shiftKey && $event.button === 0 && $event.target.closest('a, button')) { $event.preventDefault(); show($event) }">{{ $trigger }}
    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50" @keydown.escape.stop="close()" @keydown.tab="trap($event)"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0" x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
            <div class="absolute inset-0 bg-brand-navy/60 backdrop-blur-sm" @click="backdrop()" aria-hidden="true"></div>
            <div class="pointer-events-none relative flex h-full items-end justify-center sm:items-center sm:p-6">
                <form x-ref="panel" x-show="open" tabindex="-1" method="POST" action="{{ $action }}" @if ($files) enctype="multipart/form-data" @endif role="dialog" aria-modal="true" aria-labelledby="fm-{{ $name }}-title" @if ($subtitle) aria-describedby="fm-{{ $name }}-sub" @endif
                      @input="dirty = true" @submit="submitting = true"
                      x-transition:enter="transition duration-200 ease-out motion-reduce:transition-none" x-transition:enter-start="translate-y-full opacity-0 sm:translate-y-3 sm:scale-95 sm:opacity-0"
                      x-transition:leave="transition duration-150 ease-in motion-reduce:transition-none" x-transition:leave-end="translate-y-full opacity-0 sm:translate-y-2 sm:scale-95"
                      class="pointer-events-auto flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-pop ring-1 ring-slate-900/5 outline-none sm:max-h-[min(90dvh,48rem)] sm:rounded-2xl {{ $width }}">
                    @csrf
                    @if (strtoupper($method) !== 'POST')
                        @method($method)
                    @endif
                    <input type="hidden" name="_modal" value="{{ $name }}">
                    {{-- Pembungkus tanpa kotak: x-data isian (pratinjau) harus mencakup isi dan footer, tetapi x-ref panel tetap milik formModal. --}}
                    <div class="contents" @if ($data) x-data="{{ $data }}" @endif>

                    {{-- Pita judul: gradien merek, chip ikon, garis aksen oranye. --}}
                    <header class="relative shrink-0 overflow-hidden bg-linear-to-br from-brand-navy via-brand-blue-dark to-brand-blue px-5 pt-5 pb-6 text-white sm:px-6">
                        <x-motif class="-right-6 -bottom-10 h-36 w-36 opacity-20" />
                        <span class="mx-auto mb-3 block h-1 w-10 rounded-full bg-white/30 sm:hidden" aria-hidden="true"></span>
                        <div class="relative flex items-start gap-4">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/12 text-brand-orange ring-1 ring-white/20"><x-icon :name="$icon" size="h-6 w-6" /></span>
                            <div class="min-w-0 flex-1">
                                <h2 id="fm-{{ $name }}-title" class="text-lg leading-snug font-semibold break-words text-white sm:text-xl">{{ $title }}</h2>
                                @if ($subtitle)
                                    <p id="fm-{{ $name }}-sub" class="mt-1 text-sm break-words text-blue-100/90">{{ $subtitle }}</p>
                                @endif
                                <span class="mt-3 block h-1 w-12 rounded-full bg-brand-orange" aria-hidden="true"></span>
                            </div>
                            <button type="button" @click="close()" aria-label="Tutup" class="-mt-1 -mr-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-blue-100 transition hover:bg-white/15 hover:text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none">
                                <x-icon name="close" size="h-5 w-5" />
                            </button>
                        </div>
                    </header>

                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5 sm:px-6 sm:py-6">
                        @if ($failed)
                            <div data-fm-errors tabindex="-1" role="alert" class="mb-5 flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 outline-none">
                                <x-icon name="alert" size="mt-0.5 h-5 w-5" class="text-rose-600" />
                                <div>
                                    <p class="font-semibold">Data belum bisa disimpan</p>
                                    <ul class="mt-1 list-disc space-y-0.5 pl-4">
                                        @foreach ($messages as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        @endif
                        {{ $slot }}
                    </div>

                    <footer class="flex shrink-0 gap-3 border-t border-slate-100 bg-slate-50/90 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:justify-end sm:px-6">
                        @isset($footer)
                            {{ $footer }}
                        @else
                            <button type="button" class="btn flex-1 sm:flex-none" @click="close()">Batal</button>
                            <button type="submit" class="btn btn-primary flex-1 sm:flex-none sm:min-w-36" :disabled="submitting{{ $disableWhen ? ' || ('.$disableWhen.')' : '' }}"><span x-text="submitting ? 'Menyimpan…' : @js($submitLabel)">{{ $submitLabel }}</span></button>
                        @endisset
                    </footer>
                    </div>
                </form>
            </div>
        </div>
    </template>
</span>
