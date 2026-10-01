/*
 * TukaHR — Excel-like sheet behaviour (Alpine component used inside the Livewire grid).
 * Loaded as a classic script in <head> so it registers before Livewire starts Alpine.
 *
 * - Saves on change (blur/Enter) in small batches via $wire.saveCells (optimistic versions).
 * - Arrow/Enter navigation (RTL aware), multi-cell paste from Excel.
 * - Fill handle: drag the small square at the corner of the active cell up or down to copy
 *   its value into that column (Ctrl+D copies the value of the cell above), like Excel.
 * - Validation before saving: a number column's range (data-min/data-max on its <th>) and
 *   number format are checked first; invalid input is never queued. The error shows under
 *   the cell; Enter keeps the cell for correction, leaving it restores the saved value.
 * - Managers reorder columns by dragging the grip in the column header.
 * - Managers select rows (Shift+click for a run of rows) to delete them or set their project.
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
        bubble: null,
        colDrag: null, // active column drag: { grip, th, id, target, before, pointer, moved }
        selected: {}, // row id → true (manager multi-select)
        lastSelected: null, // anchor row for Shift+click

        init() {
            const root = this.$refs.grid || this.$el;
            root.addEventListener('focusin', (e) => this.isCell(e.target) && this.onFocus(e.target));
            root.addEventListener('focusout', (e) => this.isCell(e.target) && this.onBlur(e.target));
            this.createHandle();
            this.createBubble();
            root.addEventListener('pointerdown', (e) => {
                const grip = e.target.closest && e.target.closest('[data-col-grip]');
                if (grip) this.startColumnDrag(e, grip);
            });
            // A Livewire re-render drops the handle (it is not in the server HTML); put it back.
            window.Livewire?.hook?.('commit', ({ succeed }) => succeed(() => requestAnimationFrame(() => {
                if (this.$el.isConnected) this.pruneSelection();
                const active = document.activeElement;
                if (this.$el.isConnected && !this.fill && this.isFillable(active)) this.placeHandle(active);
                if (this.$el.isConnected && this.isCell(active) && active.classList.contains('is-error')) {
                    this.showError(active, active.title);
                }
            })));
            root.addEventListener('change', (e) => this.isCell(e.target) && this.queue(e.target));
            root.addEventListener('keydown', (e) => this.onKey(e));
            root.addEventListener('paste', (e) => this.onPaste(e));

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
            clearInterval(this.pollTimer);
            window.removeEventListener('beforeunload', this._beforeUnload);
            this.cancelFill();
            this.cancelColumnDrag();
            this.handle?.remove();
            this.bubble?.remove();
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
            const error = this.validate(el);
            if (error) {
                this.reject(el, error);
                return false;
            }
            this.clearError(el);
            const value = this.raw(el);
            const key = this.keyOf(el);
            if (value === (el.dataset.saved ?? '')) {
                delete this.pending[key];
                el.classList.remove('is-dirty');
                return true;
            }
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
        },
        setSelected(id, on) {
            if (on) this.selected[id] = true;
            else delete this.selected[id];
        },
        toggleAll(e) {
            if (e.target.checked) this.selectableRows().forEach((id) => (this.selected[id] = true));
            else this.clearSelection();
        },
        clearSelection() {
            this.selected = {};
            this.lastSelected = null;
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
