/*
 * TukaHR — Excel-like sheet behaviour (Alpine component used inside the Livewire grid).
 * Loaded as a classic script in <head> so it registers before Livewire starts Alpine.
 *
 * - Saves on change (blur/Enter) in small batches via $wire.saveCells (optimistic versions).
 * - Arrow/Enter navigation (RTL aware), multi-cell paste from Excel.
 * - Fill handle: drag the small square at the corner of the active cell up or down to copy
 *   its value into that column (Ctrl+D copies the value of the cell above), like Excel.
 * - Validation before saving: a number column's range (data-min/data-max on its <th>) and
 *   number format (whole numbers only) are checked first; invalid input is never queued. The error
 *   shows under the cell; Enter keeps the cell for correction, leaving it restores the saved value.
 * - Managers reorder columns by dragging the grip in the column header.
 * - Everyone resizes columns with the handle at the end of a header (double-click: default width); the
 *   width is the user's own and is saved for them ($wire.saveColumnWidth).
 * - Managers select rows (Shift+click for a run of rows) to delete them or set their project.
 * - Number cells show thousands separators; raw value while editing.
 * - Pulls other users' edits every few seconds via $wire.changesSince.
 * - On a re-render, rows whose HTML did not change (same data-hash) are not morphed at all; actions on
 *   one person (approve, notes, project) only send that row ('grid-rows', see Grid::patchRows).
 * - Big lists: only the rows near the viewport are rendered (the rest stay in the DOM with .v-off), so
 *   typing, hovering and scrolling cost the same with 50 or 1000 people. Ctrl+F goes to the grid search,
 *   which searches every row (pressed again, it opens the browser's own search).
 * - Rows carry no Alpine directives (their <tbody> is x-ignore'd): their buttons, selection checkboxes
 *   and project <select> are handled here by event delegation.
 */
