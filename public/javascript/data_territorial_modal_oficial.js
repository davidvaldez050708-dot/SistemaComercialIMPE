/*
 * Información Territorial · actualizaciones oficiales.
 * Organización progresiva de las opciones ya renderizadas por PHP.
 * No agrega, elimina ni cambia acciones de actualización: las casillas
 * siguen siendo las originales, incluso si producción tiene fuentes extra.
 */
(function () {
    'use strict';

    const grupos = [
        { id: 'territorio', nombre: 'Territorio' },
        { id: 'economia', nombre: 'Economía' },
        { id: 'educacion', nombre: 'Educación' },
        { id: 'importaciones', nombre: 'Importaciones' },
        { id: 'otras', nombre: 'Otras fuentes' }
    ];

    const clasificarTipo = function (tipo, nombre) {
        const categorias = {
            poblacion: 'territorio',
            municipios: 'territorio',
            actividad: 'economia',
            actividad_municipal: 'economia',
            escolaridad_adulta: 'educacion',
            contexto_educativo_general: 'educacion',
            perfil_educativo_prioritario: 'educacion',
            perfil_adulto_laboral: 'educacion',
            perfil_educativo_general_15: 'educacion',
            perfil_educativo_general_15_mas: 'educacion',
            perfil_educativo_general_15mas: 'educacion',
            poder_adquisitivo: 'importaciones',
            rezago_educativo: 'importaciones'
        };

        const clave = String(tipo || '').trim().toLowerCase();
        if (Object.prototype.hasOwnProperty.call(categorias, clave)) {
            return categorias[clave];
        }

        // Compatibilidad con opciones existentes únicamente en producción.
        const etiqueta = String(nombre || '').toLowerCase();
        if (/educativ|escolaridad|secundaria|rezago/.test(clave + ' ' + etiqueta)) {
            return 'educacion';
        }
        if (/econ[oó]mic|denue|ingreso|adquisitiv/.test(clave + ' ' + etiqueta)) {
            return 'economia';
        }
        if (/municip|poblaci[oó]n/.test(clave + ' ' + etiqueta)) {
            return 'territorio';
        }
        return 'otras';
    };

    const instalar = function (idModal) {
        const modal = document.getElementById(idModal);
        if (!modal) {
            return;
        }

        const lista = modal.querySelector('.data-official-option-list[data-official-options]');
        if (!lista || lista.dataset.officialCategorized === '1') {
            return;
        }

        const opciones = Array.from(lista.querySelectorAll('.data-official-option-card'))
            .map(function (tarjeta) {
                const casilla = tarjeta.querySelector('input[data-official-option]');
                if (!casilla) {
                    return null;
                }

                return {
                    tarjeta: tarjeta,
                    casilla: casilla,
                    grupo: clasificarTipo(
                        casilla.value,
                        tarjeta.querySelector('strong')?.textContent || ''
                    )
                };
            })
            .filter(Boolean);

        if (!opciones.length) {
            return;
        }

        const disponibles = grupos.filter(function (grupo) {
            return opciones.some(function (opcion) {
                return opcion.grupo === grupo.id;
            });
        });

        const navegacion = document.createElement('nav');
        navegacion.className = 'official-update-categories';
        navegacion.setAttribute('aria-label', 'Categorías de información oficial');

        const indicadores = document.createElement('p');
        indicadores.className = 'official-update-selection-status';
        indicadores.setAttribute('role', 'status');
        indicadores.setAttribute('aria-live', 'polite');

        const botones = new Map();
        let activo = disponibles.some(function (grupo) {
            return grupo.id === 'territorio';
        }) ? 'territorio' : disponibles[0].id;

        disponibles.forEach(function (grupo) {
            const boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'official-update-category';
            boton.dataset.officialCategory = grupo.id;

            const texto = document.createElement('span');
            texto.textContent = grupo.nombre;
            const contador = document.createElement('span');
            contador.className = 'official-update-category-count';
            contador.setAttribute('aria-label', 'opciones seleccionadas');

            boton.append(texto, contador);
            boton.addEventListener('click', function () {
                activo = grupo.id;
                refrescar();
            });
            botones.set(grupo.id, { boton: boton, contador: contador });
            navegacion.appendChild(boton);
        });

        const refrescar = function () {
            const seleccionadas = opciones.filter(function (opcion) {
                return opcion.casilla.checked;
            }).length;
            const porCategoria = new Map();
            disponibles.forEach(function (grupo) {
                porCategoria.set(grupo.id, 0);
            });

            opciones.forEach(function (opcion) {
                opcion.tarjeta.hidden = opcion.grupo !== activo;
                if (opcion.casilla.checked) {
                    porCategoria.set(
                        opcion.grupo,
                        (porCategoria.get(opcion.grupo) || 0) + 1
                    );
                }
            });

            disponibles.forEach(function (grupo) {
                const elementos = botones.get(grupo.id);
                const cantidad = porCategoria.get(grupo.id) || 0;
                elementos.boton.setAttribute(
                    'aria-pressed',
                    String(grupo.id === activo)
                );
                elementos.contador.textContent = String(cantidad);
                elementos.contador.classList.toggle('has-selected', cantidad > 0);
            });

            indicadores.textContent = seleccionadas + ' de ' +
                opciones.length + ' opciones seleccionadas. ' +
                'Las selecciones se conservan al cambiar de categoría.';
        };

        opciones.forEach(function (opcion) {
            opcion.casilla.addEventListener('change', refrescar);
        });

        lista.insertAdjacentElement('beforebegin', navegacion);
        navegacion.insertAdjacentElement('afterend', indicadores);
        lista.classList.add('is-official-categorized');
        lista.dataset.officialCategorized = '1';

        // Mostrar la cifra real incluso si Hostinger contiene una opción
        // adicional todavía no incorporada al código principal de GitHub.
        const opcionesDisponibles = modal.querySelector(
            '.data-official-scope-grid > div:nth-child(2) strong'
        );
        if (opcionesDisponibles) {
            opcionesDisponibles.textContent = String(opciones.length);
        }

        refrescar();
    };

    const iniciar = function () {
        instalar('modalActualizarInformacionEstados');
        instalar('modalActualizarInformacionOficial');
    };

    // Ayudantes puros para pruebas unitarias fuera del navegador.
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { clasificarTipo: clasificarTipo };
    }

    if (typeof document !== 'undefined') {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', iniciar, { once: true });
        } else {
            iniciar();
        }
    }
})();
