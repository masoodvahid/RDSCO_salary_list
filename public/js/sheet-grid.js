/*
 * TukaHR — Excel-like sheet behaviour (Alpine component used inside the Livewire grid).
 * Loaded as a classic script in <head> so it registers before Livewire starts Alpine.
 *
 * - Saves on change (blur/Enter) in small batches via $wire.saveCells (optimistic versions).
 * - Arrow/Enter navigation (RTL aware), multi-cell paste from Excel.
 * - Number cells show thousands separators; raw value while editing.
 * - Pulls other users' edits every few seconds via $wire.changesSince.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('sheetGrid', (options = {}) => ({
        status: 'saved', // saved | dirty | saving | error
        message: '',
        pending: {},
        saving: false,
        timer: null,
        pollTimer: null,
        lastSync: options.syncedAt || null,
        signature: options.signature || null,

        init() {
            const root = this.$refs.grid || this.$el;
            root.addEventListener('focusin', (e) => this.isCell(e.target) && this.onFocus(e.target));
            root.addEventListener('focusout', (e) => this.isCell(e.target) && this.onBlur(e.target));
            root.addEventListener('change', (e) => this.isCell(e.target) && this.queue(e.target));
            root.addEventListener('keydown', (e) => this.onKey(e));
            root.addEventListener('paste', (e) => this.onPaste(e));

            if (options.poll) {
                this.pollTimer = setInterval(() => this.sync(), options.poll * 1000);
            }
            this._beforeUnload = (e) => {
                if (this.hasPending()) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this._beforeUnload);
        },

        destroy() {
            clearInterval(this.pollTimer);
            window.removeEventListener('beforeunload', this._beforeUnload);
        },

        isCell(el) {
            return el && el.matches && el.matches('input[data-cell]');
        },
        isNumber(el) {
            return el.dataset.type === 'number';
        },
        hasPending() {
            return Object.keys(this.pending).length > 0 || this.saving;
        },
        keyOf(el) {
            return el.dataset.row + ':' + (el.dataset.field || el.dataset.col);
        },
        toLatin(value) {
            return String(value)
                .replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d))
                .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        },
        raw(el) {
            const value = el.value.trim();
            return this.isNumber(el) ? this.toLatin(value).replace(/[,٬،\s]/g, '').replace('٫', '.') : value;
        },
        group(value) {
            if (value === null || value === undefined || value === '') return '';
            const match = String(value).match(/^(-?)(\d+)(\.\d+)?$/);
            if (!match) return String(value);
            return match[1] + match[2].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (match[3] || '');
        },
        show(el, value) {
            el.value = this.isNumber(el) && document.activeElement !== el ? this.group(value ?? '') : value ?? '';
        },

        onFocus(el) {
            if (this.isNumber(el)) el.value = this.raw(el);
            requestAnimationFrame(() => el.select());
        },
        onBlur(el) {
            if (this.isNumber(el)) el.value = this.group(this.raw(el));
        },

        queue(el) {
            const value = this.raw(el);
            const key = this.keyOf(el);
            if (value === (el.dataset.saved ?? '')) {
                delete this.pending[key];
                el.classList.remove('is-dirty');
                return;
            }
            this.pending[key] = {
                row: Number(el.dataset.row),
                column: el.dataset.col ? Number(el.dataset.col) : null,
                field: el.dataset.field || null,
                value: value,
                version: el.dataset.version !== undefined ? Number(el.dataset.version) : null,
            };
            el.classList.add('is-dirty');
            el.classList.remove('is-error', 'is-conflict');
            this.status = 'dirty';
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.flush(), 400);
        },

        async flush() {
            if (this.saving) {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.flush(), 300);
                return;
            }
            const batch = Object.values(this.pending);
            if (!batch.length) return;
            this.pending = {};
            this.saving = true;
            this.status = 'saving';
            try {
                const res = await this.$wire.saveCells(batch);
                this.apply(res);
                const problems = res.errors.length + res.conflicts.length + res.denied.length;
                this.status = problems ? 'error' : this.hasPendingOnly() ? 'dirty' : 'saved';
                this.message = problems ? this.firstMessage(res) : '';
                if (res.structureChanged) this.$wire.$refresh();
            } catch (e) {
                batch.forEach((c) => {
                    const key = c.row + ':' + (c.field || c.column);
                    if (!this.pending[key]) this.pending[key] = c;
                });
                this.status = 'error';
                this.message = 'ذخیره انجام نشد. اتصال را بررسی کنید؛ دوباره تلاش می‌شود.';
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.flush(), 5000);
            } finally {
                this.saving = false;
            }
        },
        hasPendingOnly() {
            return Object.keys(this.pending).length > 0;
        },
        firstMessage(res) {
            const first = res.errors[0] || res.conflicts[0] || res.denied[0];
            return first ? first.message : '';
        },

        find(item) {
            const root = this.$refs.grid || this.$el;
            const selector = item.field
                ? `input[data-row="${item.row}"][data-field="${item.field}"]`
                : `input[data-row="${item.row}"][data-col="${item.column}"]`;
            return root.querySelector(selector);
        },

        apply(res) {
            res.saved.forEach((c) => {
                const el = this.find(c);
                if (!el) return;
                el.dataset.saved = c.value ?? '';
                if (!c.field) el.dataset.version = c.version;
                el.classList.remove('is-dirty', 'is-error', 'is-conflict');
                el.title = '';
                if (this.pending[this.keyOf(el)] === undefined) this.show(el, c.value);
            });
            res.conflicts.forEach((c) => {
                const el = this.find(c);
                if (!el) return;
                el.dataset.saved = c.value ?? '';
                el.dataset.version = c.version;
                this.show(el, c.value);
                el.classList.remove('is-dirty');
                el.classList.add('is-conflict');
                el.title = c.message;
            });
            [...res.errors, ...res.denied].forEach((c) => {
                const el = this.find(c);
                if (!el) return;
                el.classList.remove('is-dirty');
                el.classList.add('is-error');
                el.title = c.message;
            });
        },

        async sync() {
            if (this.hasPending() || document.hidden) return;
            try {
                const res = await this.$wire.changesSince(this.lastSync, this.signature);
                this.lastSync = res.now;
                res.cells.forEach((c) => {
                    const el = this.find(c);
                    if (!el || el === document.activeElement || el.classList.contains('is-dirty')) return;
                    if (Number(el.dataset.version || 0) >= c.version) return;
                    el.dataset.version = c.version;
                    el.dataset.saved = c.value ?? '';
                    this.show(el, c.value);
                    el.classList.add('is-remote');
                    setTimeout(() => el.classList.remove('is-remote'), 2500);
                });
                if (res.signature !== this.signature) {
                    this.signature = res.signature;
                    this.$wire.$refresh();
                }
            } catch (e) {
                // Next poll will retry.
            }
        },

        onKey(e) {
            const el = e.target;
            if (!this.isCell(el)) return;
            const r = Number(el.dataset.r);
            const c = Number(el.dataset.c);
            let target = null;

            if (e.key === 'Enter') {
                target = [r + (e.shiftKey ? -1 : 1), c];
            } else if (e.key === 'ArrowDown') {
                target = [r + 1, c];
            } else if (e.key === 'ArrowUp') {
                target = [r - 1, c];
            } else if (e.key === 'ArrowLeft' && (e.ctrlKey || el.selectionStart === el.value.length)) {
                target = [r, c + 1]; // RTL: left is the next column
            } else if (e.key === 'ArrowRight' && (e.ctrlKey || el.selectionEnd === 0)) {
                target = [r, c - 1];
            } else if (e.key === 'Escape') {
                el.value = el.dataset.saved ?? '';
                delete this.pending[this.keyOf(el)];
                el.classList.remove('is-dirty');
                el.blur();
                return;
            }

            if (!target) return;
            const root = this.$refs.grid || this.$el;
            const next = root.querySelector(`input[data-cell][data-r="${target[0]}"][data-c="${target[1]}"]`);
            e.preventDefault();
            if (e.key === 'Enter') this.queue(el);
            if (next) next.focus();
        },

        onPaste(e) {
            const el = e.target;
            if (!this.isCell(el)) return;
            const text = (e.clipboardData || window.clipboardData).getData('text');
            if (!text || (!text.includes('\t') && !text.includes('\n'))) return;
            e.preventDefault();

            const root = this.$refs.grid || this.$el;
            const lines = text.replace(/\r/g, '').replace(/\n$/, '').split('\n');
            const r0 = Number(el.dataset.r);
            const c0 = Number(el.dataset.c);
            lines.forEach((line, dr) => {
                line.split('\t').forEach((value, dc) => {
                    const target = root.querySelector(`input[data-cell][data-r="${r0 + dr}"][data-c="${c0 + dc}"]`);
                    if (!target) return;
                    target.value = value.trim();
                    this.queue(target);
                    if (target !== document.activeElement && this.isNumber(target)) {
                        target.value = this.group(this.raw(target));
                    }
                });
            });
        },
    }));
});
