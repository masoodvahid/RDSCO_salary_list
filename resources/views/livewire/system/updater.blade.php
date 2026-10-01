<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6" x-data="updater(@js($config))">
    <h1 class="page-title">به‌روزرسانی سامانه</h1>
    <p class="page-lead">نسخه‌های جدید از GitHub دریافت و نصب می‌شوند. پیش از نصب از دیتابیس بکاپ گرفته می‌شود و اگر مرحله‌ای خطا بدهد، می‌توانید سامانه را به نسخه‌ی قبل برگردانید.</p>

    {{-- Current version --}}
    <section class="card mt-6 flex flex-wrap items-center justify-between gap-4 p-5" aria-label="نسخه‌ی نصب‌شده">
        <div class="flex items-center gap-3">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
            <div>
                <div class="text-sm text-ink-soft">نسخه‌ی نصب‌شده</div>
                <div class="text-xl font-extrabold num" x-text="summary.current === 'dev' ? 'نسخه‌ی توسعه' : summary.current"></div>
            </div>
        </div>
        <div class="text-xs leading-6 text-ink-soft">
            <div>مخزن: <span class="font-mono font-semibold text-ink" dir="ltr">{{ $repository }}</span></div>
            <div>PHP <span class="num" dir="ltr">{{ PHP_VERSION }}</span> @unless ($hasToken) <span class="text-zinc-400">| بدون توکن GitHub</span> @endunless</div>
        </div>
        <button type="button" class="btn btn-primary" x-on:click="check()" x-bind:disabled="checking || busy">
            <span x-show="!checking">بررسی نسخه‌ی جدید</span>
            <span x-show="checking" x-cloak>در حال بررسی…</span>
        </button>
    </section>

    {{-- Messages --}}
    <div x-show="message" x-cloak class="mt-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm leading-6 text-red-800" role="alert" x-text="message"></div>
    <div x-show="checked && !available && !inProgress" x-cloak class="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        سامانه به‌روز است<span x-show="release">؛ آخرین نسخه‌ی منتشرشده <b class="num" x-text="release?.version"></b> است</span>.
    </div>
    <div x-show="summary.status === 'done' && !busy" x-cloak class="mt-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        آخرین به‌روزرسانی با موفقیت انجام شد<span x-show="summary.target">: نسخه‌ی <b class="num" x-text="summary.target?.version"></b></span>.
    </div>
    <div x-show="summary.status === 'rolled_back' && !busy" x-cloak class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        سامانه به نسخه‌ی <b class="num" x-text="summary.from"></b> برگردانده شد.
    </div>

    {{-- New release --}}
    <section x-show="release && available" x-cloak class="card mt-4 overflow-hidden" aria-label="نسخه‌ی جدید">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-accent-soft/60 px-5 py-4">
            <div>
                <div class="text-xs font-semibold text-accent">نسخه‌ی جدید</div>
                <div class="text-lg font-extrabold"><span class="num" x-text="release?.version"></span> <span class="text-sm font-normal text-ink-soft" x-text="release?.published_at ? '— ' + date(release.published_at) : ''"></span></div>
            </div>
            <button type="button" class="btn btn-primary" x-on:click="start()" x-bind:disabled="busy || inProgress">نصب این نسخه</button>
        </div>
        <div class="release-notes max-h-72 overflow-y-auto px-5 py-4 text-sm leading-7" x-html="release?.notes_html"></div>
    </section>

    {{-- Progress --}}
    <section x-show="inProgress || summary.status === 'done'" x-cloak class="card mt-4 p-5" aria-label="مراحل به‌روزرسانی">
        <h2 class="mb-3 text-sm font-bold">
            مراحل
            <span x-show="summary.target" class="font-normal text-ink-soft">— نسخه‌ی <span class="num" x-text="summary.target?.version"></span></span>
        </h2>
        <ol class="space-y-2.5">
            <template x-for="(step, index) in steps" :key="step.key">
                <li class="flex items-center gap-3 text-sm">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                          :class="{
                              'bg-stage-final text-white': stepState(step.key) === 'done',
                              'bg-accent text-white animate-pulse': stepState(step.key) === 'active',
                              'bg-red-600 text-white': stepState(step.key) === 'failed',
                              'bg-zinc-100 text-ink-soft': stepState(step.key) === 'pending',
                          }"
                          x-text="stepState(step.key) === 'done' ? '✓' : (stepState(step.key) === 'failed' ? '!' : (index + 1).toLocaleString('fa-IR'))"></span>
                    <span :class="{ 'font-semibold': stepState(step.key) === 'active', 'text-ink-soft': stepState(step.key) === 'pending' }" x-text="step.label"></span>
                </li>
            </template>
        </ol>

        <div x-show="summary.maintenance" x-cloak class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-[13px] text-amber-900">
            سامانه الان در حالت تعمیر است و کاربران دیگر صفحه‌ی «در حال به‌روزرسانی» می‌بینند.
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" x-show="canResume" x-cloak x-on:click="resume()">ادامه‌ی به‌روزرسانی</button>
            <button type="button" class="btn" x-show="canRestart && release && available" x-cloak x-on:click="runFrom('download')">تلاش دوباره</button>
            <button type="button" class="btn btn-ghost text-red-700 hover:bg-red-50" x-show="summary.can_rollback && !busy" x-cloak x-on:click="rollback()">بازگرداندن نسخه‌ی قبل</button>
        </div>
    </section>

    {{-- Log --}}
    <details x-show="summary.log && summary.log.length" x-cloak class="card mt-4 p-5 text-[13px]">
        <summary class="cursor-pointer font-semibold">گزارش آخرین به‌روزرسانی</summary>
        <ul class="mt-3 space-y-1.5">
            <template x-for="(line, i) in summary.log" :key="i">
                <li class="flex gap-3" :class="line.level === 'error' ? 'text-red-700' : 'text-ink-soft'">
                    <span class="num shrink-0 text-xs text-zinc-400" x-text="time(line.at)"></span>
                    <span class="leading-6" x-text="line.message"></span>
                </li>
            </template>
        </ul>
    </details>

    <p class="mt-6 text-xs leading-6 text-ink-soft">
        برای انتشار نسخه‌ی جدید، در GitHub یک Release با برچسبی مثل <code class="font-mono" dir="ltr">v1.2.0</code> بسازید. بسته‌ی نصب چند دقیقه بعد خودکار به Release اضافه می‌شود و از اینجا قابل نصب است.
        بکاپ‌های دیتابیس در <code class="font-mono" dir="ltr">core/storage/app/backups</code> نگه داشته می‌شوند (۵ نسخه‌ی آخر).
    </p>
</div>
