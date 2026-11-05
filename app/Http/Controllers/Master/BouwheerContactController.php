<?php

namespace App\Http\Controllers\Master;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\BouwheerContact;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Data master PIC Bouwheer (PRD 16.2.3, FD-10, FD-17). Tidak ada hapus; kontak yang tidak dipakai lagi dinonaktifkan. */
class BouwheerContactController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $contacts = BouwheerContact::query()
            ->withCount('projects')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->whereLike('name', "%{$search}%")->orWhereLike('company_name', "%{$search}%")))
            ->orderBy('company_name')->orderBy('name')
            ->paginate(20)->withQueryString();

        return view('master.contacts.index', ['contacts' => $contacts, 'search' => $search]);
    }

    public function create(): View
    {
        return view('master.contacts.form', ['contact' => new BouwheerContact(['is_active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $contact = BouwheerContact::create($data);
            Audit::record(AuditAction::CREATE, 'BOUWHEER_CONTACT', $contact->id, after: $contact->fresh()->only(array_keys($data)));
        });

        return redirect()->route('master.bouwheer-contacts.index')->with('status', 'PIC Bouwheer berhasil ditambahkan.');
    }

    public function edit(BouwheerContact $contact): View
    {
        return view('master.contacts.form', ['contact' => $contact]);
    }

    public function update(Request $request, BouwheerContact $contact): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $contact) {
            $before = $contact->only(array_keys($data));
            $contact->update($data);
            Audit::record(AuditAction::UPDATE, 'BOUWHEER_CONTACT', $contact->id, $before, $contact->fresh()->only(array_keys($data)));
        });

        return redirect()->route('master.bouwheer-contacts.index')->with('status', 'PIC Bouwheer diperbarui.');
    }

    private function validated(Request $request): array
    {
        // Checkbox yang tidak dicentang tidak terkirim, jadi nilainya dipastikan ada sebelum validasi.
        $request->merge(['is_active' => $request->boolean('is_active')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'company_name' => ['required', 'string', 'max:200'],
            'position' => ['nullable', 'string', 'max:100'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'Nama PIC wajib diisi.',
            'company_name.required' => 'Nama perusahaan wajib diisi.',
            'email.email' => 'Format email tidak valid.',
        ]);
    }
}
