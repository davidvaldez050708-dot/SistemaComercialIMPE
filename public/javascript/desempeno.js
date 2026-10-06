document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.querySelector('[data-performance-module]');
    const form = document.querySelector('[data-performance-filter-form]');

    if (!root || !form) {
        return;
    }

    const periodSelect = form.querySelector('[data-performance-period]');
    const customPeriod = form.querySelector('[data-performance-custom-period]');
    const dateFrom = form.querySelector('#performance_desde');
    const dateTo = form.querySelector('#performance_hasta');

    const submitForm = function () {
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        form.submit();
    };

    const syncCustomPeriod = function () {
        if (!periodSelect || !customPeriod) {
            return false;
        }

        const custom = periodSelect.value === 'personalizado';
        customPeriod.classList.toggle('d-none', !custom);

        if (dateFrom) {
            dateFrom.required = custom;
        }

        if (dateTo) {
            dateTo.required = custom;
        }

        return custom;
    };

    periodSelect?.addEventListener('change', function () {
        const custom = syncCustomPeriod();

        if (!custom) {
            submitForm();
        }
    });

    form.querySelectorAll('[data-performance-auto-submit]')
        .forEach(function (element) {
            element.addEventListener('change', submitForm);
        });

    syncCustomPeriod();

    const refreshEveryMs = 60000;
    let lastInteractionAt = Date.now();

    ['pointerdown', 'keydown', 'input', 'change'].forEach(function (eventName) {
        document.addEventListener(eventName, function () {
            lastInteractionAt = Date.now();
        }, { passive: true });
    });

    window.setInterval(function () {
        if (document.visibilityState !== 'visible') {
            return;
        }

        const active = document.activeElement;
        const editing =
            active instanceof HTMLInputElement ||
            active instanceof HTMLSelectElement ||
            active instanceof HTMLTextAreaElement;

        if (editing) {
            return;
        }

        if ((Date.now() - lastInteractionAt) < 15000) {
            return;
        }

        window.location.reload();
    }, refreshEveryMs);
});
