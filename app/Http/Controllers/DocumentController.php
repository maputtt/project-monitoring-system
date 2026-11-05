<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Audit;
use App\Support\DocumentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Dokumen privat (DOC-01). Unggah di level project; file hanya bisa diunduh lewat URL bertanda tangan 5 menit. Tidak ada hapus. */
class DocumentController extends Controller
{
    public const MAX_KB = 51200; // 50 MB

    public const PROJECT_DOCUMENT_TYPES = ['NOTA_DISPOSISI', 'BAPP', 'BAST', 'BUKTI_BAYAR', 'OTHER'];

    public const FILE_MESSAGES = [
        'file.required' => 'Pilih file yang akan diunggah.', 'file.max' => 'Ukuran file maksimal 50 MB.',
        'file.mimes' => 'File harus PDF, JPG, atau PNG.', 'file.mimetypes' => 'File harus PDF, JPG, atau PNG.', 'file.uploaded' => 'File gagal diunggah. Periksa ukuran file (maksimal 50 MB).',
    ];

    /** Aturan file bersama (juga dipakai Nota Disposisi mutasi budget). mimes + mimetypes memeriksa isi file; closure memastikan ekstensi nama cocok dengan MIME terdeteksi (PDF bernama .png ditolak). */
    public static function fileRules(bool $required = true): array
    {
        return ['bail', $required ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.self::MAX_KB,
            fn ($attr, $file, $fail) => in_array(strtolower($file->getClientOriginalExtension()), DocumentStore::EXTENSIONS[$file->getMimeType()] ?? [], true) || $fail('Ekstensi file tidak sesuai dengan isi file.')];
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'file' => self::fileRules(),
            'document_type' => ['required', Rule::in(self::PROJECT_DOCUMENT_TYPES)],
        ], self::FILE_MESSAGES);
        $user = $request->user();

        $key = null;
        try {
            DB::transaction(function () use ($project, $data, $request, $user, &$key) {
                $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
                abort_if(in_array($locked->status, [ProjectStatus::FINANCIALLY_CLOSED, ProjectStatus::ARCHIVED, ProjectStatus::CANCELLED], true), 403, 'Dokumen tidak bisa ditambah pada project yang sudah ditutup, diarsip, atau dibatalkan.');

                $id = DocumentStore::store($request->file('file'), $locked->id, 'PROJECT', $locked->id, $data['document_type'], $user->id);
                $row = DB::table('documents')->where('id', $id)->first();
                $key = $row->storage_key;
                // Audit mencatat metadata, bukan isi file. entity_type DOCUMENT; project_id ada di data_after.
                Audit::record(AuditAction::CREATE, 'DOCUMENT', $id, after: Arr::only((array) $row, ['project_id', 'entity_type', 'entity_id', 'document_type', 'original_filename', 'mime_type', 'file_size', 'checksum', 'status']));
            });
        } catch (\Throwable $e) {
            // Transaksi membatalkan barisnya, jadi berkasnya jangan tertinggal tanpa pemilik.
            $key && Storage::disk(DocumentStore::DISK)->delete($key);
            throw $e;
        }

        return redirect()->route('projects.show', $project)->with('status', 'Dokumen diunggah.');
    }

    /** Tautan di halaman project. Tidak bertanda tangan: cek jangkauan dulu, lalu alihkan ke URL unduh baru berlaku 5 menit, sehingga halaman yang lama terbuka tidak berisi tautan mati. */
    public function open(Request $request, int $document): RedirectResponse
    {
        $this->visibleDocument($request, $document);

        return redirect(URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $document]));
    }

    private function visibleDocument(Request $request, int $document): object
    {
        $doc = DB::table('documents')->where('id', $document)->first();
        abort_unless($doc && Project::query()->visibleTo($request->user())->whereKey($doc->project_id)->exists(), 404);

        return $doc;
    }

    /** Route `signed`: tanda tangan hilang, salah, atau lewat 5 menit menjawab 403. Project di luar jangkauan menjawab 404. */
    public function download(Request $request, int $document): StreamedResponse
    {
        $doc = $this->visibleDocument($request, $document);
        abort_unless(Storage::disk(DocumentStore::DISK)->exists($doc->storage_key), 404);

        return Storage::disk(DocumentStore::DISK)->download($doc->storage_key, $doc->original_filename, ['Content-Type' => $doc->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }
}
