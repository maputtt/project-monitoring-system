{{-- Kerangka semua halaman: sidebar navy di desktop, bilah atas tipis, menu bawah melayang di ponsel (PRD bagian 8). Tamu (belum login, tanpa menu) mendapat tata letak terbelah: panel merek di kiri, formulir di kanan. --}}
@props(['title' => null, 'role' => null])
@php
    $user = auth()->user();
    $role ??= $user?->role;
    $menu = $role?->menu() ?? [];
    $guest = ! $user && ! $menu;
    $isCurrent = fn (string $path) => request()->is(explode('/', ltrim($path, '/'))[0].'*');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/images/logo-mark.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-brand-canvas bg-[radial-gradient(60rem_26rem_at_80%_-6rem,rgb(34_63_146/0.09),transparent_70%)] bg-no-repeat text-slate-900 antialiased">
    <div @class(['min-h-screen', 'grid lg:grid-cols-[1.05fr_1fr]' => $guest, 'md:flex' => ! $guest])>
        @if ($guest)
            <aside class="relative flex flex-col justify-center overflow-hidden bg-linear-to-br from-brand-navy via-brand-blue-dark to-brand-blue px-6 py-10 text-white sm:px-10 sm:py-14 lg:p-16">
                <x-motif class="-right-16 -bottom-20 h-64 w-64 opacity-30 sm:-right-20 sm:-bottom-28 sm:h-96 sm:w-96 lg:-right-40 lg:-bottom-44 lg:h-[46rem] lg:w-[46rem]" />
                <div class="relative max-w-lg">
                    <p class="text-xs font-semibold tracking-[0.25em] text-brand-orange uppercase sm:text-sm">Portfolio demo</p>
                    <p class="mt-3 text-5xl font-bold tracking-tight sm:text-6xl lg:text-7xl">PMS</p>
                    <p class="mt-2 text-base font-medium text-blue-100 sm:text-lg lg:text-xl">Project Monitoring System</p>
                    <span class="mt-6 block h-1 w-16 rounded-full bg-brand-orange" aria-hidden="true"></span>
                    <p class="mt-6 hidden max-w-md text-sm leading-relaxed text-blue-100/90 sm:block lg:text-base">Pantau project, anggaran RAB, dan tagihan dalam satu sistem. Setiap perubahan finansial melalui persetujuan dan tercatat di audit log.</p>
                </div>
            </aside>
        @elseif ($menu)
            <aside class="sticky top-0 hidden h-screen w-64 shrink-0 flex-col overflow-hidden bg-linear-to-b from-brand-navy to-brand-blue-dark p-4 md:flex">
                <a href="/" class="relative block rounded-2xl bg-white px-3 py-2 shadow-lg"><img src="{{ asset('images/logo.svg') }}" alt="Project Monitoring System" width="270" height="120" class="mx-auto h-20 w-auto"></a>
                <p class="relative mt-7 mb-2 px-3 text-[11px] font-semibold tracking-wider text-slate-300 uppercase">Menu</p>
                <nav class="relative space-y-1" aria-label="Menu utama">
                    @foreach ($menu as $item)
                        <a href="{{ $item['path'] }}" @if ($isCurrent($item['path'])) aria-current="page" @endif class="nav-item"><x-icon :name="$item['path']" />{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            </aside>
        @endif

        <div @class(['flex min-w-0 flex-1 flex-col', 'lg:justify-center' => $guest])>
            @unless ($guest)
                <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-white/80 px-4 backdrop-blur md:px-8">
                    <a href="/" @class(['shrink-0', 'md:hidden' => $menu])><img src="{{ asset('images/logo-mark.svg') }}" alt="PMS" width="512" height="512" class="h-8 w-8 object-contain"></a>
                    <p class="min-w-0 truncate text-sm text-slate-600">
                        <span class="hidden lg:inline">{{ config('app.name') }} <span aria-hidden="true">/</span></span>
                        <span class="font-semibold text-slate-900">{{ $title }}</span>
                    </p>
                    @if ($user)
                        <div class="relative ml-auto shrink-0" x-data="{ open: false }" @keydown.escape.window="if (open) { open = false; $refs.menubtn.focus() }" @click.outside="open = false">
                            <button type="button" x-ref="menubtn" @click="open = ! open" :aria-expanded="open" aria-controls="user-menu" aria-label="Menu akun {{ $user->name }}"
                                    class="flex items-center gap-2.5 rounded-full py-1 pr-2 pl-1 transition hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:outline-none sm:pr-3">
                                <x-avatar :name="$user->name" size="h-9 w-9 text-xs" />
                                <span class="hidden min-w-0 text-left leading-tight sm:block">
                                    <span class="block max-w-44 truncate text-sm font-semibold">{{ $user->name }}</span>
                                    <span class="block text-xs text-slate-600">{{ $user->role->label() }}</span>
                                </span>
                                <x-icon name="chevron" size="h-4 w-4" class="text-slate-400" />
                            </button>
                            <div id="user-menu" x-show="open" x-cloak x-transition.origin.top.right.duration.150ms
                                 class="absolute right-0 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200/70 bg-white p-4 shadow-pop">
                                <div class="flex items-center gap-3">
                                    <x-avatar :name="$user->name" size="h-11 w-11 text-sm" />
                                    <div class="min-w-0 leading-tight">
                                        <p class="truncate font-semibold">{{ $user->name }}</p>
                                        <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-sm text-slate-600">{{ $user->role->label() }}@if ($user->department)<span> · Divisi {{ $user->department->value }}</span>@endif</p>
                                <form method="POST" action="{{ route('logout') }}" class="mt-4 border-t border-slate-100 pt-4">
                                    @csrf
                                    <button type="submit" class="btn w-full"><x-icon name="logout" size="h-4 w-4" />Keluar</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </header>
            @endunless

            <main @class(['w-full', 'mx-auto max-w-sm px-4 py-10' => $guest, 'mx-auto max-w-6xl p-4 pb-28 md:p-8 md:pb-10' => ! $guest])>
                @if (session('status'))
                    <div class="mb-5 flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 shadow-xs motion-safe:animate-pop" role="status">
                        <x-icon name="check" size="mt-0.5 h-5 w-5" class="text-emerald-600" /><p>{{ session('status') }}</p>
                    </div>
                @endif
                @if (session('warning'))
                    <div class="mb-5 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 shadow-xs motion-safe:animate-pop" role="status">
                        <x-icon name="alert" size="mt-0.5 h-5 w-5" class="text-amber-600" /><p>{{ session('warning') }}</p>
                    </div>
                @endif
                {{-- Galat dari popup formulir ditampilkan di dalam popup yang dibuka ulang, bukan di sini (menghindari banner ganda). --}}
                @if ($errors->any() && ! old('_modal'))
                    <div class="mb-5 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 shadow-xs motion-safe:animate-pop" role="alert">
                        <x-icon name="alert" size="mt-0.5 h-5 w-5" class="text-rose-600" />
                        <div>
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>

    @if ($menu)
        <nav class="fixed inset-x-3 bottom-3 z-20 flex gap-1 rounded-2xl bg-brand-navy/95 p-1.5 shadow-pop ring-1 ring-white/10 backdrop-blur md:hidden" aria-label="Menu utama">
            @foreach ($menu as $item)
                <a href="{{ $item['path'] }}" @if ($isCurrent($item['path'])) aria-current="page" @endif
                   @class(['flex min-w-0 flex-1 flex-col items-center gap-1 rounded-xl px-1 py-2 text-center text-[11px] leading-tight font-medium break-words transition-colors focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:outline-none', 'bg-white/12 font-semibold text-white' => $isCurrent($item['path']), 'text-slate-300' => ! $isCurrent($item['path'])])><x-icon :name="$item['path']" :class="$isCurrent($item['path']) ? 'text-brand-orange' : ''" />{{ $item['label'] }}</a>
            @endforeach
        </nav>
    @endif
</body>
</html>
