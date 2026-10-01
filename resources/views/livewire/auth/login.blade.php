<div class="w-full max-w-sm">
    <div class="mb-8 flex items-center gap-2.5">
        <span class="flex size-8 items-center justify-center rounded-lg bg-zinc-900 font-extrabold text-white">ت</span>
        <span class="text-base font-bold">توکا <span class="font-normal text-zinc-500">· لیست حقوق</span></span>
    </div>

    <div class="card p-6">
        @if ($inviteName)
            <h1 class="text-xl font-extrabold">{{ $inviteName }}، خوش آمدید</h1>
            <p class="mt-1.5 text-sm leading-6 text-zinc-600">برای ورود به شیت حقوق، کد پیامکی را تایید کنید. ثبت‌نام و رمز عبور لازم نیست.</p>
        @else
            <h1 class="text-xl font-extrabold">ورود به سامانه</h1>
            <p class="mt-1.5 text-sm leading-6 text-zinc-600">با شماره موبایلی که مدیر سامانه برایتان ثبت کرده وارد شوید.</p>
        @endif

        @if (! $codeSent)
            <form wire:submit="sendCode" class="mt-6 space-y-4">
                <div>
                    <label for="mobile" class="label">شماره موبایل</label>
                    <input id="mobile" type="tel" inputmode="numeric" autocomplete="tel" dir="ltr"
                           wire:model="mobile" @readonly($inviteName !== null)
                           class="input text-left num" placeholder="09121234567" autofocus>
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
                        <button type="button" wire:click="changeNumber" class="text-zinc-600 underline underline-offset-4">تغییر شماره</button>
                    @else
                        <span></span>
                    @endif
                    <button type="button" wire:click="sendCode" class="text-zinc-600 underline underline-offset-4">ارسال دوباره کد</button>
                </div>
            </form>
        @endif
    </div>

    <p class="mt-4 text-center text-xs leading-5 text-zinc-500">دسترسی هر نفر فقط با شماره موبایل خودش کار می‌کند.</p>
</div>
