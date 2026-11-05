<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Models\TaxRate;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Master tarif pajak (PRD 6.1, FD-18). Hanya tambah versi baru; tidak ada ubah atau hapus. */
class TaxRateController extends Controller
{
    public function index(): View
    {
        $rates = TaxRate::query()->orderBy('tax_type')->orderBy('vendor_qualification')->orderByDesc('effective_from')->get();

        return view('tax-rates.index', ['rates' => $rates]);
    }

    public function create(): View
    {
        return view('tax-rates.form');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data += ['vendor_qualification' => null]; // PPN: kualifikasi dibuang oleh exclude_unless

        DB::transaction(function () use ($data, $request) {
            $series = TaxRate::query()
                ->where('tax_type', $data['tax_type'])->where('vendor_qualification', $data['vendor_qualification'])
                ->lockForUpdate()->get();

            $latest = $series->sortByDesc('effective_from')->first();
            if ($latest && $data['effective_from'] <= $latest->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'Tanggal mulai berlaku harus setelah '.$latest->effective_from->format('d/m/Y').', versi terakhir tarif ini.']);
            }

            if ($open = $series->whereNull('effective_until')->first()) {
                $open->effective_until = Carbon::parse($data['effective_from'])->subDay();
                $open->save();
                Audit::record(AuditAction::UPDATE, 'TAX_RATE', $open->id, ['effective_until' => null], ['effective_until' => $open->effective_until->toDateString()]);
            }

            $rate = (new TaxRate($data))->forceFill(['created_by' => $request->user()->id]);
            $rate->save();
            Audit::record(AuditAction::CREATE, 'TAX_RATE', $rate->id, after: array_intersect_key($rate->fresh()->toArray(), $data));
        }, 3); // deadlock pada seri kosong (dua versi pertama bersamaan): ulang, seri dibaca ulang di dalam transaksi

        return redirect()->route('tax-rates.index')->with('status', 'Versi tarif pajak ditambahkan.');
    }

    private function validated(Request $request): array
    {
        // Versi tarif tidak bisa diubah atau dihapus, jadi salah ketik tahun jauh ke depan akan memblokir versi benar selamanya.
        $latest = now(config('app.local_timezone'))->startOfDay()->addYearNoOverflow();

        return $request->validate([
            'tax_type' => ['required', 'in:PPN,PPH'],
            'name' => ['required', 'string', 'max:150'],
            'vendor_qualification' => ['exclude_unless:tax_type,PPH', 'required', 'in:A,B'],
            'rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$latest->toDateString()],
        ], [
            'tax_type.required' => 'Jenis pajak wajib dipilih.',
            'tax_type.in' => 'Jenis pajak tidak dikenal.',
            'name.required' => 'Nama tarif wajib diisi.',
            'name.string' => 'Nama tarif tidak valid.',
            'name.max' => 'Nama tarif maksimal 150 karakter.',
            'vendor_qualification.required' => 'Kualifikasi vendor wajib dipilih untuk PPh.',
            'vendor_qualification.in' => 'Kualifikasi vendor harus A atau B.',
            'rate.required' => 'Tarif wajib diisi.',
            'rate.decimal' => 'Tarif maksimal dua angka desimal.',
            'rate.min' => 'Tarif tidak boleh negatif.',
            'rate.max' => 'Tarif tidak boleh lebih dari 100%.',
            'effective_from.required' => 'Tanggal mulai berlaku wajib diisi.',
            'effective_from.date_format' => 'Tanggal mulai berlaku tidak valid.',
            'effective_from.before_or_equal' => 'Tanggal mulai berlaku paling lambat '.$latest->format('d/m/Y').' (satu tahun dari hari ini).',
        ]);
    }
}
