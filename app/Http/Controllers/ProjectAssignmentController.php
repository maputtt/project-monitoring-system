<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Tim project (MST-03): MANAGER_GM menugaskan dan menonaktifkan Staff/PIC. Hak ada di ProjectPolicy::manageTeam; tidak ada hapus. */
class ProjectAssignmentController extends Controller
{
    private const TABLE = 'project_user_assignments';

    private const AUDITED = ['project_id', 'user_id', 'assignment_role', 'is_active'];

    public function store(Request $request, Project $project): RedirectResponse
    {
        $manager = $request->user();
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'STAFF_PIC')->where('department', $project->department->value)->where('is_active', true)],
        ], [
            'user_id.required' => 'Pilih Staff/PIC yang akan ditugaskan.',
            'user_id.integer' => 'Pengguna tidak valid.',
            'user_id.exists' => 'Pengguna harus Staff/PIC aktif dari divisi project ini.',
        ]);

        DB::transaction(function () use ($project, $manager, $data) {
            $this->lockProject($project);

            // Peran tim hanya PIC; input assignment_role dari klien diabaikan.
            if (DB::table(self::TABLE)->where('project_id', $project->id)->where('user_id', $data['user_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Staff/PIC ini sudah ditugaskan aktif pada project ini.']);
            }

            $row = DB::table(self::TABLE)->where('project_id', $project->id)->where('user_id', $data['user_id'])->where('assignment_role', 'PIC')->lockForUpdate()->first();
            if ($row) { // baris nonaktif diaktifkan kembali, bukan dibuat baru (unique project, user, peran)
                $before = (array) $row;
                DB::table(self::TABLE)->where('id', $row->id)->update(['is_active' => true, 'assigned_at' => now(), 'assigned_by' => $manager->id]);
                $this->audit(AuditAction::UPDATE, $row->id, $before);
            } else {
                $id = DB::table(self::TABLE)->insertGetId($data + ['assignment_role' => 'PIC', 'project_id' => $project->id, 'assigned_by' => $manager->id, 'is_active' => true]);
                $this->audit(AuditAction::CREATE, $id, null);
            }
        });

        return redirect()->route('projects.show', $project)->with('status', 'Staff/PIC ditugaskan ke project.');
    }

    public function deactivate(Request $request, int $assignment): RedirectResponse
    {
        // Assignment milik project di luar jangkauan manajer menjawab 404, sama seperti route {project}.
        $project = Project::query()->visibleTo($request->user())->findOrFail(DB::table(self::TABLE)->where('id', $assignment)->value('project_id'));
        Gate::authorize('manageTeam', $project);

        DB::transaction(function () use ($project, $assignment) {
            $this->lockProject($project); // serialisasi: dua penonaktifan bersamaan tidak bisa sama-sama lolos cek "terakhir"
            $row = DB::table(self::TABLE)->where('id', $assignment)->lockForUpdate()->first();

            if (! $row->is_active) {
                throw ValidationException::withMessages(['assignment' => 'Assignment ini sudah nonaktif.']);
            }
            if (! $project->hasOtherUsablePic($row->user_id)) {
                throw ValidationException::withMessages(['assignment' => 'Project harus memiliki minimal satu Staff/PIC aktif. Tugaskan penggantinya lebih dulu.']);
            }

            DB::table(self::TABLE)->where('id', $assignment)->update(['is_active' => false]);
            $this->audit(AuditAction::UPDATE, $assignment, (array) $row);
        });

        return redirect()->route('projects.show', $project)->with('status', 'Assignment dinonaktifkan.');
    }

    /** Kunci baris project; status dicek ulang di bawah kunci karena policy berjalan sebelum kunci. */
    private function lockProject(Project $project): void
    {
        $status = Project::query()->lockForUpdate()->findOrFail($project->id)->status;
        abort_if($status === ProjectStatus::FINANCIALLY_CLOSED, 409, 'Tim project tidak bisa diubah setelah financial closing.');
    }

    private function audit(AuditAction $action, int $id, ?array $before): void
    {
        $after = (array) DB::table(self::TABLE)->where('id', $id)->first();
        Audit::record($action, 'PROJECT_ASSIGNMENT', $id, $before === null ? null : array_intersect_key($before, array_flip(self::AUDITED)), array_intersect_key($after, array_flip(self::AUDITED)));
    }
}
