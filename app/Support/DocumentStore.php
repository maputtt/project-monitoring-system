<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Menyimpan dokumen ke disk privat (storage/app/private, di luar web root) dan mencatat barisnya di `documents`.
 * Pemanggil wajib sudah memvalidasi file (lihat DocumentController::RULES) dan memanggil ini di dalam DB::transaction.
 * Tidak ada hapus: dokumen lama diganti lewat status REPLACED (story berikutnya).
 */
final class DocumentStore
{
    public const DISK = 'local';

    /** MIME hasil deteksi isi file -> ekstensi yang boleh. Satu-satunya sumber ekstensi di storage_key. */
    public const EXTENSIONS = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];

    /** @return int id baris documents */
    public static function store(UploadedFile $file, int $projectId, string $entityType, int $entityId, string $documentType, int $userId): int
    {
        $mime = $file->getMimeType();
        // Nama di disk acak dan ekstensinya dari MIME terdeteksi, bukan dari nama kiriman klien.
        $key = "documents/$projectId/".Str::uuid().'.'.self::EXTENSIONS[$mime][0];

        Storage::disk(self::DISK)->putFileAs(dirname($key), $file, basename($key), ['visibility' => 'private']);

        try {
            return DB::table('documents')->insertGetId([
                'project_id' => $projectId, 'entity_type' => $entityType, 'entity_id' => $entityId, 'document_type' => $documentType,
                'original_filename' => self::fitName(basename($file->getClientOriginalName())), 'storage_key' => $key, 'mime_type' => $mime,
                'file_size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()),
                'status' => 'UPLOADED', 'uploaded_by' => $userId, 'uploaded_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($key); // jangan tinggalkan berkas yatim bila baris gagal
            throw $e;
        }
    }

    /** Simpan dokumen; bila $replace, versi lama (terbaru yang bukan REPLACED) ditandai REPLACED dan versi baru menunjuk ke sana. $key diisi agar pemanggil bisa menghapus berkas bila transaksinya gagal. */
    public static function storeReplacing(UploadedFile $file, int $projectId, string $entityType, int $entityId, string $documentType, int $userId, bool $replace, ?string &$key): int
    {
        $old = $replace ? DB::table('documents')->where('entity_type', $entityType)->where('entity_id', $entityId)->where('document_type', $documentType)->where('status', '<>', 'REPLACED')->orderByDesc('id')->value('id') : null;
        $id = self::store($file, $projectId, $entityType, $entityId, $documentType, $userId);
        $key = DB::table('documents')->where('id', $id)->value('storage_key');
        if ($old !== null) {
            DB::table('documents')->where('id', $id)->update(['replaced_document_id' => $old]);
            DB::table('documents')->where('id', $old)->update(['status' => 'REPLACED']);
        }

        return $id;
    }

    /** Muat di kolom (255): potong nama dasar, pertahankan ekstensi. */
    private static function fitName(string $name): string
    {
        $ext = '.'.pathinfo($name, PATHINFO_EXTENSION);

        return mb_strlen($name) <= 250 ? $name : mb_substr($name, 0, 250 - mb_strlen($ext)).$ext;
    }
}
