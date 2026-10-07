{{--
    Role scope picker: every project, or one or more projects.
    Expects: $prefix (id prefix), $scopeModel, $idsModel (Livewire property names), $role, $scope, $projects.
--}}
@if ($role === 'manager' || $role === 'finance')
    <p class="text-[13px] text-ink-soft">{{ $role === 'finance' ? 'مدیر مالی' : 'مدیر' }} به همه پروژه‌ها دسترسی دارد.</p>
@else
    <fieldset>
        <legend class="label">محدوده دسترسی</legend>
        <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
            <label class="inline-flex cursor-pointer items-center gap-2">
                <input type="radio" wire:model.live="{{ $scopeModel }}" value="some" class="size-4 accent-accent">
                پروژه‌های مشخص
            </label>
            <label @class(['inline-flex items-center gap-2', 'cursor-pointer' => $role !== 'editor', 'cursor-not-allowed opacity-50' => $role === 'editor'])>
                <input type="radio" wire:model.live="{{ $scopeModel }}" value="all" class="size-4 accent-accent" @disabled($role === 'editor')>
                همه پروژه‌ها
                @if ($role === 'editor') <span class="text-xs text-ink-soft">(برای ویرایشگر مجاز نیست)</span> @endif
            </label>
        </div>

        @if ($scope !== 'all')
            <x-project-picker class="mt-3" :id="$prefix.'-projects'" multiple :model="$idsModel" placeholder="پروژه‌ها را انتخاب کنید"
                :options="$projects->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->name, 'hint' => $p->is_active ? null : 'غیرفعال'])->values()->all()" />
        @endif
        @error($idsModel) <p class="error">{{ $message }}</p> @enderror
    </fieldset>
@endif
