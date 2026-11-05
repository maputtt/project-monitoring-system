<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

// DOC-01 (PRD FD-11, AC-14, AC-19): dokumen privat, unggah divalidasi server, unduh hanya lewat URL bertanda tangan 5 menit.
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->staff = User::factory()->role('STAFF_PIC', 'BM')->create();
        $this->project = Project::factory()->assignedTo($this->staff)->create(['created_by' => $this->staff->id, 'status' => 'ACTIVE']);
    }

    private function upload(?UploadedFile $file = null, ?User $user = null, array $extra = [])
    {
        return $this->actingAs($user ?? $this->staff)->post("/projects/{$this->project->id}/documents", $extra + ['document_type' => 'BAPP', 'file' => $file ?? UploadedFile::fake()->create('Laporan Asli.pdf', 100, 'application/pdf')]);
    }

    private function uploaded(): object
    {
        $this->upload()->assertSessionHasNoErrors();

        return DB::table('documents')->orderByDesc('id')->first();
    }

    private function signed(int $id): string
    {
        return URL::temporarySignedRoute('documents.download', now()->addMinutes(5), ['document' => $id]);
    }

    public function test_upload_stores_private_file_with_random_key_checksum_and_audit(): void
    {
        $file = UploadedFile::fake()->create('Laporan Asli.pdf', 100, 'application/pdf');
        $this->upload($file)->assertRedirect("/projects/{$this->project->id}")->assertSessionHasNoErrors();

        $doc = DB::table('documents')->first();
        $this->assertSame('Laporan Asli.pdf', $doc->original_filename);
        $this->assertMatchesRegularExpression('#^documents/'.$this->project->id.'/[0-9a-f-]{36}\.pdf$#', $doc->storage_key);
        Storage::disk('local')->assertExists($doc->storage_key);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $doc->checksum);
        $this->assertSame(['PROJECT', $this->project->id, 'UPLOADED', 'application/pdf', $this->staff->id], [$doc->entity_type, $doc->entity_id, $doc->status, $doc->mime_type, $doc->uploaded_by]);
        $this->assertSame(1, DB::table('audit_logs')->where(['entity_type' => 'DOCUMENT', 'entity_id' => $doc->id, 'action' => 'CREATE', 'user_id' => $this->staff->id])->count());
    }

    public function test_two_uploads_of_same_file_get_different_keys(): void
    {
        $this->upload();
        $this->upload();
        $this->assertSame(2, DB::table('documents')->distinct()->count('storage_key'));
    }

    public function test_png_and_exactly_50_mb_are_accepted(): void
    {
        $this->upload(UploadedFile::fake()->create('foto.png', 10, 'image/png'))->assertSessionHasNoErrors();
        $this->upload(UploadedFile::fake()->create('pas.pdf', 51200, 'application/pdf'))->assertSessionHasNoErrors();
        $this->assertSame(['image/png', 'application/pdf'], DB::table('documents')->orderBy('id')->pluck('mime_type')->all());
    }

    public function test_invalid_files_are_rejected_and_nothing_is_stored(): void
    {
        $bad = [
            'too large' => UploadedFile::fake()->create('besar.pdf', 51201, 'application/pdf'),
            'exe' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
            'txt' => UploadedFile::fake()->create('catatan.txt', 10, 'text/plain'),
            'pdf renamed png' => UploadedFile::fake()->create('palsu.png', 10, 'application/pdf'),
            'png renamed pdf' => UploadedFile::fake()->create('palsu.pdf', 10, 'image/png'),
        ];
        foreach ($bad as $file) {
            $this->upload($file)->assertSessionHasErrors('file');
        }
        $this->upload(extra: ['document_type' => 'HACK'])->assertSessionHasErrors('document_type');
        $this->actingAs($this->staff)->post("/projects/{$this->project->id}/documents", ['document_type' => 'BAPP'])->assertSessionHasErrors('file');
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('entity_type', 'DOCUMENT')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_authorization(): void
    {
        $this->post("/projects/{$this->project->id}/documents", [])->assertRedirect('/login');
        $this->upload(user: User::factory()->role('STAFF_PIC', 'BM')->create())->assertNotFound(); // satu divisi, tidak ditugaskan
        $this->upload(user: User::factory()->role('STAFF_PIC', 'CP')->create())->assertNotFound();
        $this->upload(user: User::factory()->role('MANAGER_GM', 'BM')->create())->assertForbidden();
        $this->upload(user: User::factory()->role('DIREKSI', null)->create())->assertForbidden();
        $this->upload(user: User::factory()->role('SYSTEM_ADMIN', null)->create())->assertForbidden();
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_upload_blocked_on_closed_project(): void
    {
        DB::table('projects')->where('id', $this->project->id)->update(['status' => 'FINANCIALLY_CLOSED']);
        $this->upload()->assertForbidden();
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_signed_url_streams_original_file_to_authorized_users(): void
    {
        $doc = $this->uploaded();
        $url = $this->signed($doc->id);

        foreach ([$this->staff, User::factory()->role('MANAGER_GM', 'BM')->create(), User::factory()->role('DIREKSI', null)->create(), User::factory()->role('SYSTEM_ADMIN', null)->create()] as $user) {
            $response = $this->actingAs($user)->get($url)->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
            $this->assertStringContainsString('Laporan Asli.pdf', $response->headers->get('Content-Disposition'));
        }
    }

    public function test_unsigned_tampered_or_expired_url_is_forbidden(): void
    {
        $doc = $this->uploaded();
        $this->get("/documents/{$doc->id}/download")->assertForbidden();
        $this->get($this->signed($doc->id).'x')->assertForbidden();
        $this->get(str_replace("/documents/{$doc->id}/", '/documents/'.($doc->id + 1).'/', $this->signed($doc->id)))->assertForbidden();
        $expired = $this->signed($doc->id);
        $this->travel(6)->minutes();
        $this->get($expired)->assertForbidden();
    }

    public function test_download_is_404_outside_reach_or_when_file_missing(): void
    {
        $doc = $this->uploaded();
        $url = $this->signed($doc->id);
        $this->actingAs(User::factory()->role('STAFF_PIC', 'BM')->create())->get($url)->assertNotFound(); // tidak ditugaskan
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->get($url)->assertNotFound();
        $this->actingAs($this->staff)->get($this->signed($doc->id + 999))->assertNotFound();
        Storage::disk('local')->delete($doc->storage_key);
        $this->get($url)->assertNotFound();
    }

    public function test_project_page_lists_documents_with_signed_links_and_escapes_name(): void
    {
        $this->upload(UploadedFile::fake()->create('<i>x.pdf', 10, 'application/pdf'))->assertSessionHasNoErrors();
        $key = DB::table('documents')->value('storage_key');
        $page = $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk();
        $page->assertSee('&lt;i&gt;x.pdf', false)->assertDontSee('<i>x.pdf', false)->assertSee('Unggah dokumen');
        $this->assertMatchesRegularExpression('#/documents/\d+/open"#', $page->getContent()); // tautan tetap hidup; URL bertanda tangan baru diterbitkan saat klik
        $this->assertStringNotContainsString('signature=', $page->getContent());
        $this->assertStringNotContainsString($key, $page->getContent());

        $this->actingAs(User::factory()->role('MANAGER_GM', 'BM')->create())->get("/projects/{$this->project->id}")->assertOk()->assertDontSee('Unggah dokumen')->assertSee('Unduh');
    }

    public function test_no_delete_or_public_route_for_documents(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'document')) {
                $this->assertEmpty(array_intersect(['DELETE', 'PUT', 'PATCH'], $route->methods()), $route->uri());
            }
        }
        $doc = $this->uploaded();
        $this->actingAs($this->staff)->delete("/documents/{$doc->id}")->assertNotFound();
        $this->get('/storage/'.$doc->storage_key)->assertNotFound();
    }

    public function test_open_redirects_to_fresh_signed_download_even_after_page_is_stale(): void
    {
        $doc = $this->uploaded();
        $this->get("/documents/{$doc->id}/open")->assertRedirectContains("/documents/{$doc->id}/download?expires=");
        $this->travel(30)->minutes(); // halaman lama: tautan open masih menerbitkan URL baru yang valid
        $target = $this->get("/documents/{$doc->id}/open")->assertRedirect()->headers->get('Location');
        $this->get($target)->assertOk();
    }

    public function test_open_is_404_out_of_reach_and_login_for_guest(): void
    {
        $doc = $this->uploaded();
        auth()->logout();
        $this->get("/documents/{$doc->id}/open")->assertRedirect('/login');
        $this->actingAs(User::factory()->role('STAFF_PIC', 'BM')->create())->get("/documents/{$doc->id}/open")->assertNotFound();
        $this->actingAs(User::factory()->role('MANAGER_GM', 'CP')->create())->get("/documents/{$doc->id}/open")->assertNotFound();
        $this->actingAs($this->staff)->get('/documents/'.($doc->id + 999).'/open')->assertNotFound();
        $this->actingAs(User::factory()->role('DIREKSI', null)->create())->get("/documents/{$doc->id}/open")->assertRedirect();
    }

    public function test_expired_signature_shows_clear_message(): void
    {
        $doc = $this->uploaded();
        $expired = $this->signed($doc->id);
        $this->travel(6)->minutes();
        $this->get($expired)->assertForbidden()->assertSee('Tautan unduh kedaluwarsa')->assertDontSee('tidak memiliki izin');
    }

    public function test_failed_audit_rolls_back_row_and_removes_stored_file(): void
    {
        DB::beforeExecuting(function ($query) {
            if (str_contains($query, 'into `audit_logs`')) {
                throw new \RuntimeException('audit down');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->upload();
            $this->fail('Upload seharusnya gagal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit down', $e->getMessage());
        }
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_long_filename_keeps_extension_when_truncated(): void
    {
        $this->upload(UploadedFile::fake()->create(str_repeat('a', 300).'.pdf', 10, 'application/pdf'))->assertSessionHasNoErrors();
        $name = DB::table('documents')->value('original_filename');
        $this->assertSame(250, mb_strlen($name));
        $this->assertStringEndsWith('.pdf', $name);
    }

    /** UploadedFile::fake() melaporkan MIME yang kita tentukan; di sini berkas nyata, jadi deteksi isi file ikut terukur. */
    private function realFile(string $name, string $content): UploadedFile
    {
        file_put_contents($path = tempnam(sys_get_temp_dir(), 'doc'), $content);

        return new UploadedFile($path, $name, 'application/pdf', null, true); // klien selalu mengaku PDF
    }

    public function test_real_file_content_must_match_type_and_extension(): void
    {
        $pdf = '%PDF-1.4
1 0 obj<<>>endobj
trailer<<>>
%%EOF
';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        foreach (['ok.pdf' => $pdf, 'ok.PNG' => $png] as $name => $content) {
            $this->upload($this->realFile($name, $content))->assertSessionHasNoErrors();
        }
        $this->assertSame(2, DB::table('documents')->count());

        $bad = ['html.pdf' => '<html><script>alert(1)</script></html>', 'win.pdf' => 'MZ    ', 'shell.png' => '<?php system($_GET[0]);', 'empty.pdf' => '', 'pdf.png' => $pdf, 'png.pdf' => $png, 'noext' => $pdf, 'pdf.exe' => $pdf];
        foreach ($bad as $name => $content) {
            $this->upload($this->realFile($name, $content))->assertSessionHasErrors('file');
        }
        $this->assertSame(2, DB::table('documents')->count());
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertSame(2, DB::table('audit_logs')->where('entity_type', 'DOCUMENT')->count());
    }

    public function test_upload_allowed_until_project_is_closed_archived_or_cancelled(): void
    {
        foreach (['DRAFT' => 302, 'SUBMITTED' => 302, 'ACTIVE' => 302, 'COMPLETED' => 302, 'FINANCIALLY_CLOSED' => 403, 'ARCHIVED' => 403, 'CANCELLED' => 403] as $status => $code) {
            DB::table('projects')->where('id', $this->project->id)->update(['status' => $status]);
            $this->upload()->assertStatus($code);
            $this->actingAs($this->staff)->get("/projects/{$this->project->id}")->assertOk()->{$code === 302 ? 'assertSee' : 'assertDontSee'}('Unggah dokumen');
        }
        $this->assertSame(4, DB::table('documents')->count());
    }

    public function test_audit_row_holds_metadata_only(): void
    {
        $doc = $this->uploaded();
        $after = json_decode(DB::table('audit_logs')->where('entity_type', 'DOCUMENT')->value('data_after'), true);
        $this->assertSame(['checksum', 'document_type', 'entity_id', 'entity_type', 'file_size', 'mime_type', 'original_filename', 'project_id', 'status'], collect($after)->keys()->sort()->values()->all());
        $this->assertSame($doc->checksum, $after['checksum']);
    }
}
