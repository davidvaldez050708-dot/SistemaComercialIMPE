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
            link.href = 'public/css/educacion_objetivo.css?v=20260928-3';
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
            return Number.isFinite(n)
                ? new Intl.NumberFormat('es-MX').format(n)
                : '—';
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
                        '<span class="data-education-target-eyebrow">Población educativa prioritaria</span>' +
                        '<h4>Perfil educativo adulto de 25 a 49 años</h4>' +
                        '<p>Consultando población adulta y el cruce oficial de edad con escolaridad, sin estimar cifras a partir de porcentajes generales.</p>' +
                    '</div>' +
                    '<span class="data-education-target-period">INEGI</span>' +
                '</div>' +
                '<div class="data-education-target-loading">' +
                    '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>' +
                    '<span>Consultando información oficial...</span>' +
                '</div>';
        };

        const renderError = function (mensaje) {
            contenedor.innerHTML =
                '<div class="data-education-target-heading">' +
                    '<div>' +
                        '<span class="data-education-target-eyebrow">Contexto educativo del territorio</span>' +
                        '<h4>Indicadores educativos</h4>' +
                        '<p>Información educativa oficial para complementar el análisis territorial.</p>' +
                    '</div>' +
                    '<span class="data-education-target-period">INEGI</span>' +
                '</div>' +
                '<div class="data-education-target-error">' +
                    '<i class="bi bi-exclamation-circle"></i>' +
                    '<span>' + escapar(mensaje || 'No fue posible consultar la información de INEGI.') + '</span>' +
                '</div>';
        };

        const gruposAdultos = function (metricas) {
            return [
                ['25–29', metricas?.poblacion_25_29],
                ['30–34', metricas?.poblacion_30_34],
                ['35–39', metricas?.poblacion_35_39],
                ['40–44', metricas?.poblacion_40_44],
                ['45–49', metricas?.poblacion_45_49]
            ];
        };

        const renderGruposAdultos = function (metricas, prioridad) {
            const gruposPrioridad = prioridad?.metricas?.grupos || {};

            return (
                '<div class="data-education-priority-age-grid">' +
                    gruposAdultos(metricas).map(function (item) {
                        const clave = item[0].replace('–', '-');
                        const grupo = gruposPrioridad?.[clave] || {};
                        const brecha = grupo.sin_estudios_media_superior ??
                            grupo.sin_media_superior_concluida;
                        const brechaPct = grupo.sin_estudios_media_superior_pct;
                        return (
                            '<div>' +
                                '<span>' + escapar(item[0]) + ' años</span>' +
                                '<strong>' + numero(item[1]) + '</strong>' +
                                '<small>' +
                                    (brecha !== null && brecha !== undefined
                                        ? numero(brecha) +
                                            ' sin estudios de media superior' +
                                            (brechaPct !== null && brechaPct !== undefined
                                                ? ' · ' + porcentaje(brechaPct)
                                                : '')
                                        : 'Población total del grupo') +
                                '</small>' +
                            '</div>'
                        );
                    }).join('') +
                '</div>'
            );
        };

        const renderMunicipiosPrioridad = function (perfilPrioritario, perfilAdulto) {
            const prioridadDisponible = perfilPrioritario?.disponible === true;
            const municipiosPrioridad = Array.isArray(perfilPrioritario?.municipios)
                ? perfilPrioritario.municipios
                : [];
            const municipiosAdultos = Array.isArray(perfilAdulto?.municipios)
                ? perfilAdulto.municipios
                : [];

            const construirLista = function (modo) {
                if (!prioridadDisponible) {
                    return municipiosAdultos
                        .filter(function (municipio) {
                            return Number(municipio?.metricas?.poblacion_25_49 || 0) > 0;
                        })
                        .sort(function (a, b) {
                            return Number(b.metricas.poblacion_25_49 || 0) -
                                Number(a.metricas.poblacion_25_49 || 0);
                        })
                        .slice(0, 6)
                        .map(function (municipio) {
                            return {
                                nombre: municipio.nombre,
                                valor: Number(municipio.metricas.poblacion_25_49 || 0),
                                porcentaje: null
                            };
                        });
                }

                return municipiosPrioridad
                    .filter(function (municipio) {
                        const m = municipio?.metricas || {};
                        return Number(m.sin_estudios_media_superior_25_49 ??
                            m.sin_media_superior_concluida_25_49 ?? 0) > 0;
                    })
                    .sort(function (a, b) {
                        const ma = a.metricas || {};
                        const mb = b.metricas || {};

                        if (modo === 'porcentaje') {
                            return Number(
                                mb.sin_estudios_media_superior_pct ??
                                mb.sin_media_superior_concluida_pct ?? 0
                            ) - Number(
                                ma.sin_estudios_media_superior_pct ??
                                ma.sin_media_superior_concluida_pct ?? 0
                            );
                        }

                        return Number(
                            mb.sin_estudios_media_superior_25_49 ??
                            mb.sin_media_superior_concluida_25_49 ?? 0
                        ) - Number(
                            ma.sin_estudios_media_superior_25_49 ??
                            ma.sin_media_superior_concluida_25_49 ?? 0
                        );
                    })
                    .slice(0, 6)
                    .map(function (municipio) {
                        const m = municipio.metricas || {};
                        return {
                            nombre: municipio.nombre,
                            valor: Number(
                                m.sin_estudios_media_superior_25_49 ??
                                m.sin_media_superior_concluida_25_49 ?? 0
                            ),
                            porcentaje: Number(
                                m.sin_estudios_media_superior_pct ??
                                m.sin_media_superior_concluida_pct ?? 0
                            )
                        };
                    });
            };

            const renderLista = function (lista, modo) {
                if (!lista.length) {
                    return '<div class="data-education-priority-ranking-empty">Sin datos suficientes.</div>';
                }

                const maximo = Math.max.apply(null, lista.map(function (item) {
                    return modo === 'porcentaje' ? item.porcentaje : item.valor;
                }));

                return (
                    '<div class="data-education-target-municipal-list">' +
                        lista.map(function (item) {
                            const medida = modo === 'porcentaje'
                                ? item.porcentaje
                                : item.valor;
                            const ancho = maximo > 0
                                ? Math.max(2, (medida / maximo) * 100)
                                : 0;

                            return (
                                '<div class="data-education-target-municipal-row">' +
                                    '<strong title="' + escapar(item.nombre || '') + '">' +
                                        escapar(item.nombre || 'Municipio') +
                                    '</strong>' +
                                    '<div class="data-education-target-bar" aria-hidden="true">' +
                                        '<span style="width:' + ancho.toFixed(2) + '%"></span>' +
                                    '</div>' +
                                    '<span>' +
                                        (modo === 'porcentaje'
                                            ? porcentaje(item.porcentaje)
                                            : numero(item.valor) +
                                                (item.porcentaje !== null
                                                    ? ' · ' + porcentaje(item.porcentaje)
                                                    : '')) +
                                    '</span>' +
                                '</div>'
                            );
                        }).join('') +
                    '</div>'
                );
            };

            const volumen = construirLista('volumen');
            const proporcion = prioridadDisponible
                ? construirLista('porcentaje')
                : [];

            if (!volumen.length) {
                return '';
            }

            return (
                '<div class="data-education-target-municipal data-education-priority-municipal">' +
                    '<div class="data-education-target-municipal-heading">' +
                        '<strong>' +
                            (prioridadDisponible
                                ? 'Prioridad municipal de población educativa'
                                : 'Municipios con mayor población de 25 a 49 años') +
                        '</strong>' +
                        '<span>' +
                            (prioridadDisponible
                                ? 'Volumen e incidencia son criterios distintos'
                                : 'Contexto adulto · no sustituye el cruce educativo') +
                        '</span>' +
                    '</div>' +
                    (prioridadDisponible
                        ? '<div class="data-education-priority-ranking-grid">' +
                            '<section>' +
                                '<div class="data-education-priority-ranking-title">' +
                                    '<strong>Mayor volumen</strong>' +
                                    '<span>Personas sin estudios de media superior</span>' +
                                '</div>' +
                                renderLista(volumen, 'volumen') +
                            '</section>' +
                            '<section>' +
                                '<div class="data-education-priority-ranking-title">' +
                                    '<strong>Mayor proporción</strong>' +
                                    '<span>% dentro de la población de 25 a 49 años</span>' +
                                '</div>' +
                                renderLista(proporcion, 'porcentaje') +
                            '</section>' +
                          '</div>'
                        : renderLista(volumen, 'volumen')) +
                '</div>'
            );
        };

        const renderPrioridad = function (datos) {
            const perfilAdulto = datos?.perfil_adulto || {};
            const adultoEstado = perfilAdulto?.estado || {};
            const adultoMetricas = adultoEstado?.metricas || {};
            const perfilPrioritario = datos?.perfil_educativo_prioritario || {};
            const prioridadEstado = perfilPrioritario?.estado || {};
            const prioridadMetricas = prioridadEstado?.metricas || {};
            const prioridadDisponible = perfilPrioritario?.disponible === true;
            const meta = perfilPrioritario?.meta || {};
            const poblacion2549 = adultoMetricas.poblacion_25_49 ??
                prioridadMetricas.poblacion_25_49;

            if (!perfilAdulto?.ok && !prioridadDisponible) {
                return '';
            }

            return (
                '<div class="data-education-priority">' +
                    '<div class="data-education-priority-heading">' +
                        '<div>' +
                            '<span class="data-education-target-eyebrow">Población educativa prioritaria</span>' +
                            '<h4>Adultos de 25 a 49 años</h4>' +
                            '<p>Rango estadístico construido con los grupos quinquenales oficiales 25–29, 30–34, 35–39, 40–44 y 45–49. Los segmentos educativos son mutuamente excluyentes para facilitar la priorización territorial.</p>' +
                        '</div>' +
                        '<span class="data-education-target-period">Censo 2020</span>' +
                    '</div>' +

                    '<div class="data-education-priority-cards is-segmented">' +
                        '<article class="data-education-priority-card is-focus">' +
                            '<span>Sin estudios de media superior</span>' +
                            '<strong>' +
                                (prioridadDisponible
                                    ? numero(
                                        prioridadMetricas.sin_estudios_media_superior_25_49 ??
                                        prioridadMetricas.sin_media_superior_concluida_25_49
                                      )
                                    : 'Pendiente') +
                            '</strong>' +
                            '<b>' +
                                (prioridadDisponible
                                    ? porcentaje(
                                        prioridadMetricas.sin_estudios_media_superior_pct ??
                                        prioridadMetricas.sin_media_superior_concluida_pct
                                      ) + ' del grupo de 25 a 49'
                                    : 'Requiere cruce oficial edad × escolaridad') +
                            '</b>' +
                            '<small>Personas sin grados aprobados de educación media superior. Es el segmento de mayor brecha educativa.</small>' +
                        '</article>' +
                        '<article class="data-education-priority-card">' +
                            '<span>Con media superior, sin educación superior</span>' +
                            '<strong>' +
                                (prioridadDisponible
                                    ? numero(prioridadMetricas.media_superior_sin_superior_25_49)
                                    : 'Pendiente') +
                            '</strong>' +
                            '<b>' +
                                (prioridadDisponible
                                    ? porcentaje(prioridadMetricas.media_superior_sin_superior_pct) + ' del grupo de 25 a 49'
                                    : 'Requiere cruce oficial edad × escolaridad') +
                            '</b>' +
                            '<small>Segmento diferenciado: ya cuenta con estudios de media superior, pero no con educación superior.</small>' +
                        '</article>' +
                        '<article class="data-education-priority-card is-positive">' +
                            '<span>Con educación superior</span>' +
                            '<strong>' +
                                (prioridadDisponible
                                    ? numero(prioridadMetricas.con_educacion_superior_25_49)
                                    : 'Pendiente') +
                            '</strong>' +
                            '<b>' +
                                (prioridadDisponible
                                    ? porcentaje(prioridadMetricas.con_educacion_superior_pct) + ' del grupo de 25 a 49'
                                    : 'Requiere cruce oficial edad × escolaridad') +
                            '</b>' +
                            '<small>Complemento del universo 25–49 con algún nivel de educación superior registrado.</small>' +
                        '</article>' +
                    '</div>' +
                    '<div class="data-education-priority-universe">' +
                        '<span>Universo total 25–49</span>' +
                        '<strong>' + numero(poblacion2549) + '</strong>' +
                        '<small>Los tres segmentos anteriores no se superponen y suman este universo de referencia.</small>' +
                    '</div>' +

                    renderGruposAdultos(adultoMetricas, prioridadEstado) +

                    (!prioridadDisponible
                        ? '<div class="data-education-priority-method">' +
                            '<i class="bi bi-shield-check"></i>' +
                            '<div><strong>Dato educativo exacto protegido</strong>' +
                            '<span>' +
                                escapar(
                                    perfilPrioritario?.actualizacion_automatica?.mensaje ||
                                    'El sistema busca automáticamente una fuente oficial compatible de INEGI y no extrapola porcentajes generales cuando el cruce edad × escolaridad no está disponible.'
                                ) +
                            '</span></div>' +
                          '</div>'
                        : '') +

                    renderMunicipiosPrioridad(perfilPrioritario, perfilAdulto) +

                    (prioridadDisponible
                        ? '<div class="data-education-priority-source">' +
                            '<span>Fuente: <strong>' + escapar(meta.fuente || 'INEGI - Censo de Población y Vivienda 2020') + '</strong></span>' +
                            '<span>Tabulado: <strong>' + escapar(meta.referencia_fuente || 'B2020_07_08_M') + '</strong></span>' +
                            '<span>Metodología: <strong>Cruce directo edad × escolaridad</strong></span>' +
                          '</div>'
                        : '') +
                '</div>'
            );
        };

        const renderMunicipiosJuveniles = function (municipios) {
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
                        '<strong>Contexto municipal: población de 15 a 24 años no registrada como asistente</strong>' +
                        '<span>Referencia · no define la prioridad</span>' +
                    '</div>' +
                    '<div class="data-education-target-municipal-list">' +
                        lista.map(function (municipio) {
                            const valor = Number(municipio.metricas.fuera_15_24 || 0);
                            const ancho = maximo > 0
                                ? Math.max(2, (valor / maximo) * 100)
                                : 0;
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

        const renderContexto = function (datos) {
            const estado = datos?.estado || {};
            const m = estado.metricas || {};

            return (
                '<div class="data-education-context-general">' +
                    '<div class="data-education-target-heading">' +
                        '<div>' +
                            '<span class="data-education-target-eyebrow">Contexto educativo general</span>' +
                            '<h4>Indicadores educativos de contexto</h4>' +
                            '<p>Estos indicadores describen el entorno educativo del estado. Se mantienen separados de la población objetivo adulta para evitar interpretaciones incorrectas.</p>' +
                        '</div>' +
                        '<span class="data-education-target-period">Censo ' + escapar(datos.periodo || '2020') + '</span>' +
                    '</div>' +

                    '<div class="data-education-target-summary">' +
                        '<article class="data-education-target-card is-primary">' +
                            '<span>Población de 15 años y más con secundaria completa</span>' +
                            '<strong>' + numero(m.secundaria_completa) + '</strong>' +
                            '<b>' + porcentaje(m.secundaria_completa_pct) + ' de la población de 15 años y más</b>' +
                            '<small>Variable oficial de contexto. No significa “máxima escolaridad” ni equivale a población objetivo.</small>' +
                        '</article>' +
                        '<article class="data-education-target-card">' +
                            '<span>Población de 18 años y más con educación posbásica</span>' +
                            '<strong>' + numero(m.educacion_posbasica_18_mas) + '</strong>' +
                            '<b>Contexto de continuidad educativa</b>' +
                            '<small>Su universo de edad es 18+; no se usa para estimar la brecha educativa de 25–49.</small>' +
                        '</article>' +
                        '<article class="data-education-target-card">' +
                            '<span>Grado promedio de escolaridad</span>' +
                            '<strong>' + decimal(m.grado_promedio_escolaridad) + ' años</strong>' +
                            '<b>Promedio estatal registrado</b>' +
                            '<small>Describe el contexto general y no modifica por sí solo la prioridad municipal.</small>' +
                        '</article>' +
                    '</div>' +

                    '<details class="data-education-youth-context">' +
                        '<summary><span><strong>Contexto educativo juvenil</strong><small>Indicadores de 15 a 24 años · referencia complementaria</small></span><i class="bi bi-chevron-down"></i></summary>' +
                        '<div class="data-education-youth-context-body">' +
                            '<div><span>15 a 17 años fuera de la escuela</span><strong>' + numero(m.fuera_15_17) + '</strong><small>' + porcentaje(m.fuera_15_17_pct) + ' del grupo de edad</small></div>' +
                            '<div><span>18 a 24 años fuera de la escuela</span><strong>' + numero(m.fuera_18_24) + '</strong><small>' + porcentaje(m.fuera_18_24_pct) + ' del grupo de edad</small></div>' +
                            '<div><span>15 a 24 años no registrados como asistentes</span><strong>' + numero(m.fuera_15_24) + '</strong><small>' + porcentaje(m.fuera_15_24_pct) + ' del grupo de edad</small></div>' +
                        '</div>' +
                        '<p>Estos indicadores se conservan como contexto juvenil y no se suman con la población adulta prioritaria.</p>' +
                    '</details>' +

                    '<details class="data-education-youth-municipal">' +
                        '<summary><span><strong>Detalle municipal del contexto juvenil</strong><small>Municipios con mayor población de 15 a 24 años no registrada como asistente</small></span><i class="bi bi-chevron-down"></i></summary>' +
                        '<div class="data-education-youth-municipal-body">' +
                            renderMunicipiosJuveniles(datos.municipios || []) +
                        '</div>' +
                    '</details>' +

                    '<p class="data-education-target-note">' +
                        '<strong>Importante:</strong> edad, asistencia escolar y nivel de escolaridad son dimensiones distintas. ' +
                        'No se suman ni se extrapolan entre universos de edad. La población prioritaria de 25–49 se calcula únicamente con el cruce oficial correspondiente.' +
                    '</p>' +
                    '<div class="data-education-target-source">' +
                        '<span>Fuente: <strong>' + escapar(datos.fuente || 'INEGI') + '</strong></span>' +
                        '<span>Periodo: <strong>' + escapar(datos.periodo || '2020') + '</strong></span>' +
                        '<span>Tipo: <strong>Consulta oficial</strong></span>' +
                    '</div>' +
                '</div>'
            );
        };

        const render = function (datos) {
            contenedor.innerHTML =
                renderPrioridad(datos) +
                renderContexto(datos);
        };

        const cargar = async function () {
            renderCarga();

            try {
                const respuesta = await fetch(
                    'public/inegi_educacion_objetivo.php?estado_id=' +
                        encodeURIComponent(estadoId),
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
                    throw new Error(
                        'La respuesta de INEGI no pudo interpretarse correctamente.'
                    );
                }

                if (!respuesta.ok || !datos?.ok) {
                    throw new Error(
                        datos?.mensaje ||
                        'No fue posible consultar la información de INEGI.'
                    );
                }

                render(datos);
            } catch (error) {
                renderError(
                    error.message ||
                    'No fue posible consultar la información de INEGI.'
                );
            }
        };

        cargar();
    });
})();
