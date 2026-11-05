<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;

/** Hak dasar atas project. Aturan khusus per perintah (misalnya no self-approval) ada di Action masing-masing. */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::STAFF_PIC;
    }

    /** Mengubah isi project: hanya Staff/PIC yang ditugaskan, dan hanya selama DRAFT. */
    public function update(User $user, Project $project): bool
    {
        return $user->role === Role::STAFF_PIC
            && $project->status === ProjectStatus::DRAFT
            && $project->isVisibleTo($user);
    }

    /** Mengajukan data di dalam project (BQ, invoice, progress, mutasi): Staff/PIC yang ditugaskan. */
    public function contribute(User $user, Project $project): bool
    {
        return $user->role === Role::STAFF_PIC && $project->isVisibleTo($user);
    }

    /** Menyetujui, meminta revisi, unlock, dan closing: Manager/GM divisi project. */
    public function approve(User $user, Project $project): bool
    {
        return $user->role === Role::MANAGER_GM && $project->isVisibleTo($user);
    }

    /** Mengajukan project DRAFT untuk aktivasi. Statusnya diperiksa oleh peta transisi (409), bukan di sini. */
    public function submit(User $user, Project $project): bool
    {
        return $this->contribute($user, $project);
    }

    /** Mengaktifkan project: Manager/GM divisi project, dan bukan orang yang mengajukannya (no self-approval). */
    public function activate(User $user, Project $project): bool
    {
        return $this->approve($user, $project) && ! $project->hasBeenSubmittedBy($user);
    }

    public function requestRevision(User $user, Project $project): bool
    {
        return $this->approve($user, $project);
    }

    /** Tim project: hanya Manager/GM divisi project (AC-28), kecuali setelah financial closing. */
    public function manageTeam(User $user, Project $project): bool
    {
        return $this->approve($user, $project) && $project->status !== ProjectStatus::FINANCIALLY_CLOSED;
    }
}
