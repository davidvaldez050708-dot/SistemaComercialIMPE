/* Configuración comercial 1A.2: mejoras de formulario sin reparto de prospectos. */
(function () {
    'use strict';

    function refrescarGrupo(grupo) {
        var todos = grupo.querySelector('[data-asesores-todos]');
        if (!todos) return;
        var items = Array.prototype.slice.call(grupo.querySelectorAll('[data-asesores-programa]'));
        var elegidos = items.filter(function (input) { return input.checked; }).length;
        todos.checked = elegidos === items.length;
        todos.indeterminate = elegidos > 0 && elegidos < items.length;
    }

    document.querySelectorAll('[data-asesores-grupo]').forEach(refrescarGrupo);

    document.addEventListener('change', function (event) {
        var todos = event.target.closest('[data-asesores-todos]');
        if (todos) {
            var grupo = todos.closest('[data-asesores-grupo]');
            if (!grupo) return;
            grupo.querySelectorAll('[data-asesores-programa]').forEach(function (input) {
                input.checked = todos.checked;
            });
            refrescarGrupo(grupo);
            return;
        }
        if (event.target.matches('[data-asesores-programa]')) {
            var padre = event.target.closest('[data-asesores-grupo]');
            if (padre) refrescarGrupo(padre);
        }
        if (event.target.matches('[data-asesores-dia-check]')) {
            var fila = event.target.closest('[data-asesores-dia]');
            if (fila) fila.classList.toggle('is-active', event.target.checked);
        }
    });

    document.querySelectorAll('[data-asesores-dia]').forEach(function (fila) {
        var check = fila.querySelector('[data-asesores-dia-check]');
        fila.classList.toggle('is-active', !!check && check.checked);
    });

    // No abrir/cerrar el acordeón al ampliar una foto. El manejador oficial
    // de AvatarHelper del footer conserva la apertura de foto y su modal.
    document.addEventListener('click', function (event) {
        if (event.target.closest('.asesores-card-head .system-avatar-photo-button')) {
            event.preventDefault();
        }
    }, true);

    document.querySelectorAll('[data-asesores-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var elegidos = form.querySelectorAll('[data-asesores-programa]:checked').length;
            if (!elegidos) {
                event.preventDefault();
                var primero = form.querySelector('[data-asesores-programa]');
                if (primero) {
                    primero.focus();
                    primero.setCustomValidity('Selecciona al menos un programa educativo.');
                    primero.reportValidity();
                    primero.setCustomValidity('');
                }
                return;
            }
            var disponible = form.querySelector('input[name="activo"]');
            if (disponible && disponible.checked &&
                !form.querySelector('[data-asesores-dia-check]:checked')) {
                event.preventDefault();
                var primerDia = form.querySelector('[data-asesores-dia-check]');
                if (primerDia) {
                    primerDia.focus();
                    primerDia.setCustomValidity('Selecciona al menos un día laboral.');
                    primerDia.reportValidity();
                    primerDia.setCustomValidity('');
                }
            }
        });
    });
}());
