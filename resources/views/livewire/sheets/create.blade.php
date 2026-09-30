<div class="mx-auto max-w-xl px-4 py-8 sm:px-6">
    <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-zinc-600 hover:text-zinc-900">→ بازگشت</a>
    <h1 class="mt-3 text-2xl font-extrabold">شیت ماه جدید</h1>
    <p class="mt-1 text-sm leading-6 text-zinc-600">هر ماه یک شیت دارد که همه پرسنل در آن هستند و پروژه هر نفر در ستون «پروژه» مشخص می‌شود.</p>

    <form wire:submit="create" class="card mt-6 space-y-5 p-6">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="month" class="label">ماه</label>
                <select id="month" wire:model.live="month" class="input">
                    @foreach ($months as $number => $name)
                        <option value="{{ $number }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="year" class="label">سال</label>
                <input id="year" type="number" wire:model.live.blur="year" class="input num text-left" min="1400" max="1499">
            </div>
        </div>
        @error('month') <p class="error">{{ $message }}</p> @enderror

        <div>
            <label for="copy" class="label">ساختار از ماه قبل</label>
            <select id="copy" wire:model="copyFromId" class="input">
                <option value="">بدون کپی (ستون‌های پیش‌فرض و همه پروژه‌های فعال)</option>
                @foreach ($sheets as $s)
                    <option value="{{ $s->id }}">کپی از {{ $s->title() }}</option>
                @endforeach
            </select>
            <p class="mt-1.5 text-xs leading-5 text-zinc-500">پروژه‌ها، ستون‌ها و پرسنل کپی می‌شوند. مقادیر ستون‌های قفل (مثل حقوق پایه) همیشه کپی می‌شوند.</p>
        </div>

        <label class="flex items-start gap-2.5 text-sm">
            <input type="checkbox" wire:model="copyAllValues" class="mt-1 size-4 rounded border-zinc-300">
            <span>مقادیر ستون‌های باز را هم کپی کن <span class="block text-xs text-zinc-500">برای ماه‌هایی که بیشتر اقلام تغییری نمی‌کنند.</span></span>
        </label>

        <div>
            <label for="deadline" class="label">مهلت تکمیل پروژه‌ها</label>
            <input id="deadline" type="text" wire:model="deadline" class="input num w-48 text-left" placeholder="1405/07/14" dir="ltr">
            @error('deadline') <p class="error">{{ $message }}</p> @enderror
            <p class="mt-1.5 text-xs text-zinc-500">پس از پایان این روز، ویرایشگرها و تاییدکننده‌های پروژه دیگر نمی‌توانند مقادیر را تغییر دهند.</p>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">ساخت شیت</button>
        </div>
    </form>
</div>
