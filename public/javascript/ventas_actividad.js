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
        update();
    });
});
