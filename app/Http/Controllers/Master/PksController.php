<?php

namespace App\Http\Controllers\Master;

use App\Enums\AuditAction;
use App\Enums\PksStatus;
use App\Http\Controllers\Controller;
use App\Models\Pks;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Data master PKS (PRD 16.2.2, FD-17). Tidak ada hapus; PKS yang tidak dipakai lagi diberi status ARCHIVED atau CANCELLED. */
class PksController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $pks = Pks::query()
            ->withCount('projects')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->whereLike('pks_number', "%{$search}%")->orWhereLike('name', "%{$search}%")->orWhereLike('client_name', "%{$search}%")))
            ->orderByDesc('issued_date')->orderBy('pks_number')
            ->paginate(20)->withQueryString();

        return view('master.pks.index', ['pks' => $pks, 'search' => $search]);
    }

    public function create(): View
    {
        return view('master.pks.form', ['pks' => new Pks(['status' => PksStatus::ACTIVE])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request) {
            $pks = (new Pks($data))->forceFill(['created_by' => $request->user()->id]);
            $pks->save();
            Audit::record(AuditAction::CREATE, 'PKS', $pks->id, after: $this->snapshot($pks, $data));
        });

        return redirect()->route('master.pks.index')->with('status', 'PKS berhasil ditambahkan.');
    }

    public function edit(Pks $pks): View
    {
        return view('master.pks.form', ['pks' => $pks]);
    }

    public function update(Request $request, Pks $pks): RedirectResponse
    {
        $data = $this->validated($request, $pks);

        DB::transaction(function () use ($data, $pks) {
            $before = $this->snapshot($pks, $data);
            $pks->update($data);
            Audit::record(AuditAction::UPDATE, 'PKS', $pks->id, $before, $this->snapshot($pks, $data));
        });

        return redirect()->route('master.pks.index')->with('status', 'PKS diperbarui.');
    }

    private function snapshot(Pks $pks, array $data): array
    {
        return array_intersect_key($pks->fresh()->toArray(), $data);
    }

    private function validated(Request $request, ?Pks $pks = null): array
    {
        return $request->validate([
            'pks_number' => ['required', 'string', 'max:100', Rule::unique('pks', 'pks_number')->ignore($pks)],
            'name' => ['required', 'string', 'max:200'],
            'client_name' => ['required', 'string', 'max:200'],
            'role_mode' => ['nullable', 'string', 'max:50'],
            'issued_date' => ['required', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(PksStatus::class)],
        ], [
            'pks_number.required' => 'Nomor PKS wajib diisi.',
            'pks_number.unique' => 'Nomor PKS sudah dipakai.',
            'name.required' => 'Nama PKS wajib diisi.',
            'client_name.required' => 'Nama klien wajib diisi.',
            'issued_date.required' => 'Tanggal terbit wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal berakhir tidak boleh lebih awal dari tanggal mulai.',
            'status.enum' => 'Status tidak dikenal.',
        ]);
    }
}
