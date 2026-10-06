<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\Digits;
use App\Support\Mobile;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** users.project_id is legacy (see the project_user migration); projects live in the pivot. */
    protected $fillable = ['name', 'job_title', 'mobile', 'role', 'is_active', 'last_login_at'];

    protected $hidden = ['remember_token'];

    /** Mirrors the column defaults so freshly created models match the database. */
    protected $attributes = ['role' => 'viewer', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Becoming (or ceasing to be) an active approver claims (or frees) the approver slot of each project.
        static::saved(function (User $user) {
            if ($user->wasChanged(['role', 'is_active'])) {
                $user->refreshApproverKeys();
            }
        });
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withPivot('approver_key')->withTimestamps();
    }

    /** @var list<int>|null memo: the grid asks for every cell */
    private ?array $projectIdCache = null;

    /** @return list<int> */
    public function projectIds(): array
    {
        return $this->projectIdCache ??= $this->projects->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /**
     * Replace the member's projects. Pass the role / active state the user is about to have when
     * they change in the same save, so the approver slots are claimed for the right projects.
     * Throws UniqueConstraintViolationException when another active approver holds one of them.
     *
     * @param  array<int|string>  $projectIds
     */
    public function syncProjects(array $projectIds, ?Role $role = null, ?bool $active = null): void
    {
        $approver = ($role ?? $this->role) === Role::Approver && ($active ?? $this->is_active);
        $ids = array_values(array_unique(array_map('intval', $projectIds)));

        // sync() detaches first, so slots this user gives up are free before new ones are claimed.
        $this->projects()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['approver_key' => $approver ? $id : null]])->all());
        $this->unsetRelation('projects');
        $this->projectIdCache = null;
    }

    public function refreshApproverKeys(): void
    {
        $approver = $this->role === Role::Approver && $this->is_active;
        DB::table('project_user')->where('user_id', $this->id)->update([
            'approver_key' => $approver ? DB::raw('project_id') : null,
            'updated_at' => now(),
        ]);
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager;
    }

    /** Approver without a project scope: approves as the CEO, after HR. */
    public function isGlobalApprover(): bool
    {
        return $this->role === Role::Approver && $this->projectIds() === [];
    }

    /** The finance manager gives the final approval, after the CEO. */
    public function isFinance(): bool
    {
        return $this->role === Role::Finance;
    }

    /**
     * Managers and finance, and approvers / viewers with no project, see every project. An editor always
     * works within projects.
     */
    public function hasAllProjects(): bool
    {
        return $this->isManager() || $this->isFinance() || ($this->role !== Role::Editor && $this->projectIds() === []);
    }

    public function scopeLabel(): string
    {
        if ($this->hasAllProjects()) {
            return 'همه پروژه‌ها';
        }
        $names = $this->projects->sortBy('name')->pluck('name');
        if ($names->isEmpty()) {
            return 'بدون پروژه';
        }
        if ($names->count() <= 3) {
            return $names->join('، ');
        }

        return $names->take(2)->join('، ').' و '.Digits::toPersian($names->count() - 2).' پروژه‌ی دیگر';
    }

    /** "Name (job title)" for signatures and notes; just the name when no title is set. */
    public function nameWithTitle(): string
    {
        return filled($this->job_title) ? "{$this->name} ({$this->job_title})" : $this->name;
    }

    public function maskedMobile(): string
    {
        return Mobile::mask($this->mobile);
    }
}
