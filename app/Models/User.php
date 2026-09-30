<?php

namespace App\Models;

use App\Enums\Role;
use App\Support\Mobile;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'mobile', 'role', 'project_id', 'is_active', 'last_login_at'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function isManager(): bool
    {
        return $this->role === Role::Manager;
    }

    /** Approver without a project scope acts at the finance stage. */
    public function isGlobalApprover(): bool
    {
        return $this->role === Role::Approver && $this->project_id === null;
    }

    public function hasAllProjects(): bool
    {
        return $this->isManager() || $this->project_id === null;
    }

    public function scopeLabel(): string
    {
        return $this->hasAllProjects() ? 'همه پروژه‌ها' : ($this->project?->name ?? '—');
    }

    public function maskedMobile(): string
    {
        return Mobile::mask($this->mobile);
    }
}
