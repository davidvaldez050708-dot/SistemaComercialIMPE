(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const tablero = document.querySelector('[data-analyst-dashboard]');
        if (!tablero || Number(window.IMPE_CURRENT_ROLE_ID || 0) !== 4) {
            return;
        }

        const estado = tablero.querySelector('.analyst-welcome-status');
        if (estado) {
            const titulo = estado.querySelector('strong');
            const detalle = estado.querySelector('div > span');
            const texto = String(titulo?.textContent || '').trim().toLowerCase();

            if (detalle && texto.includes('requiere')) {
                detalle.textContent = 'Pendientes ordenados por fecha y nivel de atención.';
            }
        }

        const progreso = tablero.querySelector('.analyst-progress-panel');
        const descripcionProgreso = progreso?.querySelector('.analyst-progress-heading p');
        if (descripcionProgreso) {
            const mes = new Intl.DateTimeFormat('es-MX', { month: 'long' }).format(new Date());
            descripcionProgreso.textContent = 'Resultados registrados durante ' + mes + '.';
        }

        const totalInteracciones = progreso?.querySelector('.analyst-interaction-total');
        if (totalInteracciones) {
            const numero = String(totalInteracciones.querySelector('strong')?.textContent || '').trim();
            if (numero !== '') {
                totalInteracciones.innerHTML = '<strong>' + numero + '</strong> interacciones';
            }
        }

        tablero.querySelectorAll('.analyst-activity-item').forEach(function (item) {
            const canal = item.querySelector('.analyst-activity-channel');
            const resultado = item.querySelector('.analyst-activity-result');
            if (!canal) {
                return;
            }

            const canalTexto = String(canal.textContent || '').trim().toLowerCase();
            const resultadoTexto = String(resultado?.textContent || '').trim().toLowerCase();

            if (canalTexto === 'actualización') {
                canal.textContent = 'Seguimiento';
                if (resultado && (resultadoTexto === 'resultado registrado' || resultadoTexto === '')) {
                    resultado.textContent = 'Cambio registrado';
                }
                return;
            }

            if (canalTexto === 'correo' && resultado && resultadoTexto === 'resultado registrado') {
                resultado.textContent = 'Correo registrado';
            }
        });
    });
})();