document.addEventListener('livewire:init', () => {
    // Morphing a big table costs far more than the change itself; a row with the same hash as the
    // server's new HTML is identical, so skip it (and keep its client state: errors, pending edits).
    window.Livewire.hook('morph.updating', ({ el, toEl, skip }) => {
        if (el.tagName === 'TR' && el.dataset.hash && el.dataset.hash === toEl.dataset?.hash) skip();
    });
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('sheetGrid', (options = {}) => ({
        status: 'saved', // saved | dirty | saving | error
        message: '',
        pending: {},
        saving: false,
        timer: null,
        pollTimer: null,
        // Seeded from data-synced-at / data-signature on the root (not from options: the x-data
        // expression has to stay the same across renders, or Alpine re-runs the whole component).
        lastSync: null,
        signature: null,
        info: '',
        infoTimer: null,
        handle: null,
        fill: null, // active drag: { source, col, r0, r1, value, pointer, targets }
        bubble: null,
        colDrag: null, // active column drag: { grip, th, id, target, before, pointer, moved }
        colResize: null, // active column resize: { handle, th, key, rtl, x, start, width, frame, unbind }
        selected: {}, // row id → true (manager multi-select)
        lastSelected: null, // anchor row for Shift+click
        win: null, // rendered rows window: { rows, top, bottom, start, end, rowH, bodyTop, scrollTop, viewH }

        init() {
            const root = this.$refs.grid || this.$el;
            this.lastSync = this.$el.dataset.syncedAt || options.syncedAt || null;
            this.signature = this.$el.dataset.signature || options.signature || null;
            root.addEventListener('focusin', (e) => this.isCell(e.target) && this.onFocus(e.target));
            root.addEventListener('focusout', (e) => this.isCell(e.target) && this.onBlur(e.target));
            this.createHandle();
            this.createBubble();
            root.addEventListener('pointerdown', (e) => {
                const grip = e.target.closest && e.target.closest('[data-col-grip]');
                if (grip) this.startColumnDrag(e, grip);
                const handle = e.target.closest && e.target.closest('[data-col-resize]');
                if (handle) this.startColumnResize(e, handle);
            });
            root.addEventListener('dblclick', (e) => {
                const handle = e.target.closest && e.target.closest('[data-col-resize]');
                if (handle) this.resetColumnWidth(handle);
            });
            // A Livewire re-render drops the handle (it is not in the server HTML); put it back.
            // It also brings the current structure signature, so the next poll does not refresh again.
            this._unhookCommit = window.Livewire?.hook?.('commit', ({ succeed }) => succeed(() => requestAnimationFrame(() => {
                if (this.$el.isConnected && this.$el.dataset.signature) this.signature = this.$el.dataset.signature;
                if (this.$el.isConnected) this.pruneSelection();
                const active = document.activeElement;
                if (this.$el.isConnected && !this.fill && this.isFillable(active)) this.placeHandle(active);
                if (this.$el.isConnected && this.isCell(active) && active.classList.contains('is-error')) {
                    this.showError(active, active.title);
                }
            })));
            // A row's project <select> ships with only its current option; fill it on first use.
            const fillProjects = (e) => {
                const select = e.target.closest && e.target.closest('select[data-project-select]');
                if (select) this.fillProjectSelect(select);
            };
            root.addEventListener('mousedown', fillProjects, true);
            root.addEventListener('focusin', fillProjects);
            root.addEventListener('touchstart', fillProjects, { capture: true, passive: true });
            root.addEventListener('change', (e) => this.isCell(e.target) && this.queue(e.target));
            root.addEventListener('keydown', (e) => this.onKey(e));
            root.addEventListener('paste', (e) => this.onPaste(e));
            root.addEventListener('click', (e) => this.onRowClick(e));
            root.addEventListener('change', (e) => this.onRowChange(e));
            // A single-row action sends just that row; a full table render may bring other rows.
            this._offRows = window.Livewire?.on?.('grid-rows', ({ rows }) => this.patchRows(rows));
            this._unhookIsland = window.Livewire?.hook?.('island.morphed', ({ component }) => {
                if (component?.el === this.$el) this.afterTableRender();
            });
            this.initWindow();
            this._onFind = (e) => this.onFind(e);
            window.addEventListener('keydown', this._onFind);

            if (options.poll) {
                this.pollTimer = setInterval(() => this.sync(), options.poll * 1000);
            }
            this._beforeUnload = (e) => {
                if (this.hasPending() || this.openError()) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this._beforeUnload);
        },

        destroy() {
            this._unhookCommit?.();
            this._unhookIsland?.();
            this._offRows?.();
            clearInterval(this.pollTimer);
            window.removeEventListener('beforeunload', this._beforeUnload);
            window.removeEventListener('keydown', this._onFind);
            window.removeEventListener('resize', this._onResize);
            document.documentElement.classList.remove('grid-windowed');
            this.cancelFill();
            this.cancelColumnDrag();
            this.cancelColumnResize();
            this.handle?.remove();
            this.bubble?.remove();
        },

        fillProjectSelect(select) {
            if (select.dataset.filled) return;
            const options = document.getElementById('project-options');
            if (!options) return;
            const value = select.dataset.value || '';
            select.innerHTML = options.innerHTML;
            select.value = value;
            select.dataset.filled = '1';
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
            // Input that failed validation is never left on screen as if it were saved.
            if (el.classList.contains('is-error')) this.revert(el, el.title);
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

        // ------------------------------------------------------------ validation

        /** Exact comparison of two numeric strings (no float rounding), mirroring Digits::compare. */
        compare(a, b) {
            const canon = (v) => {
                let [, sign, int, frac = ''] = String(v).match(/^(-?)(\d+)(?:\.(\d+))?$/) || [null, '', '0'];
                int = int.replace(/^0+(?=\d)/, '');
                frac = frac.replace(/0+$/, '');
                return { neg: sign === '-' && (int !== '0' || frac !== ''), int, frac };
            };
            const x = canon(a);
            const y = canon(b);
            if (x.neg !== y.neg) return x.neg ? -1 : 1;
            const len = Math.max(x.frac.length, y.frac.length);
            const abs = x.int.length - y.int.length
                || (x.int > y.int) - (x.int < y.int)
                || (x.frac.padEnd(len, '0') > y.frac.padEnd(len, '0')) - (x.frac.padEnd(len, '0') < y.frac.padEnd(len, '0'));
            const result = Math.sign(abs);
            return x.neg ? -result : result;
        },
        rule(el) {
            const root = this.$refs.grid || this.$el;
            const th = el.dataset.col && root.querySelector(`thead th[data-column-id="${el.dataset.col}"]`);
            return th ? th.dataset : {};
        },
        /** Validation message for the cell's current input, or null when it may be saved. */
        validate(el) {
            if (!this.isNumber(el)) return null;
            const value = this.raw(el);
            if (value === '') return null;
            if (!/^-?\d+(\.\d+)?$/.test(value)) return 'در این ستون فقط عدد وارد کنید.';
            // Whole numbers only ("12.00" is still 12), as the server checks (Digits::normalizeInteger).
            if (/\.\d*[1-9]/.test(value)) return 'در این ستون فقط عدد صحیح وارد کنید؛ اعشار مجاز نیست.';
            const rule = this.rule(el);
            const below = rule.min !== undefined && this.compare(value, rule.min) < 0;
            const above = rule.max !== undefined && this.compare(value, rule.max) > 0;
            return below || above ? rule.rangeMessage || 'این مقدار خارج از بازه‌ی مجاز است.' : null;
        },
        /** Refuses the input of a cell: in focus it stays for correction, otherwise it is undone. */
        reject(el, message) {
            delete this.pending[this.keyOf(el)];
            el.classList.remove('is-dirty');
            el.classList.add('is-error');
            el.setAttribute('aria-invalid', 'true');
            el.title = message;
            this.status = 'error';
            this.message = 'ذخیره نشد: ' + message;
            if (document.activeElement === el) {
                this.showError(el, message);
            } else {
                this.revert(el, message);
            }
        },
        revert(el, message) {
            const saved = el.dataset.saved ?? '';
            this.clearError(el);
            delete this.pending[this.keyOf(el)];
            el.classList.remove('is-dirty');
            this.show(el, saved);
            el.classList.add('is-reverted');
            setTimeout(() => el.classList.remove('is-reverted'), 1600);
            this.status = 'error';
            this.message = 'ذخیره نشد: ' + (message || 'مقدار نامعتبر بود') + ' مقدار قبلی برگشت.';
        },
        clearError(el) {
            el.classList.remove('is-error');
            el.removeAttribute('aria-invalid');
            el.title = '';
            if (this.bubble?.parentElement === el.closest('td')) this.hideError();
        },
        openError() {
            const root = this.$refs.grid || this.$el;
            return root.querySelector('input[data-cell].is-error');
        },
        createBubble() {
            const bubble = document.createElement('div');
            bubble.className = 'cell-error';
            bubble.setAttribute('role', 'alert');
            this.bubble = bubble;
        },
        showError(el, message) {
            const td = el.closest('td');
            if (!td || !this.bubble) return;
            this.bubble.innerHTML = '';
            const text = document.createElement('span');
            text.textContent = message;
            const hint = document.createElement('small');
            hint.textContent = 'مقدار را اصلاح کنید و Enter بزنید؛ Esc مقدار قبلی را برمی‌گرداند.';
            this.bubble.append(text, hint);
            this.bubble.classList.remove('is-above');
            if (this.bubble.parentElement !== td) {
                this.hideError();
                td.appendChild(this.bubble);
            }
            td.classList.add('error-host');
            // Near the bottom of the grid, open upward so the footer does not cover it.
            const root = this.$refs.grid || this.$el;
            const foot = root.querySelector('tfoot')?.getBoundingClientRect().height || 0;
            const limit = root.getBoundingClientRect().bottom - foot;
            if (this.bubble.getBoundingClientRect().bottom > limit) this.bubble.classList.add('is-above');
        },
        hideError() {
            this.bubble?.parentElement?.classList.remove('error-host');
            this.bubble?.remove();
        },

        // ------------------------------------------------------------ saving

        /** Queues a cell for saving after validating it. Returns false when the input was refused. */
        queue(el) {
            const value = this.raw(el);
            const key = this.keyOf(el);
            // Unchanged is never refused: values saved before a rule (a fraction, a range set later) may stay.
            if (value === (el.dataset.saved ?? '')) {
                this.clearError(el);
                delete this.pending[key];
                el.classList.remove('is-dirty');
                return true;
            }
            const error = this.validate(el);
            if (error) {
                this.reject(el, error);
                return false;
            }
            this.clearError(el);
            this.pending[key] = {
                row: Number(el.dataset.row),
                column: el.dataset.col ? Number(el.dataset.col) : null,
                field: el.dataset.field || null,
                value: value,
                version: el.dataset.version !== undefined ? Number(el.dataset.version) : null,
            };
            el.classList.add('is-dirty');
            el.classList.remove('is-conflict');
            this.status = 'dirty';
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.flush(), 400);
            return true;
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
                if (problems) {
                    this.status = 'error';
                    if (!this.message.startsWith('ذخیره نشد')) this.message = this.firstMessage(res);
                } else if (!this.openError()) {
                    this.status = this.hasPendingOnly() ? 'dirty' : 'saved';
                    this.message = '';
                }
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
                // Skip cells the user has typed into again since this batch was sent.
                if (!el || this.pending[this.keyOf(el)] !== undefined) return;
                this.reject(el, c.message);
            });
        },

        async sync() {
            if (this.hasPending() || document.hidden) return;
            try {
                const res = await this.$wire.changesSince(this.lastSync, this.signature);
                this.lastSync = res.now;
                res.cells.forEach((c) => {
                    const el = this.find(c);
                    if (!el || el === document.activeElement || el.classList.contains('is-dirty') || el.classList.contains('is-error')) return;
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
                } else if (res.rows) {
                    this.patchRows(res.rows);
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
            if (e.key === 'Escape' && el.classList.contains('is-error')) {
                e.preventDefault();
                this.revert(el, el.title);
                this.status = this.hasPendingOnly() ? 'dirty' : 'saved';
                this.message = '';
                el.select();
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
            // Like Excel: invalid input keeps the cell (and shows why) instead of moving on.
            if (!this.queue(el)) {
                el.select();
                return;
            }
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
            if (!this.queue(source)) return; // commit what is typed in the source first; refuse invalid input
            this.handle.setPointerCapture(e.pointerId);

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
            const refused = [];
            lines.forEach((line, dr) => {
                line.split('\t').forEach((value, dc) => {
                    const target = root.querySelector(`input[data-cell][data-r="${r0 + dr}"][data-c="${c0 + dc}"]`);
                    if (!target) return;
                    target.value = value.trim();
                    const error = this.validate(target);
                    if (!this.queue(target)) {
                        refused.push(error);
                        return;
                    }
                    if (target !== document.activeElement && this.isNumber(target)) {
                        target.value = this.group(this.raw(target));
                    }
                });
            });
            if (refused.length > 1) {
                this.message = `${this.toPersian(refused.length)} مقدار ذخیره نشد؛ ${refused.filter(Boolean)[0] || ''}`;
            }
        },

        // ------------------------------------------------------------ row selection (managers)

        get selectedIds() {
            return Object.keys(this.selected).filter((id) => this.selected[id]).map(Number);
        },
        get selectedCount() {
            return this.selectedIds.length;
        },
        /** Visible rows that can be selected, in screen order. */
        selectableRows() {
            const root = this.$refs.grid || this.$el;
            return [...root.querySelectorAll('input[data-select-row]:not(:disabled)')].map((el) => Number(el.dataset.selectRow));
        },
        toggleRow(e, id) {
            const on = e.target.checked;
            const rows = this.selectableRows();
            const from = rows.indexOf(this.lastSelected);
            const to = rows.indexOf(id);
            if (e.shiftKey && from !== -1 && to !== -1) {
                // Shift+click: the whole run between the previous click and this one.
                const [lo, hi] = from < to ? [from, to] : [to, from];
                rows.slice(lo, hi + 1).forEach((rowId) => this.setSelected(rowId, on));
            } else {
                this.setSelected(id, on);
            }
            this.lastSelected = id;
            this.syncCheckboxes();
        },
        setSelected(id, on) {
            if (on) this.selected[id] = true;
            else delete this.selected[id];
        },
        toggleAll(e) {
            if (e.target.checked) this.selectableRows().forEach((id) => (this.selected[id] = true));
            else this.clearSelection();
            this.syncCheckboxes();
        },
        clearSelection() {
            this.selected = {};
            this.lastSelected = null;
            this.syncCheckboxes();
        },
        /** Row checkboxes have no x-bind (see the header comment): set them, and the row highlight, from `selected`. */
        syncCheckboxes() {
            const root = this.$refs.grid || this.$el;
            root.querySelectorAll('input[data-select-row]').forEach((box) => {
                const on = this.selected[box.dataset.selectRow] === true;
                if (box.checked !== on) box.checked = on;
                const row = box.closest('tr');
                if (row && row.classList.contains('is-selected') !== on) row.classList.toggle('is-selected', on);
            });
        },
        /** Keeps the header checkbox in step: checked, indeterminate or empty. */
        syncSelectAll(el) {
            const count = this.selectedCount;
            const total = this.selectableRows().length;
            el.checked = total > 0 && count >= total;
            el.indeterminate = count > 0 && count < total;
        },
        /** After a re-render, forget rows that are no longer on screen (deleted or filtered out). */
        pruneSelection() {
            const present = new Set(this.selectableRows());
            Object.keys(this.selected).forEach((id) => present.has(Number(id)) || delete this.selected[id]);
        },
        async deleteSelected() {
            const ids = this.selectedIds;
            if (!ids.length) return;
            const question = `${this.toPersian(ids.length)} ردیف حذف شود؟\n\nمقادیر و یادداشت‌های این افراد هم حذف می‌شود. حذف در لاگ تغییرات ثبت می‌شود.`;
            if (!window.confirm(question)) return;
            await this.settle();
            if (await this.$wire.deleteRows(ids)) this.clearSelection();
        },
        async assignProject(projectId) {
            const ids = this.selectedIds;
            if (!ids.length || projectId === '__') return;
            await this.settle();
            if (await this.$wire.setRowsProject(ids, projectId === '' ? null : Number(projectId))) this.clearSelection();
        },

        // ------------------------------------------------------------ row actions and row updates

        tableBody() {
            return (this.$refs.grid || this.$el).querySelector('table.sheet > tbody');
        },
        onRowClick(e) {
            const box = e.target.closest && e.target.closest('input[data-select-row]');
            if (box) {
                this.toggleRow(e, Number(box.dataset.selectRow));
                return;
            }
            const button = e.target.closest && e.target.closest('button[data-act]');
            const row = button && button.closest('tr[data-row-id]');
            if (!row) return;
            const id = Number(row.dataset.rowId);
            switch (button.dataset.act) {
                case 'approve': this.$wire.approveRow(id); break;
                case 'reject': this.$wire.openReject(id); break;
                case 'notes': this.$wire.openNotes(id); break;
                case 'delete': if (window.confirm(button.dataset.confirm)) this.$wire.deleteRow(id); break;
            }
        },
        onRowChange(e) {
            const select = e.target.closest && e.target.closest('select[data-project-select]');
            const row = select && select.closest('tr[data-row-id]');
            if (row) this.$wire.setRowProject(Number(row.dataset.rowId), select.value);
        },
        /** Rows re-rendered on the server (a single-row action, or rows others changed: Grid::patchRows). */
        patchRows(rows) {
            const body = this.tableBody();
            if (!body || !rows) return;
            Object.entries(rows).forEach(([id, html]) => {
                const row = body.querySelector(`:scope > tr[data-row-id="${id}"]`);
                if (!row) return;
                const hidden = row.classList.contains('v-off');
                // A cell being edited keeps the version it was opened with, so a save still detects that
                // someone else changed it meanwhile (like the cell sync in sync()).
                const editing = [...row.querySelectorAll('input[data-cell]')]
                    .filter((el) => el === document.activeElement || el.classList.contains('is-dirty') || el.classList.contains('is-error'))
                    .map((el) => [el, el.dataset.saved, el.dataset.version, el.className, el.title]);
                // Morph, not replace: the same elements stay (focus, typed text, pending edits).
                window.Alpine.morph(row, html);
                row.classList.toggle('v-off', hidden);
                editing.forEach(([el, saved, version, className, title]) => {
                    if (saved !== undefined) el.dataset.saved = saved;
                    if (version !== undefined) el.dataset.version = version;
                    el.className = className;
                    el.title = title;
                });
            });
            this.syncCheckboxes();
            const active = document.activeElement;
            if (!this.fill && this.isFillable(active)) this.placeHandle(active);
        },
        afterTableRender() {
            this.refreshWindow();
            this.syncCheckboxes();
        },
        /** Ctrl+F searches every row (the browser's search only sees rendered rows); again: browser search. */
        onFind(e) {
            if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey || e.code !== 'KeyF') return;
            const search = document.getElementById('grid-search');
            if (!search || !this.$el.isConnected || document.activeElement === search || document.querySelector('[role="dialog"]')) return;
            e.preventDefault();
            search.focus();
            search.select();
        },

        // ------------------------------------------------------------ rendered rows window (big lists)

        initWindow() {
            const grid = this.$refs.grid;
            this.win = { rows: [], top: null, bottom: null, start: -1, end: -1, rowH: 35, bodyTop: 0, scrollTop: grid.scrollTop, viewH: grid.clientHeight };
            this.collectRows();
            // Measured while only the first rows are rendered (see app.css), so this layout is cheap.
            const body = this.tableBody();
            if (body) this.win.bodyTop = body.getBoundingClientRect().top - grid.getBoundingClientRect().top + grid.scrollTop;
            const sample = this.win.rows[0];
            if (sample && sample.offsetHeight > 0) this.win.rowH = sample.offsetHeight;
            this.updateWindow(true);
            document.documentElement.classList.add('grid-windowed');
            grid.addEventListener('scroll', () => {
                this.win.scrollTop = grid.scrollTop;
                this.updateWindow();
            }, { passive: true });
            this._onResize = () => {
                this.win.viewH = grid.clientHeight;
                this.updateWindow(true);
            };
            window.addEventListener('resize', this._onResize);
        },
        collectRows() {
            const body = this.tableBody();
            this.win.rows = body ? [...body.querySelectorAll(':scope > tr[data-row-id]')] : [];
            this.win.top = body && body.querySelector(':scope > #v-top > td');
            this.win.bottom = body && body.querySelector(':scope > #v-bottom > td');
        },
        /** After the table was rendered again: rows may have come, gone or lost their .v-off. */
        refreshWindow() {
            if (!this.win) return;
            this.collectRows();
            this.updateWindow(true);
        },
        /**
         * Renders the rows around the viewport (in steps of 10, with 24 rows to spare on each side) and gives
         * the spacer rows the height of the rest. Uses the cached scroll position, so it never forces a layout
         * of a freshly rendered table. The row holding the focus (or the fill source) always stays rendered.
         */
        updateWindow(force = false) {
            const win = this.win;
            const rows = win && win.rows;
            if (!rows || !rows.length || !win.top || !win.bottom) return;
            const STEP = 10;
            const SPARE = 24;
            const first = Math.max(0, Math.floor((win.scrollTop - win.bodyTop) / win.rowH));
            const shown = Math.ceil(win.viewH / win.rowH) + 1;
            const start = Math.max(0, Math.floor((first - SPARE) / STEP) * STEP);
            const end = Math.min(rows.length - 1, Math.ceil((first + shown + SPARE) / STEP) * STEP);
            if (!force && start === win.start && end === win.end) return;
            win.start = start;
            win.end = end;
            const keep = (this.fill && this.fill.source) || document.activeElement;
            const keepRow = keep && keep.closest ? keep.closest('tr[data-row-id]') : null;
            let before = 0;
            let after = 0;
            rows.forEach((tr, i) => {
                const off = (i < start || i > end) && tr !== keepRow;
                if (off) {
                    if (i < start) before++;
                    else after++;
                }
                if (tr.classList.contains('v-off') !== off) tr.classList.toggle('v-off', off);
            });
            win.top.style.height = before * win.rowH + 'px';
            win.bottom.style.height = after * win.rowH + 'px';
        },

        // ------------------------------------------------------------ column widths (everyone)

        startColumnResize(e, handle) {
            const th = handle.closest('th');
            if (!th || e.button > 0 || this.colResize || this.colDrag) return;
            e.preventDefault();
            e.stopPropagation();
            handle.setPointerCapture(e.pointerId);
            const move = (ev) => this.moveColumnResize(ev);
            const up = () => this.endColumnResize();
            const cancel = () => this.cancelColumnResize();
            const key = (ev) => ev.key === 'Escape' && this.cancelColumnResize();
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', cancel);
            handle.addEventListener('lostpointercapture', up);
            window.addEventListener('keydown', key);
            // Columns are at least as wide as their content, and share out any room left when the table is narrower
            // than the screen; start from what the user sees, or from the set width when that room is shared out.
            const table = th.closest('table');
            const stretched = table && table.offsetWidth <= (table.parentElement?.clientWidth || 0) + 1;
            const shown = th.getBoundingClientRect().width;
            this.colResize = {
                handle,
                th,
                key: handle.dataset.colResize,
                rtl: getComputedStyle(th).direction === 'rtl',
                x: e.clientX,
                start: Math.round(stretched ? parseFloat(th.style.width) || shown : shown),
                original: th.style.width,
                width: null,
                frame: null,
                unbind: () => {
                    handle.removeEventListener('pointermove', move);
                    handle.removeEventListener('pointerup', up);
                    handle.removeEventListener('pointercancel', cancel);
                    handle.removeEventListener('lostpointercapture', up);
                    window.removeEventListener('keydown', key);
                },
            };
            handle.classList.add('is-active');
            document.documentElement.classList.add('is-resizing-column');
        },
        /** Same limits as Grid::saveColumnWidth: the sticky name columns stay narrower. */
        clampColumnWidth(key, width) {
            const max = key === 'first_name' || key === 'last_name' ? 320 : 600;
            return Math.round(Math.min(max, Math.max(56, width)));
        },
        setColumnWidth(th, key, width) {
            th.style.width = width + 'px';
            // The sticky last-name column sits right after the first name.
            if (key === 'first_name') th.closest('table')?.style.setProperty('--w-first', width + 'px');
        },
        moveColumnResize(e) {
            const resize = this.colResize;
            if (!resize) return;
            // The handle is on the end edge: in RTL that is the left one, so dragging left widens.
            const delta = resize.rtl ? resize.x - e.clientX : e.clientX - resize.x;
            resize.width = this.clampColumnWidth(resize.key, resize.start + delta);
            if (resize.frame) return;
            resize.frame = requestAnimationFrame(() => {
                resize.frame = null;
                if (this.colResize === resize) this.setColumnWidth(resize.th, resize.key, resize.width);
            });
        },
        clearColumnResize() {
            const resize = this.colResize;
            cancelAnimationFrame(resize.frame);
            resize.unbind();
            resize.handle.classList.remove('is-active');
            document.documentElement.classList.remove('is-resizing-column');
            this.colResize = null;
            return resize;
        },
        cancelColumnResize() {
            if (!this.colResize) return;
            const resize = this.clearColumnResize();
            if (resize.width !== null) this.setColumnWidth(resize.th, resize.key, parseFloat(resize.original) || resize.start);
        },
        endColumnResize() {
            if (!this.colResize) return;
            const resize = this.clearColumnResize();
            if (resize.width === null || resize.width === resize.start) return;
            this.setColumnWidth(resize.th, resize.key, resize.width);
            this.$wire.saveColumnWidth(resize.key, resize.width);
        },
        resetColumnWidth(handle) {
            const th = handle.closest('th');
            const width = Number(th?.dataset.defaultWidth);
            if (!th || !width) return;
            this.setColumnWidth(th, handle.dataset.colResize, width);
            this.$wire.saveColumnWidth(handle.dataset.colResize, null);
        },

        // ------------------------------------------------------------ column order (managers)

        startColumnDrag(e, grip) {
            const th = grip.closest('th[data-column-id]');
            if (!th || e.button > 0 || this.colDrag) return;
            e.preventDefault();
            grip.setPointerCapture(e.pointerId);
            const move = (ev) => this.moveColumnDrag(ev);
            const up = () => this.endColumnDrag();
            const cancel = () => this.cancelColumnDrag();
            const key = (ev) => ev.key === 'Escape' && this.cancelColumnDrag();
            grip.addEventListener('pointermove', move);
            grip.addEventListener('pointerup', up);
            grip.addEventListener('pointercancel', cancel);
            window.addEventListener('keydown', key);
            this.colDrag = {
                grip,
                th,
                id: th.dataset.columnId,
                target: null,
                before: false,
                start: e.clientX,
                pointer: { x: e.clientX, y: e.clientY },
                moved: false,
                frame: requestAnimationFrame(() => this.autoScrollColumns()),
                unbind: () => {
                    grip.removeEventListener('pointermove', move);
                    grip.removeEventListener('pointerup', up);
                    grip.removeEventListener('pointercancel', cancel);
                    window.removeEventListener('keydown', key);
                },
            };
            th.classList.add('is-col-dragging');
            this.$el.classList.add('is-moving-column');
        },
        moveColumnDrag(e) {
            if (!this.colDrag) return;
            this.colDrag.pointer = { x: e.clientX, y: e.clientY };
            if (Math.abs(e.clientX - this.colDrag.start) > 4) this.colDrag.moved = true;
            this.updateColumnTarget();
        },
        columnHeaders() {
            const root = this.$refs.grid || this.$el;
            return [...root.querySelectorAll('thead th[data-column-id]')];
        },
        /** Horizontal span where movable columns are visible (the frozen name columns cover the rest). */
        movableArea() {
            const root = this.$refs.grid || this.$el;
            const box = root.getBoundingClientRect();
            const frozen = root.querySelector('thead .sticky-3')?.getBoundingClientRect();
            const rtl = getComputedStyle(root).direction === 'rtl';
            if (!frozen) return { left: box.left, right: box.right, rtl };
            return rtl ? { left: box.left, right: frozen.left, rtl } : { left: frozen.right, right: box.right, rtl };
        },
        updateColumnTarget() {
            const drag = this.colDrag;
            if (!drag || !drag.moved) return;
            const area = this.movableArea();
            const x = Math.min(Math.max(drag.pointer.x, area.left + 1), area.right - 1);
            const headers = this.columnHeaders();
            let target = headers.find((th) => {
                const r = th.getBoundingClientRect();
                return x >= r.left && x <= r.right;
            });
            if (!target) return;
            const r = target.getBoundingClientRect();
            const firstHalf = area.rtl ? x > r.left + r.width / 2 : x < r.left + r.width / 2;
            headers.forEach((th) => th.classList.remove('drop-before', 'drop-after'));
            drag.target = target === drag.th ? null : target;
            drag.before = firstHalf;
            if (drag.target) target.classList.add(firstHalf ? 'drop-before' : 'drop-after');
        },
        autoScrollColumns() {
            const drag = this.colDrag;
            if (!drag) return;
            if (drag.moved) {
                const root = this.$refs.grid || this.$el;
                const area = this.movableArea();
                const edge = 48;
                const x = drag.pointer.x;
                let step = 0;
                if (x < area.left + edge) step = -Math.min(24, (area.left + edge - x) / 2 + 4);
                else if (x > area.right - edge) step = Math.min(24, (x - (area.right - edge)) / 2 + 4);
                if (step) {
                    root.scrollLeft += step;
                    this.updateColumnTarget();
                }
            }
            drag.frame = requestAnimationFrame(() => this.autoScrollColumns());
        },
        clearColumnDrag() {
            const drag = this.colDrag;
            cancelAnimationFrame(drag.frame);
            drag.unbind();
            drag.th.classList.remove('is-col-dragging');
            this.columnHeaders().forEach((th) => th.classList.remove('drop-before', 'drop-after'));
            this.$el.classList.remove('is-moving-column');
            this.colDrag = null;
            return drag;
        },
        cancelColumnDrag() {
            if (this.colDrag) this.clearColumnDrag();
        },
        async endColumnDrag() {
            if (!this.colDrag) return;
            const drag = this.clearColumnDrag();
            if (!drag.moved || !drag.target) return;

            const ids = this.columnHeaders().map((th) => th.dataset.columnId);
            const order = ids.filter((id) => id !== drag.id);
            const at = order.indexOf(drag.target.dataset.columnId) + (drag.before ? 0 : 1);
            order.splice(at, 0, drag.id);
            if (order.join(',') === ids.join(',')) return;

            this.notify('در حال ذخیره‌ی ترتیب ستون‌ها…');
            await this.settle(); // unsaved cells first, so the re-render cannot drop them
            this.$wire.reorderColumns(order.map(Number));
        },
        /** Waits (up to ~6s) until queued cell edits are saved. */
        async settle() {
            for (let i = 0; i < 60 && this.hasPending(); i++) {
                if (!this.saving && this.hasPendingOnly()) {
                    clearTimeout(this.timer);
                    this.flush();
                }
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        },
    }));
});
