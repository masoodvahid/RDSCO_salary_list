<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use App\Services\InviteService;
use App\Services\SheetAccess;
use App\Support\Mobile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Share" page: invite people by name + mobile, choose a role and a scope (every project, or one or more projects).
 */
class Members extends Component
{
    public string $name = '';

    public string $jobTitle = '';

    public string $mobile = '';

    public string $role = 'editor';

    /** 'some' = the projects in $projectIds, 'all' = every project */
    public string $scope = 'some';

    /** @var list<int|string> */
    public array $projectIds = [];

    #[Locked]
    public ?int $editingId = null;

    public string $editName = '';

    public string $editJobTitle = '';

    public string $editRole = '';

    public string $editScope = 'some';

    /** @var list<int|string> */
    public array $editProjectIds = [];

    public ?string $inviteLink = null;

    public ?string $inviteFor = null;

    public bool $smsSent = false;

    public string $search = '';

    public function mount(): void
    {
        $this->authorizeManage();
    }

    #[Computed]
    public function members()
    {
        $search = trim($this->search);

        return User::with('projects')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('job_title', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', '%'.Mobile::normalize($search).'%')))
            ->orderByDesc('is_active')
            ->orderByRaw("case role when 'manager' then 0 when 'approver' then 1 when 'editor' then 2 else 3 end")
            ->orderBy('name')
            ->get();
    }

    /** Titles already in use, offered as suggestions so the same job is spelled the same way. */
    #[Computed]
    public function jobTitles()
    {
        return User::whereNotNull('job_title')->where('job_title', '!=', '')
            ->distinct()->orderBy('job_title')->limit(100)->pluck('job_title');
    }

    #[Computed]
    public function projects()
    {
        return Project::where('is_active', true)->orderBy('name')->get();
    }

    /** Projects offered in the edit dialog: the active ones plus any inactive project the member still has. */
    #[Computed]
    public function editProjects()
    {
        $assigned = array_map('intval', $this->editProjectIds);

        return Project::where('is_active', true)->orWhereIn('id', $assigned)->orderByDesc('is_active')->orderBy('name')->get();
    }

    public function updatedRole(): void
    {
        if ($this->role === Role::Editor->value) {
            $this->scope = 'some';
        }
    }

    public function updatedEditRole(): void
    {
        if ($this->editRole === Role::Editor->value) {
            $this->editScope = 'some';
        }
    }

    public function invite(InviteService $invites): void
    {
        $this->authorizeManage();
        $this->mobile = Mobile::normalize($this->mobile);
        $this->name = trim($this->name);
        $this->jobTitle = $this->cleanText($this->jobTitle);

        $errors = [];
        if ($this->name === '' || mb_strlen($this->name) > 120) {
            $errors['name'] = 'نام دعوت‌شونده الزامی است.';
        }
        if (mb_strlen($this->jobTitle) > 120) {
            $errors['jobTitle'] = 'موقعیت شغلی حداکثر ۱۲۰ کاراکتر است.';
        }
        if (! Mobile::isValid($this->mobile)) {
            $errors['mobile'] = 'شماره موبایل معتبر نیست.';
        } elseif (User::where('mobile', $this->mobile)->exists()) {
            $errors['mobile'] = 'این شماره قبلاً در سامانه ثبت شده است.';
        }
        [$role, $projectIds, $roleErrors] = $this->resolveScope($this->role, $this->scope, $this->projectIds, null, 'projectIds');
        if ($errors + $roleErrors !== []) {
            throw ValidationException::withMessages($errors + $roleErrors);
        }

        try {
            $user = DB::transaction(function () use ($role, $projectIds) {
                $user = User::create([
                    'name' => $this->name,
                    'job_title' => $this->jobTitle === '' ? null : $this->jobTitle,
                    'mobile' => $this->mobile,
                    'role' => $role,
                    'is_active' => true,
                ]);
                $user->syncProjects($projectIds);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['projectIds' => 'یکی از این پروژه‌ها همین حالا تاییدکننده گرفت یا شماره تکراری است. دوباره امتحان کنید.']);
        }

        $this->inviteLink = $invites->createLink($user, auth()->user());
        $this->inviteFor = $user->name;
        $this->smsSent = $invites->sendLinkSms($user, $this->inviteLink);
        $this->reset(['name', 'jobTitle', 'mobile', 'projectIds']);
        unset($this->members, $this->jobTitles);
    }

    public function newLink(InviteService $invites, int $userId): void
    {
        $this->authorizeManage();
        $user = User::findOrFail($userId);
        $this->inviteLink = $invites->createLink($user, auth()->user());
        $this->inviteFor = $user->name;
        $this->smsSent = $invites->sendLinkSms($user, $this->inviteLink);
    }

    public function startEdit(int $userId): void
    {
        $this->authorizeManage();
        $user = User::with('projects')->findOrFail($userId);
        $this->editingId = $user->id;
        $this->editName = $user->name;
        $this->editJobTitle = (string) $user->job_title;
        $this->editRole = $user->role->value;
        $this->editProjectIds = array_map('strval', $user->projectIds());
        $this->editScope = $this->editProjectIds === [] && $user->role !== Role::Editor ? 'all' : 'some';
        unset($this->editProjects);
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->authorizeManage();
        $user = User::findOrFail((int) $this->editingId);

        if ($user->id === auth()->id() && $this->editRole !== Role::Manager->value) {
            throw ValidationException::withMessages(['editRole' => 'نقش خودتان را نمی‌توانید تغییر دهید.']);
        }

        [$role, $projectIds, $errors] = $this->resolveScope($this->editRole, $this->editScope, $this->editProjectIds, $user, 'editProjectIds');
        $name = $this->cleanText($this->editName);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['editName'] = 'نام الزامی است (حداکثر ۱۲۰ کاراکتر).';
        }
        $jobTitle = $this->cleanText($this->editJobTitle);
        if (mb_strlen($jobTitle) > 120) {
            $errors['editJobTitle'] = 'موقعیت شغلی حداکثر ۱۲۰ کاراکتر است.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            DB::transaction(function () use ($user, $role, $projectIds, $name, $jobTitle) {
                // Lock the member so a concurrent activate/deactivate cannot leave approver slots out of step.
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                // Projects first, with the new role, so approver slots are claimed for the final set.
                $locked->syncProjects($projectIds, Role::from($role), $locked->is_active);
                $locked->update(['name' => $name, 'role' => $role, 'job_title' => $jobTitle === '' ? null : $jobTitle]);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['editProjectIds' => 'یکی از این پروژه‌ها همین حالا تاییدکننده گرفت. دوباره امتحان کنید.']);
        }

        $this->editingId = null;
        unset($this->members, $this->jobTitles);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function toggleActive(int $userId): void
    {
        $this->authorizeManage();
        $user = User::findOrFail($userId);
        if ($user->id === auth()->id()) {
            return;
        }

        try {
            DB::transaction(function () use ($user) {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $locked->update(['is_active' => ! $locked->is_active]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->addError('members', "یکی از پروژه‌های {$user->name} تاییدکننده‌ی فعال دیگری دارد؛ اول او را غیرفعال کنید یا آن پروژه را از یکی‌شان بگیرید.");
        }
        unset($this->members);
    }

    public function dismissLink(): void
    {
        $this->reset(['inviteLink', 'inviteFor', 'smsSent']);
    }

    /**
     * Validate role + scope. Returns the role value, the project ids ([] = every project) and errors.
     *
     * @param  list<int|string>  $projectValues
     * @return array{0: string, 1: list<int>, 2: array<string, string>}
     */
    private function resolveScope(string $roleValue, string $scope, array $projectValues, ?User $user, string $projectKey): array
    {
        $role = Role::tryFrom($roleValue);
        if (! $role) {
            return [$roleValue, [], [$projectKey === 'projectIds' ? 'role' : 'editRole' => 'نقش معتبر نیست.']];
        }
        if ($role === Role::Manager) {
            return [$role->value, [], []];
        }

        $wanted = array_values(array_unique(array_map('intval', array_filter($projectValues, 'is_numeric'))));
        $ids = $scope === 'all' ? [] : Project::whereIn('id', $wanted)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $errors = [];

        if ($scope !== 'all' && $ids === []) {
            $errors[$projectKey] = 'حداقل یک پروژه انتخاب کنید.';
        } elseif ($role === Role::Editor && $ids === []) {
            $errors[$projectKey] = 'ویرایشگر باید حداقل یک پروژه داشته باشد.';
        } elseif (count($ids) !== count($wanted) && $scope !== 'all') {
            $errors[$projectKey] = 'یکی از پروژه‌های انتخاب‌شده پیدا نشد.';
        }

        // One active approver per project (also enforced by the database).
        if ($errors === [] && $role === Role::Approver && $ids !== [] && ($user === null || $user->is_active)) {
            $taken = DB::table('project_user')
                ->join('users', 'users.id', '=', 'project_user.user_id')
                ->join('projects', 'projects.id', '=', 'project_user.project_id')
                ->whereIn('project_user.project_id', $ids)
                ->where('users.role', Role::Approver->value)
                ->where('users.is_active', true)
                ->when($user, fn ($q) => $q->where('users.id', '!=', $user->id))
                ->orderBy('projects.name')
                ->get(['projects.name as project', 'users.name as approver']);
            if ($taken->isNotEmpty()) {
                $list = $taken->map(fn ($t) => "پروژه {$t->project} ({$t->approver})")->join('، ');
                $errors[$projectKey] = "هر پروژه فقط یک تاییدکننده دارد و این پروژه‌ها تاییدکننده دارند: {$list}.";
            }
        }

        return [$role->value, $ids, $errors];
    }

    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function authorizeManage(): void
    {
        abort_unless(app(SheetAccess::class)->canManage(auth()->user()), 403);
    }

    public function render()
    {
        return view('livewire.members', ['roles' => Role::cases()])->title('اعضا و دسترسی');
    }
}
