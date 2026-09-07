(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const seccionEducacion = document.getElementById('educacion');
        const rezago = seccionEducacion?.querySelector('.data-education-official');

        if (!seccionEducacion || !rezago) {
            return;
        }

        const params = new URLSearchParams(window.location.search);
        const estadoId = Number(params.get('estado_id') || 0);

        if (!estadoId) {
            return;
        }

        if (!document.querySelector('link[data-education-target-style]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'public/css/educacion_objetivo.css';
            link.setAttribute('data-education-target-style', '');
            document.head.appendChild(link);
        }

        let contenedor = seccionEducacion.querySelector('[data-education-target]');

        if (!contenedor) {
            contenedor = document.createElement('section');
            contenedor.className = 'data-education-target';
            contenedor.setAttribute('data-education-target', '');
            rezago.insertAdjacentElement('afterend', contenedor);
        }

        const numero = function (valor) {
            const n = Number(valor);
            return Number.isFinite(n) ? new Intl.NumberFormat('es-MX').format(n) : '—';
        };

        const porcentaje = function (valor) {
            const n = Number(valor);
            return Number.isFinite(n) ? n.toFixed(2) + ' %' : '—';
        };

        const decimal = function (valor) {
            const n = Number(valor);
            return Number.isFinite(n) ? n.toFixed(2) : '—';
        };

        const escapar = function (valor) {
            const div = document.createElement('div');
            div.textContent = String(valor ?? '');
            return div.innerHTML;
        };

        const renderCarga = function () {
            contenedor.innerHTML =
                '<div class="data-education-target-heading">' +
                    '<div>' +
                        '<span class="data-education-target-eyebrow">Población objetivo educativa</span>' +
                        '<h4>Perfil educativo relacionado con la oferta académica</h4>' +
                        '<p>Separando la población de educación básica del grupo que puede ser más relevante para bachillerato y continuidad educativa.</p>' +
                    '</div>' +
                    '<span class="data-education-target-period">2020</span>' +
                '</div>' +
                '<div class="data-education-target-loading">' +
                    '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                    '<span>Consultando el Censo 2020 de INEGI...</span>' +
                '</div>';
        };

        const renderError = function (mensaje) {
            contenedor.innerHTML =
                '<div class="data-education-target-heading">' +
                    '<div>' +
                        '<span class="data-education-target-eyebrow">Población objetivo educativa</span>' +
                        '<h4>Perfil educativo relacionado con la oferta académica</h4>' +
                        '<p>Indicadores del Censo 2020 enfocados en edades y escolaridad relevantes para bachillerato y continuidad educativa.</p>' +
                    '</div>' +
                    '<span class="data-education-target-period">2020</span>' +
                '</div>' +
                '<div class="data-education-target-error">' +
                    '<i class="bi bi-exclamation-circle"></i>' +
                    '<span>' + escapar(mensaje || 'No fue posible consultar la información de INEGI.') + '</span>' +
                '</div>';
        };

        const renderMunicipios = function (municipios) {
            const lista = Array.isArray(municipios)
                ? municipios.filter(function (municipio) {
                    return Number(municipio?.metricas?.fuera_15_24 || 0) > 0;
                }).slice(0, 6)
                : [];

            if (!lista.length) {
                return '';
            }

            const maximo = Math.max.apply(null, lista.map(function (municipio) {
                return Number(municipio.metricas.fuera_15_24 || 0);
            }));

            return (
                '<div class="data-education-target-municipal">' +
                    '<div class="data-education-target-municipal-heading">' +
                        '<strong>Municipios con mayor población de 15 a 24 años no registrada como asistente</strong>' +
                        '<span>Top 6 del territorio</span>' +
                    '</div>' +
                    '<div class="data-education-target-municipal-list">' +
                        lista.map(function (municipio) {
                            const valor = Number(municipio.metricas.fuera_15_24 || 0);
                            const ancho = maximo > 0 ? Math.max(2, (valor / maximo) * 100) : 0;
                            return (
                                '<div class="data-education-target-municipal-row">' +
                                    '<strong title="' + escapar(municipio.nombre || '') + '">' +
                                        escapar(municipio.nombre || 'Municipio') +
                                    '</strong>' +
                                    '<div class="data-education-target-bar" aria-hidden="true">' +
                                        '<span style="width:' + ancho.toFixed(2) + '%"></span>' +
                                    '</div>' +
                                    '<span>' + numero(valor) + ' personas</span>' +
                                '</div>'
                            );
                        }).join('') +
                    '</div>' +
                '</div>'
            );
        };

        const render = function (datos) {
            const estado = datos?.estado || {};
            const m = estado.metricas || {};

            contenedor.innerHTML =
                '<div class="data-education-target-heading">' +
                    '<div>' +
                        '<span class="data-education-target-eyebrow">Población objetivo educativa</span>' +
                        '<h4>Perfil educativo relacionado con la oferta académica</h4>' +
                        '<p>Este bloque separa el rezago educativo general y se concentra en escolaridad y edades más cercanas a bachillerato y continuidad de estudios.</p>' +
                    '</div>' +
                    '<span class="data-education-target-period">Censo ' + escapar(datos.periodo || '2020') + '</span>' +
                '</div>' +

                '<div class="data-education-target-summary">' +
                    '<article class="data-education-target-card is-primary">' +
                        '<span>Secundaria como máxima escolaridad</span>' +
                        '<strong>' + numero(m.secundaria_completa) + '</strong>' +
                        '<b>' + porcentaje(m.secundaria_completa_pct) + ' de la población de 15 años y más</b>' +
                        '<small>Es el grupo cuya máxima escolaridad registrada son 3 grados aprobados de secundaria. Sirve como referencia directa para dimensionar el mercado potencial de bachillerato.</small>' +
                    '</article>' +
                    '<article class="data-education-target-card">' +
                        '<span>15 a 17 años fuera de la escuela</span>' +
                        '<strong>' + numero(m.fuera_15_17) + '</strong>' +
                        '<b>' + porcentaje(m.fuera_15_17_pct) + ' del grupo de edad</b>' +
                        '<small>Calculado como población de 15 a 17 años menos quienes reportaron asistir a la escuela.</small>' +
                    '</article>' +
                    '<article class="data-education-target-card">' +
                        '<span>18 a 24 años fuera de la escuela</span>' +
                        '<strong>' + numero(m.fuera_18_24) + '</strong>' +
                        '<b>' + porcentaje(m.fuera_18_24_pct) + ' del grupo de edad</b>' +
                        '<small>Ayuda a dimensionar jóvenes que ya no aparecen dentro de la asistencia escolar formal.</small>' +
                    '</article>' +
                '</div>' +

                '<div class="data-education-target-band">' +
                    '<div class="data-education-target-band-copy">' +
                        '<span>Resumen de oportunidad educativa</span>' +
                        '<strong>Población de 15 a 24 años no registrada como asistente a la escuela</strong>' +
                    '</div>' +
                    '<div class="data-education-target-band-value">' +
                        '<strong>' + numero(m.fuera_15_24) + '</strong>' +
                        '<span>' + porcentaje(m.fuera_15_24_pct) + ' del grupo de 15 a 24 años</span>' +
                    '</div>' +
                '</div>' +

                '<div class="data-education-target-context">' +
                    '<div>' +
                        '<span>Población de 18 años y más con educación posbásica</span>' +
                        '<strong>' + numero(m.educacion_posbasica_18_mas) + '</strong>' +
                    '</div>' +
                    '<div>' +
                        '<span>Grado promedio de escolaridad</span>' +
                        '<strong>' + decimal(m.grado_promedio_escolaridad) + ' años</strong>' +
                    '</div>' +
                '</div>' +

                renderMunicipios(datos.municipios || []) +

                '<p class="data-education-target-note">' +
                    '<strong>Importante:</strong> secundaria como máxima escolaridad y no asistencia escolar son indicadores distintos. ' +
                    'No deben sumarse ni asumirse como las mismas personas. Para identificar exactamente “secundaria terminada + no estudia” se requiere cruzar microdatos o el cubo censal de INEGI.' +
                '</p>' +
                '<div class="data-education-target-source">' +
                    '<span>Fuente: <strong>' + escapar(datos.fuente || 'INEGI') + '</strong></span>' +
                    '<span>Periodo: <strong>' + escapar(datos.periodo || '2020') + '</strong></span>' +
                    '<span>Tipo: <strong>Consulta oficial</strong></span>' +
                '</div>';
        };

        const cargar = async function () {
            renderCarga();

            try {
                const respuesta = await fetch(
                    'public/inegi_educacion_objetivo.php?estado_id=' + encodeURIComponent(estadoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store'
                    }
                );

                const texto = await respuesta.text();
                let datos = null;

                try {
                    datos = JSON.parse(texto);
                } catch (error) {
                    throw new Error('La respuesta de INEGI no pudo interpretarse correctamente.');
                }

                if (!respuesta.ok || !datos?.ok) {
                    throw new Error(datos?.mensaje || 'No fue posible consultar la información de INEGI.');
                }

                render(datos);
            } catch (error) {
                renderError(error.message || 'No fue posible consultar la información de INEGI.');
            }
        };

        cargar();
    });
})();
