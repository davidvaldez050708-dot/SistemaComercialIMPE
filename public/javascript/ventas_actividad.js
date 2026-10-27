document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-sales-activity-form]').forEach(function (form) {
        const period = form.querySelector('[data-sales-period]');
        const custom = form.querySelectorAll('[data-sales-custom]');
        function update() {
            custom.forEach(function (block) {
                const enabled = period.value === 'personalizado';
                block.hidden = !enabled;
                block.querySelectorAll('input').forEach(function (input) {
                    input.disabled = !enabled;
                    input.required = enabled;
                });
            });
        }
        period.addEventListener('change', update);
        function markDirty() {
            document.querySelectorAll('[data-sales-report-pdf]').forEach(function (link) { link.hidden = true; });
            const note = form.parentElement.querySelector('[data-sales-dirty-note]');
            if (note) note.hidden = false;
        }
        form.addEventListener('change', markDirty);
        form.addEventListener('input', markDirty);
        update();
    });
});
