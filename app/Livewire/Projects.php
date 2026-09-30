<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\SheetProject;
use App\Models\User;
use App\Services\SheetAccess;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Master list of projects. Each monthly sheet picks which of these are active that month.
 * Projects are deactivated, never deleted, so past sheets keep their history.
 */
class Projects extends Component
{
    public string $name = '';

    #[Locked]
    public ?int $editingId = null;

    public string $editName = '';

    public function mount(): void
    {
        $this->authorizeManage();
    }

    #[Computed]
    public function projects()
    {
        return Project::orderByDesc('is_active')->orderBy('name')->get()->map(function (Project $project) {
            $project->setAttribute('members_count', User::where('project_id', $project->id)->where('is_active', true)->count());
            $project->setAttribute('months_count', SheetProject::where('project_id', $project->id)->count());

            return $project;
        });
    }

    public function add(): void
    {
        $this->authorizeManage();
        $name = $this->validName($this->name, 'name');
        Project::create(['name' => $name, 'is_active' => true]);
        $this->name = '';
        unset($this->projects);
    }

    public function startEdit(int $projectId): void
    {
        $this->authorizeManage();
        $project = Project::findOrFail($projectId);
        $this->editingId = $project->id;
        $this->editName = $project->name;
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->authorizeManage();
        $project = Project::findOrFail((int) $this->editingId);
        $project->update(['name' => $this->validName($this->editName, 'editName', $project->id)]);
        $this->editingId = null;
        unset($this->projects);
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function toggleActive(int $projectId): void
    {
        $this->authorizeManage();
        $project = Project::findOrFail($projectId);
        $project->update(['is_active' => ! $project->is_active]);
        unset($this->projects);
    }

    private function validName(string $name, string $key, ?int $ignoreId = null): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages([$key => 'نام پروژه الزامی است.']);
        }
        if (Project::where('name', $name)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            throw ValidationException::withMessages([$key => 'پروژه‌ای با این نام وجود دارد.']);
        }

        return $name;
    }

    private function authorizeManage(): void
    {
        abort_unless(app(SheetAccess::class)->canManage(auth()->user()), 403);
    }

    public function render()
    {
        return view('livewire.projects')->title('پروژه‌ها');
    }
}
