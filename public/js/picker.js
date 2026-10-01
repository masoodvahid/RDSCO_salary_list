/*
 * Project picker: a dropdown with search, single choice (calls a Livewire action) or
 * multiple choice (checkboxes bound with wire:model). Markup: resources/views/components/project-picker.blade.php
 */
document.addEventListener('alpine:init', () => {
    const fold = (s) => String(s ?? '').toLowerCase().replace(/[\s‌]/g, '').replace(/ي/g, 'ی').replace(/ك/g, 'ک');

    window.Alpine.data('projectPicker', (labels = {}, placeholder = '') => ({
        open: false,
        q: '',
        labels,

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.open = true;
            this.q = '';
            this.$nextTick(() => {
                const first = this.$refs.search || this.$refs.panel?.querySelector('input, button');
                first?.focus();
            });
        },

        close(focusTrigger = false) {
            if (!this.open) return;
            this.open = false;
            if (focusTrigger) this.$refs.trigger?.focus();
        },

        matches(label) {
            return this.q === '' || fold(label).includes(fold(this.q));
        },

        get noMatch() {
            return this.q !== '' && !Object.values(this.labels).some((label) => this.matches(label));
        },

        /** Values of the options the search currently shows. */
        visible() {
            return Object.keys(this.labels).filter((value) => this.matches(this.labels[value]));
        },

        /** Trigger text for a multiple picker: "A، B" or "A و ۳ پروژه‌ی دیگر". */
        summary(values) {
            const names = (values || []).map((v) => this.labels[String(v)]).filter(Boolean);
            if (!names.length) return placeholder;
            if (names.length <= 2) return names.join('، ');
            return names[0] + ' و ' + (names.length - 1).toLocaleString('fa-IR') + ' پروژه‌ی دیگر';
        },

        /** Adds the shown options to the current selection. */
        withVisible(values) {
            return [...new Set([...(values || []).map(String), ...this.visible()])];
        },

        /** Moves focus between options with the arrow keys. */
        move(step) {
            const items = [...(this.$refs.panel?.querySelectorAll('[data-option]') || [])].filter((el) => el.offsetParent !== null);
            if (!items.length) return;
            const focusable = (el) => el.matches('input, button') ? el : el.querySelector('input, button');
            const index = items.findIndex((el) => el.contains(document.activeElement));
            const next = items[(index + step + items.length) % items.length];
            focusable(next)?.focus();
        },
    }));
});
