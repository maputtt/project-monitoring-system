<?php

use App\Enums\Role;
use App\Http\Controllers\ApprovalInboxController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BqItemController;
use App\Http\Controllers\BqWorkflowController;
use App\Http\Controllers\BudgetMutationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardListController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceWorkflowController;
use App\Http\Controllers\Master\BouwheerContactController;
use App\Http\Controllers\Master\PksController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProjectAdjustmentController;
use App\Http\Controllers\ProjectAssignmentController;
use App\Http\Controllers\ProjectClosingController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectViewController;
use App\Http\Controllers\ProjectWorkflowController;
use App\Http\Controllers\TaxRateController;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/forgot-password', [PasswordResetController::class, 'showRequest'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});

// Semua halaman aplikasi butuh login dan akun aktif.
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Dashboard (DSH-01): semua role, read-only. '/' hanya mengarahkan ke sana.
    Route::redirect('/', '/dashboard')->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    // Drill-down kartu dashboard (read-only, semua role; lingkup tetap Project::visibleTo). Invoice dan antrean persetujuan memakai /invoices dan /approvals.
    Route::get('/dashboard/projects/{group}', [DashboardListController::class, 'projects'])->where('group', 'activated|active|completed|prospects|open')->name('dashboard.projects');
    Route::get('/dashboard/overbudget', [DashboardListController::class, 'overbudget'])->name('dashboard.overbudget');
    Route::get('/dashboard/kendala', [DashboardListController::class, 'kendala'])->name('dashboard.kendala');
    Route::get('/dashboard/margin', [DashboardListController::class, 'margin'])->name('dashboard.margin');

    // Pengguna: hanya SYSTEM_ADMIN. Tidak ada route hapus.
    Route::middleware('role:SYSTEM_ADMIN')->prefix('master')->name('master.')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
    });

    // PKS dan PIC Bouwheer: milik STAFF_PIC dari divisi mana pun. Tidak ada route hapus.
    Route::middleware('role:STAFF_PIC')->prefix('master')->name('master.')->group(function () {
        Route::resource('pks', PksController::class)->only(['index', 'create', 'store', 'edit', 'update'])->parameters(['pks' => 'pks']);
        Route::resource('bouwheer-contacts', BouwheerContactController::class)->only(['index', 'create', 'store', 'edit', 'update'])->parameters(['bouwheer-contacts' => 'contact']);
    });

    // Project: daftar dan detail (PRJ-03), buat dan ubah DRAFT (PRJ-01), perintah lifecycle (PRJ-02). Hak ada di ProjectPolicy. Tidak ada route hapus.
    Route::get('/projects', [ProjectViewController::class, 'index'])->middleware('role:STAFF_PIC,MANAGER_GM,DIREKSI')->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->middleware('can:create,'.Project::class)->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('can:create,'.Project::class)->name('projects.store');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->middleware('can:update,project')->name('projects.edit');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->middleware('can:update,project')->name('projects.update');
    Route::get('/projects/{project}', [ProjectViewController::class, 'show'])->name('projects.show'); // semua role; project di luar jangkauan sudah 404 oleh route binding
    Route::get('/projects/{project}/print', [ProjectViewController::class, 'print'])->name('projects.print'); // versi cetak/PDF; visibilitas sama dengan show (route binding)
    Route::post('/projects/{project}/submit', [ProjectWorkflowController::class, 'submit'])->middleware('can:submit,project')->name('projects.submit');
    Route::post('/projects/{project}/activate', [ProjectWorkflowController::class, 'activate'])->middleware('can:activate,project')->name('projects.activate');
    Route::post('/projects/{project}/request-revision', [ProjectWorkflowController::class, 'requestRevision'])->middleware('can:requestRevision,project')->name('projects.request-revision');
    // Selesai dan financial closing (CLS-01): Manager/GM divisi (policy approve). Syarat closing diperiksa ulang di dalam transaksi.
    Route::post('/projects/{project}/complete', [ProjectWorkflowController::class, 'complete'])->middleware('can:approve,project')->name('projects.complete');
    Route::post('/projects/{project}/close', [ProjectClosingController::class, 'close'])->middleware('can:approve,project')->name('projects.close');

    // Item BQ (BQ-01): hanya STAFF_PIC yang ditugaskan. {bq} dicek terhadap project yang terlihat di controller. Tidak ada route hapus.
    Route::post('/projects/{project}/bq-items', [BqItemController::class, 'store'])->middleware('can:contribute,project')->name('projects.bq-items.store');
    Route::put('/bq-items/{bq}', [BqItemController::class, 'update'])->where('bq', '[0-9]{1,18}')->name('bq-items.update');
    // Workflow item BQ (BQ-02): submit oleh STAFF_PIC, keputusan oleh MANAGER_GM. Policy dan gate status project ada di controller.
    Route::post('/bq-items/{bq}/submit', [BqWorkflowController::class, 'submit'])->where('bq', '[0-9]{1,18}')->name('bq-items.submit');
    Route::post('/bq-items/{bq}/approve', [BqWorkflowController::class, 'approve'])->where('bq', '[0-9]{1,18}')->name('bq-items.approve');
    Route::post('/bq-items/{bq}/request-revision', [BqWorkflowController::class, 'requestRevision'])->where('bq', '[0-9]{1,18}')->name('bq-items.request-revision');
    Route::post('/bq-items/{bq}/archive', [BqWorkflowController::class, 'archive'])->where('bq', '[0-9]{1,18}')->name('bq-items.archive');
    // Realisasi biaya (BQ-03): STAFF_PIC yang ditugaskan, hanya item APPROVED. Policy dan gate status ada di controller.
    Route::put('/bq-items/{bq}/actual-cost', [BqItemController::class, 'actualCost'])->where('bq', '[0-9]{1,18}')->name('bq-items.actual-cost');

    // Mutasi budget BQ (BQ-04): ajukan dan ajukan ulang oleh STAFF_PIC, keputusan oleh MANAGER_GM (bukan pengaju). Policy dan gate status project ada di controller. Tidak ada route hapus.
    Route::post('/projects/{project}/budget-mutations', [BudgetMutationController::class, 'store'])->middleware('can:contribute,project')->name('projects.budget-mutations.store');
    Route::put('/budget-mutations/{mutation}', [BudgetMutationController::class, 'resubmit'])->where('mutation', '[0-9]{1,18}')->name('budget-mutations.resubmit');
    Route::post('/budget-mutations/{mutation}/approve', [BudgetMutationController::class, 'approve'])->where('mutation', '[0-9]{1,18}')->name('budget-mutations.approve');
    Route::post('/budget-mutations/{mutation}/request-revision', [BudgetMutationController::class, 'requestRevision'])->where('mutation', '[0-9]{1,18}')->name('budget-mutations.request-revision');
    Route::post('/budget-mutations/{mutation}/withdraw', [BudgetMutationController::class, 'withdraw'])->where('mutation', '[0-9]{1,18}')->name('budget-mutations.withdraw');

    // Adjustment project (PRJ-04), hanya TARGET_MARGIN: ajukan dan ajukan ulang oleh STAFF_PIC, keputusan oleh MANAGER_GM (bukan pengaju). Policy dan gate ACTIVE ada di controller. Tidak ada route hapus.
    Route::post('/projects/{project}/adjustments', [ProjectAdjustmentController::class, 'store'])->middleware('can:contribute,project')->name('projects.adjustments.store');
    Route::put('/adjustments/{adjustment}', [ProjectAdjustmentController::class, 'resubmit'])->where('adjustment', '[0-9]{1,18}')->name('adjustments.resubmit');
    Route::post('/adjustments/{adjustment}/approve', [ProjectAdjustmentController::class, 'approve'])->where('adjustment', '[0-9]{1,18}')->name('adjustments.approve');
    Route::post('/adjustments/{adjustment}/request-revision', [ProjectAdjustmentController::class, 'requestRevision'])->where('adjustment', '[0-9]{1,18}')->name('adjustments.request-revision');
    Route::post('/adjustments/{adjustment}/withdraw', [ProjectAdjustmentController::class, 'withdraw'])->where('adjustment', '[0-9]{1,18}')->name('adjustments.withdraw');

    // Dokumen privat (DOC-01): unggah oleh STAFF_PIC yang ditugaskan; unduh hanya lewat URL bertanda tangan 5 menit. Tidak ada route hapus.
    Route::post('/projects/{project}/documents', [DocumentController::class, 'store'])->middleware('can:contribute,project')->name('projects.documents.store');
    Route::get('/documents/{document}/open', [DocumentController::class, 'open'])->where('document', '[0-9]{1,18}')->name('documents.open');
    Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->where('document', '[0-9]{1,18}')->middleware('signed')->name('documents.download');

    // Invoice (INV-01): pratinjau pajak (JSON, tidak menyimpan apa pun) dan submit oleh STAFF_PIC yang ditugaskan. Policy dan gate status project ada di controller. Tidak ada route hapus.
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('role:STAFF_PIC,MANAGER_GM,DIREKSI')->name('invoices.index');
    Route::get('/invoices/{invoice}/tax-preview', [InvoiceController::class, 'taxPreview'])->where('invoice', '[0-9]{1,18}')->name('invoices.tax-preview');
    Route::post('/invoices/{invoice}/progress', [InvoiceController::class, 'recordProgress'])->where('invoice', '[0-9]{1,18}')->name('invoices.progress');
    Route::post('/invoices/{invoice}/submit', [InvoiceController::class, 'submit'])->where('invoice', '[0-9]{1,18}')->name('invoices.submit');
    Route::post('/invoices/{invoice}/planned-billing-date', [InvoiceController::class, 'changePlannedBillingDate'])->where('invoice', '[0-9]{1,18}')->name('invoices.planned-billing-date');
    // Verifikasi invoice (INV-02): Manager/GM divisi project, bukan pengaju. Pembayaran (INV-03). Unlock terkontrol dari PAID (INV-04).
    Route::post('/invoices/{invoice}/verify', [InvoiceWorkflowController::class, 'verify'])->where('invoice', '[0-9]{1,18}')->name('invoices.verify');
    Route::post('/invoices/{invoice}/request-revision', [InvoiceWorkflowController::class, 'requestRevision'])->where('invoice', '[0-9]{1,18}')->name('invoices.request-revision');
    Route::post('/invoices/{invoice}/submit-payment', [InvoiceController::class, 'submitPayment'])->where('invoice', '[0-9]{1,18}')->name('invoices.submit-payment');
    Route::post('/invoices/{invoice}/approve-payment', [InvoiceWorkflowController::class, 'approvePayment'])->where('invoice', '[0-9]{1,18}')->name('invoices.approve-payment');
    Route::post('/invoices/{invoice}/cancel', [InvoiceWorkflowController::class, 'cancel'])->where('invoice', '[0-9]{1,18}')->name('invoices.cancel');
    Route::post('/invoices/{invoice}/unlock', [InvoiceWorkflowController::class, 'unlock'])->where('invoice', '[0-9]{1,18}')->name('invoices.unlock');

    // Tim project (MST-03): hanya MANAGER_GM. {assignment} dicek terhadap project yang terlihat di controller.
    Route::post('/projects/{project}/assignments', [ProjectAssignmentController::class, 'store'])->middleware('can:manageTeam,project')->name('projects.assignments.store');
    Route::post('/assignments/{assignment}/deactivate', [ProjectAssignmentController::class, 'deactivate'])->where('assignment', '[0-9]{1,18}')->name('assignments.deactivate');

    // Approval Inbox (DSH-02): hanya MANAGER_GM, read-only; keputusan diambil di detail project.
    Route::get('/approvals', [ApprovalInboxController::class, 'index'])->middleware('role:MANAGER_GM')->name('approvals.index');

    // Audit log (AUD-01): read-only. MANAGER_GM hanya project divisinya; DIREKSI dan SYSTEM_ADMIN seluruh sistem.
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('role:MANAGER_GM,DIREKSI,SYSTEM_ADMIN')->name('audit-logs.index');

    // Tarif pajak: STAFF_PIC menambah versi baru, MANAGER_GM hanya melihat. Tidak ada route ubah atau hapus.
    Route::get('/tax-rates', [TaxRateController::class, 'index'])->middleware('role:STAFF_PIC,MANAGER_GM')->name('tax-rates.index');
    Route::middleware('role:STAFF_PIC')->group(function () {
        Route::get('/tax-rates/create', [TaxRateController::class, 'create'])->name('tax-rates.create');
        Route::post('/tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
    });
});

// Halaman contoh komponen (FND-01). Hanya untuk development, tidak ada di production.
if (app()->environment(['local', 'testing'])) {
    Route::get('/ui-kit', fn (Request $request) => view('ui-kit', [
        'role' => Role::tryFrom((string) $request->query('role')) ?? Role::STAFF_PIC,
    ]));
}
