/*
 * TukaHR — Excel-like sheet behaviour (Alpine component used inside the Livewire grid).
 * Loaded as a classic script in <head> so it registers before Livewire starts Alpine.
 *
 * - Saves on change (blur/Enter) in small batches via $wire.saveCells (optimistic versions).
 * - Arrow/Enter navigation (RTL aware), multi-cell paste from Excel.
 * - Fill handle: drag the small square at the corner of the active cell up or down to copy
 *   its value into that column (Ctrl+D copies the value of the cell above), like Excel.
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
        info: '',
        infoTimer: null,
        handle: null,
        fill: null, // active drag: { source, col, r0, r1, value, pointer, targets }

        init() {
            const root = this.$refs.grid || this.$el;
            root.addEventListener('focusin', (e) => this.isCell(e.target) && this.onFocus(e.target));
            root.addEventListener('focusout', (e) => this.isCell(e.target) && this.onBlur(e.target));
            this.createHandle();
            // A Livewire re-render drops the handle (it is not in the server HTML); put it back.
            window.Livewire?.hook?.('commit', ({ succeed }) => succeed(() => requestAnimationFrame(() => {
                if (this.$el.isConnected && !this.fill && this.isFillable(document.activeElement)) {
                    this.placeHandle(document.activeElement);
                }
            })));
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
            this.cancelFill();
            this.handle?.remove();
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
            this.placeHandle(el);
        },
        onBlur(el) {
            if (this.isNumber(el)) el.value = this.group(this.raw(el));
            // Focus may be moving to another cell; decide after it lands.
            setTimeout(() => {
                if (!this.fill && !this.isFillable(document.activeElement)) this.hideHandle();
            }, 0);
        },
        notify(text) {
            this.info = text;
            clearTimeout(this.infoTimer);
            this.infoTimer = setTimeout(() => (this.info = ''), 3500);
        },
        toPersian(value) {
            return String(value).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
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
            // Large fills/pastes go in chunks well below the server's batch limit.
            const keys = Object.keys(this.pending).slice(0, 400);
            if (!keys.length) return;
            const batch = keys.map((key) => this.pending[key]);
            keys.forEach((key) => delete this.pending[key]);
            this.saving = true;
            this.status = 'saving';
            try {
                const res = await this.$wire.saveCells(batch);
                this.apply(res);
                const problems = res.errors.length + res.conflicts.length + res.denied.length;
                this.status = problems ? 'error' : this.hasPendingOnly() ? 'dirty' : 'saved';
                this.message = problems ? this.firstMessage(res) : '';
                if (res.structureChanged) this.$wire.$refresh();
                if (this.hasPendingOnly()) {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => this.flush(), 50);
                }
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
                el.classList.remove('is-dirty', 'is-error', 'is-conflict', 'is-out-of-range');
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

            if (this.fill && e.key === 'Escape') {
                e.preventDefault();
                this.cancelFill();
                return;
            }
            // Ctrl+D (any keyboard layout): copy the value of the nearest editable cell above.
            if ((e.ctrlKey || e.metaKey) && e.code === 'KeyD' && this.isFillable(el)) {
                e.preventDefault();
                const above = this.columnCells(el.dataset.col)
                    .filter((cell) => Number(cell.dataset.r) < r)
                    .pop();
                if (above) {
                    el.value = this.raw(above);
                    this.queue(el);
                    el.select();
                }
                return;
            }

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

        // ------------------------------------------------------------ fill handle

        /** Payroll cells only: personnel fields are unique per person and are never filled. */
        isFillable(el) {
            return this.isCell(el) && el.dataset.col !== undefined;
        },
        columnCells(col) {
            const root = this.$refs.grid || this.$el;
            return [...root.querySelectorAll(`input[data-cell][data-col="${col}"]`)];
        },

        createHandle() {
            const handle = document.createElement('div');
            handle.className = 'fill-handle';
            handle.title = 'برای کپی مقدار در خانه‌های بالا یا پایین، بکشید';
            handle.setAttribute('aria-hidden', 'true');
            // Keep focus (and the selection) in the cell while grabbing the handle.
            handle.addEventListener('mousedown', (e) => e.preventDefault());
            handle.addEventListener('pointerdown', (e) => this.startFill(e));
            handle.addEventListener('pointermove', (e) => this.moveFill(e));
            handle.addEventListener('pointerup', () => this.endFill());
            handle.addEventListener('pointercancel', () => this.cancelFill());
            handle.addEventListener('lostpointercapture', () => this.fill && this.endFill());
            this.handle = handle;
        },
        placeHandle(el) {
            if (!this.handle || this.fill) return;
            if (!this.isFillable(el)) {
                this.hideHandle();
                return;
            }
            const td = el.closest('td');
            if (this.handle.parentElement !== td) {
                this.handle.parentElement?.classList.remove('fill-host');
                td.appendChild(this.handle);
            }
            td.classList.add('fill-host');
        },
        hideHandle() {
            this.handle?.parentElement?.classList.remove('fill-host');
            this.handle?.remove();
        },

        startFill(e) {
            const td = this.handle.parentElement;
            const source = td && td.querySelector('input[data-cell]');
            if (!source || e.button > 0) return;
            e.preventDefault();
            this.handle.setPointerCapture(e.pointerId);
            this.queue(source); // commit what is typed in the source first

            this.fill = {
                source,
                col: source.dataset.col,
                r0: Number(source.dataset.r),
                r1: Number(source.dataset.r),
                value: this.raw(source),
                pointer: { x: e.clientX, y: e.clientY },
                targets: [],
                frame: null,
            };
            source.classList.add('is-fill-source');
            this.$el.classList.add('is-filling');
            this.fill.frame = requestAnimationFrame(() => this.autoScroll());
        },
        moveFill(e) {
            if (!this.fill) return;
            this.fill.pointer = { x: e.clientX, y: e.clientY };
            this.updateFillRange();
        },
        /** Row index under the pointer, read in the source column so sideways drift does not matter. */
        rowUnderPointer() {
            const root = this.$refs.grid || this.$el;
            const box = root.getBoundingClientRect();
            const head = root.querySelector('thead')?.getBoundingClientRect().height || 0;
            const foot = root.querySelector('tfoot')?.getBoundingClientRect().height || 0;
            const cell = this.fill.source.closest('td').getBoundingClientRect();
            const y = Math.min(Math.max(this.fill.pointer.y, box.top + head + 2), box.bottom - foot - 2);
            const hit = document.elementFromPoint(cell.left + cell.width / 2, y);
            const tr = hit && hit.closest('tbody tr[data-r]');
            return tr ? Number(tr.dataset.r) : null;
        },
        updateFillRange() {
            const r = this.rowUnderPointer();
            if (r === null || r === this.fill.r1) return;
            this.fill.r1 = r;
            const lo = Math.min(this.fill.r0, r);
            const hi = Math.max(this.fill.r0, r);
            this.fill.targets = [];
            this.columnCells(this.fill.col).forEach((cell) => {
                const index = Number(cell.dataset.r);
                const inRange = cell !== this.fill.source && index >= lo && index <= hi;
                cell.classList.toggle('is-fill-target', inRange);
                if (inRange) this.fill.targets.push(cell);
            });
        },
        autoScroll() {
            if (!this.fill) return;
            const root = this.$refs.grid || this.$el;
            const box = root.getBoundingClientRect();
            const y = this.fill.pointer.y;
            const edge = 36;
            let step = 0;
            if (y > box.bottom - edge) step = Math.min(28, (y - (box.bottom - edge)) / 2 + 4);
            else if (y < box.top + 40 + edge) step = -Math.min(28, (box.top + 40 + edge - y) / 2 + 4);
            if (step) {
                root.scrollTop += step;
                this.updateFillRange();
            }
            this.fill.frame = requestAnimationFrame(() => this.autoScroll());
        },
        endFill() {
            const fill = this.fill;
            if (!fill) return;
            this.clearFill();
            fill.targets.forEach((cell) => {
                cell.value = fill.value;
                this.queue(cell);
                this.show(cell, fill.value);
            });
            if (fill.targets.length) {
                this.notify(`مقدار در ${this.toPersian(fill.targets.length)} خانه کپی شد.`);
            }
        },
        cancelFill() {
            if (this.fill) this.clearFill();
        },
        clearFill() {
            cancelAnimationFrame(this.fill.frame);
            this.fill.source.classList.remove('is-fill-source');
            this.fill.targets.forEach((cell) => cell.classList.remove('is-fill-target'));
            this.$el.classList.remove('is-filling');
            this.fill = null;
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
