<?php

namespace App\Livewire;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use App\Services\InviteService;
use App\Services\SheetAccess;
use App\Support\Mobile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Share" page: invite people by name + mobile, choose a role and a scope (one project or all).
 */
class Members extends Component
{
    public string $name = '';

    public string $jobTitle = '';

    public string $mobile = '';

    public string $role = 'editor';

    /** '' = all projects */
    public $projectId = '';

    #[Locked]
    public ?int $editingId = null;

    public string $editName = '';

    public string $editJobTitle = '';

    public string $editRole = '';

    public $editProjectId = '';

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

        return User::with('project')
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
        [$role, $projectId, $roleErrors] = $this->resolveRole($this->role, $this->projectId, null, 'projectId');
        if ($errors + $roleErrors !== []) {
            throw ValidationException::withMessages($errors + $roleErrors);
        }

        try {
            $user = User::create([
                'name' => $this->name,
                'job_title' => $this->jobTitle === '' ? null : $this->jobTitle,
                'mobile' => $this->mobile,
                'role' => $role,
                'project_id' => $projectId,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['projectId' => 'این پروژه تاییدکننده فعال دارد یا شماره تکراری است.']);
        }

        $this->inviteLink = $invites->createLink($user, auth()->user());
        $this->inviteFor = $user->name;
        $this->smsSent = $invites->sendLinkSms($user, $this->inviteLink);
        $this->reset(['name', 'jobTitle', 'mobile', 'projectId']);
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
        $user = User::findOrFail($userId);
        $this->editingId = $user->id;
        $this->editName = $user->name;
        $this->editJobTitle = (string) $user->job_title;
        $this->editRole = $user->role->value;
        $this->editProjectId = (string) ($user->project_id ?? '');
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->authorizeManage();
        $user = User::findOrFail((int) $this->editingId);

        if ($user->id === auth()->id() && $this->editRole !== Role::Manager->value) {
            throw ValidationException::withMessages(['editRole' => 'نقش خودتان را نمی‌توانید تغییر دهید.']);
        }

        [$role, $projectId, $errors] = $this->resolveRole($this->editRole, $this->editProjectId, $user->id, 'editProjectId');
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
            $user->update(['name' => $name, 'role' => $role, 'project_id' => $projectId, 'job_title' => $jobTitle === '' ? null : $jobTitle]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['editProjectId' => 'این پروژه تاییدکننده فعال دارد.']);
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
            $user->update(['is_active' => ! $user->is_active]);
        } catch (UniqueConstraintViolationException) {
            $this->addError('members', 'این پروژه تاییدکننده فعال دیگری دارد؛ اول او را غیرفعال کنید.');
        }
        unset($this->members);
    }

    public function dismissLink(): void
    {
        $this->reset(['inviteLink', 'inviteFor', 'smsSent']);
    }

    /** @return array{0: string, 1: int|null, 2: array<string, string>} */
    private function resolveRole(string $roleValue, $projectValue, ?int $ignoreUserId, string $projectKey): array
    {
        $role = Role::tryFrom($roleValue);
        $projectId = is_numeric($projectValue) ? (int) $projectValue : null;
        $errors = [];

        if (! $role) {
            return [$roleValue, null, ['role' => 'نقش معتبر نیست.']];
        }
        if ($role === Role::Manager) {
            $projectId = null;
        }
        if ($projectId !== null && ! Project::whereKey($projectId)->exists()) {
            $errors[$projectKey] = 'پروژه پیدا نشد.';
        }
        if ($role === Role::Editor && $projectId === null) {
            $errors[$projectKey] = 'ویرایشگر باید یک پروژه داشته باشد.';
        }
        if ($role === Role::Approver && $projectId !== null) {
            $existing = User::where('role', Role::Approver->value)->where('project_id', $projectId)->where('is_active', true)
                ->when($ignoreUserId, fn ($q) => $q->whereKeyNot($ignoreUserId))->first();
            if ($existing) {
                $errors[$projectKey] = "این پروژه تاییدکننده دارد: {$existing->name}. هر پروژه فقط یک تاییدکننده دارد.";
            }
        }

        return [$role->value, $projectId, $errors];
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
