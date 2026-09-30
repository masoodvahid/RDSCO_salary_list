@php use App\Support\Digits; @endphp

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
    <h1 class="text-2xl font-extrabold">پروژه‌ها</h1>
    <p class="mt-1 text-sm leading-6 text-zinc-600">فهرست کلی پروژه‌ها. در هر شیت ماهانه، از «پروژه‌های این ماه» مشخص می‌کنید کدام پروژه‌ها در آن ماه فعال‌اند. پروژه حذف نمی‌شود تا سابقه ماه‌های قبل حفظ شود؛ فقط غیرفعال می‌شود.</p>

    <form wire:submit="add" class="card mt-6 flex items-start gap-3 p-4">
        <div class="flex-1">
            <label for="project-name" class="sr-only">نام پروژه</label>
            <input id="project-name" wire:model="name" class="input" placeholder="نام پروژه جدید">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary h-10">افزودن</button>
    </form>

    <ul class="card mt-4 divide-y divide-zinc-100">
        @forelse ($this->projects as $project)
            <li wire:key="project-{{ $project->id }}" @class(['flex flex-wrap items-center gap-3 px-4 py-3', 'opacity-60' => ! $project->is_active])>
                @if ($editingId === $project->id)
                    <form wire:submit="saveEdit" class="flex flex-1 items-start gap-2">
                        <div class="flex-1">
                            <label for="edit-name" class="sr-only">نام پروژه</label>
                            <input id="edit-name" wire:model="editName" class="input h-9" autofocus>
                            @error('editName') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-primary">ذخیره</button>
                        <button type="button" wire:click="cancelEdit" class="btn btn-ghost">انصراف</button>
                    </form>
                @else
                    <div class="flex-1">
                        <div class="text-sm font-semibold">{{ $project->name }} @unless ($project->is_active) <span class="text-xs font-normal text-zinc-500">· غیرفعال</span> @endunless</div>
                        <div class="text-xs text-zinc-500">{{ Digits::toPersian($project->members_count) }} عضو · در {{ Digits::toPersian($project->months_count) }} ماه</div>
                    </div>
                    <button type="button" wire:click="startEdit({{ $project->id }})" class="btn btn-ghost btn-sm">تغییر نام</button>
                    <button type="button" wire:click="toggleActive({{ $project->id }})" class="btn btn-ghost btn-sm">{{ $project->is_active ? 'غیرفعال کردن' : 'فعال کردن' }}</button>
                @endif
            </li>
        @empty
            <li class="px-4 py-8 text-center text-sm text-zinc-500">هنوز پروژه‌ای تعریف نشده است.</li>
        @endforelse
    </ul>
</div>
