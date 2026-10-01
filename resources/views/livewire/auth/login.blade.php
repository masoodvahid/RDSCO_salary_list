@php
    use App\Enums\Stage;
@endphp

<div class="w-full max-w-[400px]">
    <x-brand class="mb-7 justify-center" size="text-lg" />

    <div class="card overflow-hidden shadow-xl shadow-ink/5">
        <div class="flex h-1" aria-hidden="true">
            @foreach (Stage::cases() as $stage)
                <span class="flex-1 {{ $stage->dotClass() }}"></span>
            @endforeach
        </div>

        <div class="p-6 sm:p-7">
            @if ($inviteName)
                <h1 class="text-xl font-extrabold">{{ $inviteName }}، خوش آمدید</h1>
                <p class="mt-1.5 text-sm leading-6 text-ink-soft">برای ورود به شیت حقوق، کد پیامکی را تایید کنید. ثبت‌نام و رمز عبور لازم نیست.</p>
            @else
                <h1 class="text-xl font-extrabold">ورود به سامانه</h1>
                <p class="mt-1.5 text-sm leading-6 text-ink-soft">با شماره موبایلی که مدیر سامانه برایتان ثبت کرده وارد شوید.</p>
            @endif

            @if (! $codeSent)
                <form wire:submit="sendCode" class="mt-6 space-y-4">
                    <div>
                        <label for="mobile" class="label">شماره موبایل</label>
                        <input id="mobile" type="tel" inputmode="numeric" autocomplete="tel" dir="ltr"
                               wire:model="mobile" @readonly($inviteName !== null)
                               class="input h-11 text-left num" placeholder="09121234567" autofocus>
                        @error('mobile') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary h-11 w-full" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="sendCode">دریافت کد پیامکی</span>
                        <span wire:loading wire:target="sendCode">در حال ارسال…</span>
                    </button>
                </form>
            @else
                <form wire:submit="verify" class="mt-6 space-y-4">
                    <div>
                        <label for="code" class="label">کد ارسال‌شده به <span dir="ltr" class="num">{{ \App\Support\Mobile::mask($mobile) }}</span></label>
                        <input id="code" type="text" inputmode="numeric" autocomplete="one-time-code" dir="ltr" maxlength="10"
                               wire:model="code" class="input h-12 text-center text-xl font-bold tracking-[0.4em] num" autofocus>
                        @error('code') <p class="error">{{ $message }}</p> @enderror
                        @error('mobile') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary h-11 w-full" wire:loading.attr="disabled">ورود</button>
                    <div class="flex items-center justify-between text-[13px]">
                        @if (! $inviteName)
                            <button type="button" wire:click="changeNumber" class="font-medium text-accent hover:underline hover:underline-offset-4">تغییر شماره</button>
                        @else
                            <span></span>
                        @endif
                        <button type="button" wire:click="sendCode" class="font-medium text-accent hover:underline hover:underline-offset-4">ارسال دوباره کد</button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    <p class="mt-4 text-center text-xs leading-5 text-ink-soft">دسترسی هر نفر فقط با شماره موبایل خودش کار می‌کند.</p>
</div>
