/*
 * TukaHR — in-app updater page (Alpine component). Every step is a separate POST to
 * /system/update/run/{step}; the steps run one after another, so "finalize" executes with
 * the code that "install" has just put in place.
 */
document.addEventListener('alpine:init', () => {
    const ORDER = ['download', 'backup', 'install', 'finalize'];
    const DONE_AFTER = { downloaded: 1, backed_up: 2, installed: 3, done: 4 };
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    window.Alpine.data('updater', (config = {}) => ({
        summary: config.summary || {},
        urls: config.urls || {},
        release: null,
        available: false,
        checked: false,
        checking: false,
        busy: false,
        current: null,
        message: '',
        steps: [
            { key: 'download', label: 'دانلود بسته و بررسی checksum' },
            { key: 'backup', label: 'بکاپ دیتابیس' },
            { key: 'install', label: 'جایگزینی فایل‌ها (سامانه چند ثانیه در دسترس نیست)' },
            { key: 'finalize', label: 'اجرای migration و راه‌اندازی دوباره' },
        ],

        get inProgress() {
            return this.busy || ['downloaded', 'backed_up', 'installed'].includes(this.summary.status) || this.summary.status === 'failed';
        },
        get canResume() {
            return !this.busy && (Boolean(this.summary.next) || this.summary.failed_step === 'finalize');
        },
        get canRestart() {
            return !this.busy && this.summary.status === 'failed' && ['download', 'backup'].includes(this.summary.failed_step);
        },

        stepState(key) {
            const index = ORDER.indexOf(key);
            if (this.busy && this.current === key) return 'active';
            if (this.summary.status === 'failed' && this.summary.failed_step === key) return 'failed';
            const done = this.summary.status === 'failed'
                ? ORDER.indexOf(this.summary.failed_step)
                : DONE_AFTER[this.summary.status] || 0;
            return index < done ? 'done' : 'pending';
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },
        async post(url) {
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                let data = {};
                try {
                    data = await response.json();
                } catch (e) {
                    data = { message: 'پاسخ سرور قابل خواندن نبود (کد ' + response.status + ').' };
                }
                return { ok: response.ok, data };
            } catch (e) {
                return { ok: false, data: { message: 'ارتباط با سرور قطع شد. صفحه را تازه کنید تا وضعیت به‌روزرسانی دیده شود.' } };
            }
        },
        async refresh() {
            try {
                const response = await fetch(this.urls.status, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (response.ok) this.summary = await response.json();
            } catch (e) {
                // keep the last known state
            }
        },

        async check() {
            this.checking = true;
            this.message = '';
            const { ok, data } = await this.post(this.urls.check);
            this.checking = false;
            this.checked = ok;
            if (!ok) {
                this.message = data.message || 'بررسی نسخه‌ی جدید ممکن نشد.';
                return;
            }
            this.release = data.release;
            this.available = data.available;
        },

        async start() {
            if (!this.release) return;
            const question = `نسخه‌ی ${this.release.version} نصب شود؟\n\nپیش از نصب از دیتابیس بکاپ گرفته می‌شود. هنگام جایگزینی فایل‌ها سامانه برای چند ثانیه تا یک دقیقه در دسترس کاربران نیست.`;
            if (!window.confirm(question)) return;
            await this.runFrom('download');
        },
        async resume() {
            await this.runFrom(this.summary.next || 'finalize');
        },
        async runFrom(step) {
            this.busy = true;
            this.message = '';
            for (let i = ORDER.indexOf(step); i < ORDER.length; i++) {
                this.current = ORDER[i];
                // Give PHP's opcache a moment to notice the replaced files before migrating.
                if (ORDER[i] === 'finalize') await sleep(2500);
                const { ok, data } = await this.post(`${this.urls.run}/${ORDER[i]}`);
                if (data && data.status !== undefined) this.summary = data;
                if (!ok) {
                    this.message = data.message || data.error || 'این مرحله ناموفق بود.';
                    break;
                }
            }
            this.current = null;
            this.busy = false;
            if (this.summary.status === 'done') {
                this.message = '';
                setTimeout(() => window.location.reload(), 2500);
            }
        },

        async rollback() {
            const question = 'سامانه به نسخه‌ی قبل برگردد؟\n\nدیتابیس به بکاپ همین به‌روزرسانی برمی‌گردد؛ اطلاعاتی که بعد از آن وارد شده باشد از بین می‌رود.';
            if (!window.confirm(question)) return;
            this.busy = true;
            this.current = 'rollback';
            this.message = '';
            const { ok, data } = await this.post(`${this.urls.run}/rollback`);
            if (data && data.status !== undefined) this.summary = data;
            this.busy = false;
            this.current = null;
            if (!ok) {
                this.message = data.message || data.error || 'بازگردانی ناموفق بود.';
                return;
            }
            setTimeout(() => window.location.reload(), 2000);
        },

        time(iso) {
            try {
                return new Date(iso).toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            } catch (e) {
                return '';
            }
        },
        date(iso) {
            try {
                return new Date(iso).toLocaleDateString('fa-IR', { year: 'numeric', month: 'long', day: 'numeric' });
            } catch (e) {
                return '';
            }
        },
    }));
});
