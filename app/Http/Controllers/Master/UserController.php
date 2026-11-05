<?php

namespace App\Http\Controllers\Master;

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\UserRequest;
use App\Models\Project;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Data master pengguna (PRD FD-17). Akun tidak pernah dihapus, hanya dinonaktifkan. */
class UserController extends Controller
{
    private const AUDITED = ['name', 'email', 'role', 'department', 'phone_number', 'position', 'is_active'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $users = User::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('master.users.index', ['users' => $users, 'search' => $search]);
    }

    public function create(): View
    {
        return view('master.users.form', ['user' => new User]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('password') + ['password_hash' => $request->validated('password')];

        DB::transaction(function () use ($data) {
            $user = User::create($data);
            Audit::record(AuditAction::CREATE, 'USER', $user->id, after: $user->fresh()->only(self::AUDITED));
        });

        return redirect()->route('master.users.index')->with('status', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        return view('master.users.form', ['user' => $user]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        // Admin tidak boleh mengubah role-nya sendiri, supaya selalu ada yang bisa mengelola data master.
        if ($user->is($request->user()) && $request->validated('role') !== $user->role->value) {
            throw ValidationException::withMessages(['role' => 'Anda tidak bisa mengubah role akun sendiri.']);
        }

        DB::transaction(function () use ($request, $user) {
            $changesRole = $request->validated('role') !== $user->role->value;
            if ($changesRole || $request->validated('department') !== $user->department?->value) {
                $this->assertNotSoleStaffPic($user, $changesRole ? 'role' : 'department');
            }

            $before = $user->only(self::AUDITED);
            $user->update($request->validated());
            Audit::record(AuditAction::UPDATE, 'USER', $user->id, $before, $user->fresh()->only(self::AUDITED));
        });

        return redirect()->route('master.users.index')->with('status', 'Data pengguna diperbarui.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Anda tidak bisa menonaktifkan akun sendiri.']);
        }

        return $this->setActive($user, false, 'Pengguna dinonaktifkan. Ia langsung dikeluarkan dari aplikasi.');
    }

    public function activate(User $user): RedirectResponse
    {
        return $this->setActive($user, true, 'Pengguna diaktifkan kembali.');
    }

    private function setActive(User $user, bool $active, string $message): RedirectResponse
    {
        DB::transaction(function () use ($user, $active) {
            $active || $this->assertNotSoleStaffPic($user, 'user');

            $before = $user->only(self::AUDITED);
            $user->update(['is_active' => $active]);
            Audit::record(AuditAction::UPDATE, 'USER', $user->id, $before, $user->only(self::AUDITED));
        });

        return back()->with('status', $message);
    }

    /**
     * Tolak perubahan yang membuat project berjalan tanpa Staff/PIC yang berguna.
     * Baris project dikunci dulu, lalu dihitung ulang, supaya tidak berbenturan dengan penonaktifan assignment (MST-03).
     */
    private function assertNotSoleStaffPic(User $user, string $field): void
    {
        if (! $user->is_active || $user->role !== Role::STAFF_PIC) {
            return; // sudah tidak dihitung sebagai PIC, jadi perubahan ini tidak mengurangi apa pun
        }

        // Daftar id boleh basi; yang menentukan adalah locking read berikutnya (tanpa subquery, supaya membaca baris terbaru, bukan snapshot).
        $ids = DB::table('project_user_assignments')->where('user_id', $user->id)->where('is_active', true)->pluck('project_id');
        $codes = Project::query()->open()->where('department', $user->department)->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Project $project) => ! $project->hasOtherUsablePic($user->id))
            ->pluck('project_code');

        if ($codes->isNotEmpty()) {
            throw ValidationException::withMessages([$field => 'Pengguna ini satu-satunya Staff/PIC aktif di project '.$codes->implode(', ').'. Minta Manager/GM menugaskan pengganti lebih dulu.']);
        }
    }
}
