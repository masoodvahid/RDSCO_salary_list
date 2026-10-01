{{--
    Role scope picker: every project, or one or more projects.
    Expects: $prefix (id prefix), $scopeModel, $idsModel (Livewire property names), $role, $scope, $projects.
--}}
@if ($role === 'manager')
    <p class="text-[13px] text-ink-soft">مدیر به همه پروژه‌ها دسترسی دارد.</p>
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
            <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="پروژه‌ها">
                @forelse ($projects as $project)
                    <label wire:key="{{ $prefix }}-p-{{ $project->id }}"
                           class="group inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-full border border-line-strong bg-white px-3 text-[13px] text-ink transition-colors hover:border-accent/40 has-checked:border-accent has-checked:bg-accent-soft has-checked:font-semibold has-checked:text-accent has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-accent">
                        <input type="checkbox" wire:model="{{ $idsModel }}" value="{{ $project->id }}" class="sr-only">
                        <svg class="hidden size-3.5 group-has-checked:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                        {{ $project->name }}
                        @unless ($project->is_active) <span class="text-xs font-normal text-ink-soft">(غیرفعال)</span> @endunless
                    </label>
                @empty
                    <p class="text-[13px] text-ink-soft">هنوز پروژه‌ای تعریف نشده است.</p>
                @endforelse
            </div>
        @endif
        @error($idsModel) <p class="error">{{ $message }}</p> @enderror
    </fieldset>
@endif
