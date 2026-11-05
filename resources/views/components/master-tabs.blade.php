{{-- Sub-menu Data Master: hanya halaman yang boleh dibuka role ini. Staff/PIC: PKS dan PIC Bouwheer. System Admin: hanya Pengguna, jadi tanpa tab. --}}
@php
    $tabs = auth()->user()->role === \App\Enums\Role::SYSTEM_ADMIN
        ? ['Pengguna' => 'master.users.index']
        : ['PKS' => 'master.pks.index', 'PIC Bouwheer' => 'master.bouwheer-contacts.index'];
@endphp
@if (count($tabs) > 1)
    <nav class="mb-6 flex w-fit max-w-full gap-1 overflow-x-auto rounded-xl bg-slate-200/60 p-1" aria-label="Data master">
        @foreach ($tabs as $label => $route)
            @php
                $current = request()->routeIs(Str::beforeLast($route, '.').'.*');
            @endphp
            <a href="{{ route($route) }}" @if ($current) aria-current="page" @endif
               @class(['rounded-lg px-4 py-2 text-sm font-medium whitespace-nowrap transition focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:outline-none', 'bg-white font-semibold text-brand-blue shadow-sm' => $current, 'text-slate-600 hover:text-slate-900' => ! $current])>{{ $label }}</a>
        @endforeach
    </nav>
@endif
