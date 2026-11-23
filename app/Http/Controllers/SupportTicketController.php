<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Support\Audit;
use App\Support\DocumentStore;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Tiket laporan masalah (Story C): semua peran melapor; System Admin melihat semua dan mengatur status. Tidak ada route hapus. */
class SupportTicketController extends Controller
{
    private const PER_PAGE = 20;

    private const MAX_KB = 5120; // 5 MB

    public function index(Request $request): View
    {
        $user = $request->user();
        $filter = $request->validate(['status' => ['nullable', Rule::enum(SupportTicketStatus::class)]], ['status.*' => 'Status tidak dikenal.']);
        $tickets = SupportTicket::query()->with('reporter:id,name,role')
            ->when($user->role !== Role::SYSTEM_ADMIN, fn ($q) => $q->where('reporter_id', $user->id))
            ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();

        return view('support-tickets.index', ['tickets' => $tickets, 'all' => $user->role === Role::SYSTEM_ADMIN]);
    }

    public function create(Request $request): View
    {
        return view('support-tickets.create', ['pageUrl' => SupportTicket::safePageUrl(is_string($request->query('page')) ? $request->query('page') : null)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'page_url' => ['nullable', 'string'],
            'screenshot' => ['bail', 'nullable', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png', 'max:'.self::MAX_KB,
                fn ($attr, $file, $fail) => in_array(strtolower($file->getClientOriginalExtension()), DocumentStore::EXTENSIONS[$file->getMimeType()] ?? [], true) || $fail('Ekstensi file tidak sesuai dengan isi file.')],
        ], [
            'title.required' => 'Judul wajib diisi.', 'title.max' => 'Judul maksimal 150 karakter.',
            'description.required' => 'Uraian masalah wajib diisi.', 'description.max' => 'Uraian maksimal 5000 karakter.',
            'screenshot.*' => 'Tangkapan layar harus berupa JPG atau PNG dengan ukuran maksimal 5 MB.',
        ]);

        $file = $request->file('screenshot');
        $key = $file ? 'support-tickets/'.Str::uuid().'.'.DocumentStore::EXTENSIONS[$file->getMimeType()][0] : null; // ekstensi dari MIME terdeteksi
        $file && Storage::disk(DocumentStore::DISK)->putFileAs(dirname($key), $file, basename($key), ['visibility' => 'private']);

        try {
            $ticket = DB::transaction(function () use ($data, $request, $file, $key) {
                $ticket = SupportTicket::create([
                    'reporter_id' => $request->user()->id, 'title' => $data['title'], 'description' => $data['description'],
                    'page_url' => SupportTicket::safePageUrl($data['page_url'] ?? null),
                    'screenshot_path' => $key, 'screenshot_mime' => $file?->getMimeType(), 'status' => SupportTicketStatus::NEW,
                ]);
                Audit::record(AuditAction::CREATE, 'SUPPORT_TICKET', $ticket->id, after: [
                    'title' => $ticket->title, 'status' => $ticket->status->value, 'page_url' => $ticket->page_url, // tanpa uraian: audit dibaca DIREKSI
                ]);

                return $ticket;
            });
        } catch (\Throwable $e) {
            $key && Storage::disk(DocumentStore::DISK)->delete($key); // jangan tinggalkan berkas yatim
            throw $e;
        }

        Notify::send('ticket_created', $ticket, $request->user(), 'Tiket baru #'.$ticket->id.' dari '.$request->user()->name);

        return redirect()->route('support-tickets.show', $ticket)->with('status', 'Laporan terkirim.');
    }

    public function show(SupportTicket $ticket): View
    {
        $this->visible($ticket);

        return view('support-tickets.show', ['ticket' => $ticket->load('reporter:id,name,role')]);
    }

    /** Dialirkan lewat controller (disk privat), dengan MIME tersimpan dan nosniff; bukan pelapor/admin menjawab 404. */
    public function screenshot(SupportTicket $ticket): Response
    {
        $this->visible($ticket);
        abort_unless($ticket->screenshot_path && Storage::disk(DocumentStore::DISK)->exists($ticket->screenshot_path), 404);

        return Storage::disk(DocumentStore::DISK)->response($ticket->screenshot_path, 'tangkapan-layar-'.$ticket->id.'.'.pathinfo($ticket->screenshot_path, PATHINFO_EXTENSION), [
            'Content-Type' => $ticket->screenshot_mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** Hanya System Admin; selain itu 404 seragam (tanpa middleware role agar id ada/tidak ada tidak terbedakan). Status boleh pindah ke mana saja; resolved_at terisi saat Selesai dan dikosongkan saat dibuka lagi. */
    public function update(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($request->user()->role === Role::SYSTEM_ADMIN, 404);
        $data = $request->validate([
            'status' => ['required', Rule::enum(SupportTicketStatus::class)],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ], ['status.*' => 'Status tidak dikenal.', 'admin_note.max' => 'Catatan maksimal 2000 karakter.']);
        $status = SupportTicketStatus::from($data['status']);
        $note = filled($data['admin_note'] ?? null) ? $data['admin_note'] : null;

        $changed = DB::transaction(function () use ($ticket, $status, $note, $request) {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->status === $status && $locked->admin_note === $note) {
                return false; // tidak ada perubahan: tidak ada tulisan, audit, dan notifikasi
            }
            $before = ['status' => $locked->status->value, 'admin_note' => $locked->admin_note, 'resolved_at' => $locked->resolved_at?->toIso8601String()];
            $locked->forceFill([
                'status' => $status, 'admin_note' => $note, 'handled_by' => $request->user()->id,
                'resolved_at' => $status === SupportTicketStatus::RESOLVED ? ($locked->resolved_at ?? now()) : null,
            ])->save();
            Audit::record(AuditAction::UPDATE, 'SUPPORT_TICKET', $locked->id, $before, [
                'status' => $status->value, 'admin_note' => $note, 'resolved_at' => $locked->resolved_at?->toIso8601String(),
            ]);

            return true;
        });

        $changed && Notify::send('ticket_updated', $ticket, $request->user(), 'Tiket #'.$ticket->id.' diperbarui: '.$status->label());

        return redirect()->route('support-tickets.show', $ticket)->with('status', 'Tiket diperbarui.');
    }

    private function visible(SupportTicket $ticket): void
    {
        abort_unless(Gate::allows('view', $ticket), 404);
    }
}
