@php use App\Http\Controllers\InvoiceController; use Illuminate\Support\Carbon; @endphp
<x-layouts.app title="Kendala aktif">
    <div class="page-head">
        <div class="min-w-0">
            <a href="{{ route('dashboard', $department ? ['department' => $department] : []) }}" class="btn mb-3"><x-icon name="arrow" size="h-4 w-4" class="rotate-180" />Dashboard</a>
            <h1 class="mt-1">Kendala aktif</h1>
            <p class="text-sm text-slate-600">Termin yang catatan progres penagihan terbarunya bertanda kendala{{ $department ? ' · Divisi '.$department : '' }}. Pilih termin untuk membuka project.</p>
        </div>
        <p class="ml-auto self-end text-sm text-slate-600">{{ number_format($items->total(), 0, ',', '.') }} termin</p>
    </div>

    @if ($items->isEmpty())
        <div class="card empty" role="status"><p>Tidak ada kendala penagihan yang aktif.</p></div>
    @else
        <ul class="grid gap-3">
            @foreach ($items as $item)
                <li class="min-w-0">
                    <a href="{{ route('projects.show', $item->project_id) }}#jadwal" class="group block rounded-2xl border border-slate-200/70 bg-white p-4 shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-brand-blue/30 hover:shadow-pop focus-visible:ring-2 focus-visible:ring-brand-blue focus-visible:ring-offset-2 focus-visible:outline-none sm:p-5">
                        <p class="text-xs font-medium [overflow-wrap:anywhere] text-slate-600">{{ $item->project_code }} · {{ $item->project_name }}</p>
                        <p class="mt-0.5 font-semibold text-slate-900 group-hover:text-brand-blue">Termin {{ $item->sequence_number }} · {{ InvoiceController::PROGRESS_STAGES[$item->stage] ?? $item->stage }}</p>
                        <p class="mt-1 text-sm [overflow-wrap:anywhere] whitespace-pre-line text-slate-700">{{ $item->description }}</p>
                        <p class="mt-1 text-xs text-slate-600">{{ Carbon::parse($item->progress_date)->format('d/m/Y') }} · oleh {{ $item->author }}</p>
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    <div class="mt-4">{{ $items->links() }}</div>
</x-layouts.app>
