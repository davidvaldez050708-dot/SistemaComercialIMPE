(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const seccionEducacion = document.getElementById('educacion');
        const rezago = seccionEducacion?.querySelector('.data-education-official');

        if (!seccionEducacion || !rezago) {
            return;
        }

        // Evita que el bloque oficial aparezca completo mientras carga
        // la población prioritaria. Se recoloca enseguida dentro del
        // contexto adicional, cerrado por defecto.
        rezago.style.display = 'none';

        let consultaEducativaTerminada = false;
        let perfilEconomico = {
            estado: '',
            sectores: [],
            sectores_sobrerrepresentados: []
        };

        try {
            const nodoPerfil = seccionEducacion.querySelector(
                '[data-education-economic-profile]'
            );
            const perfilLeido = nodoPerfil
                ? JSON.parse(nodoPerfil.textContent || '{}')
                : {};

            perfilEconomico = {
                estado: String(perfilLeido?.estado || ''),
                sectores: Array.isArray(perfilLeido?.sectores)
                    ? perfilLeido.sectores
                    : [],
                sectores_sobrerrepresentados:
                    Array.isArray(perfilLeido?.sectores_sobrerrepresentados)
                        ? perfilLeido.sectores_sobrerrepresentados
                        : []
            };
        } catch (error) {
            perfilEconomico = {
                estado: '',
                sectores: [],
                sectores_sobrerrepresentados: []
            };
        }

        const params = new URLSearchParams(window.location.search);
        const estadoId = Number(params.get('estado_id') || 0);

        if (!estadoId) {
            return;
        }

        if (!document.querySelector('link[data-education-target-style]')) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = 'public/css/educacion_objetivo.css?v=20260929-7';
            link.setAttribute('data-education-target-style', '');
            document.head.appendChild(link);
        }

        let contenedor = seccionEducacion.querySelector('[data-education-target]');

        if (!contenedor) {
            contenedor = document.createElement('section');
            contenedor.className = 'data-education-target';
            contenedor.setAttribute('data-education-target', '');
            rezago.insertAdjacentElement('beforebegin', contenedor);
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

        const asegurarContextoAdicional = function () {
            let contexto = seccionEducacion.querySelector(
                '[data-education-complementary-context]'
            );

            if (!contexto) {
                contexto = document.createElement('details');
                contexto.className = 'data-education-complementary-context';
                contexto.setAttribute('data-education-complementary-context', '');
                contexto.innerHTML =
                    '<summary>' +
                        '<span>' +
                            '<strong>Contexto educativo adicional</strong>' +
                            '<small>Indicadores oficiales y contexto complementario para profundizar el análisis</small>' +
                        '</span>' +
                        '<i class="bi bi-chevron-down" aria-hidden="true"></i>' +
                    '</summary>' +
                    '<div class="data-education-complementary-context-body" data-education-complementary-context-body></div>';

                contenedor.insertAdjacentElement('afterend', contexto);
            }

            const cuerpo = contexto.querySelector(
                '[data-education-complementary-context-body]'
            );

            if (!cuerpo) {
                return null;
            }

            if (!consultaEducativaTerminada) {
                contexto.open = false;
                return {
                    contexto: contexto,
                    cuerpo: cuerpo
                };
            }

            let rezagoDetalle = cuerpo.querySelector(
                '[data-education-official-detail]'
            );

            if (!rezagoDetalle) {
                rezagoDetalle = document.createElement('details');
                rezagoDetalle.className = 'data-education-subcontext';
                rezagoDetalle.setAttribute('data-education-official-detail', '');

                const porcentajeRezago = rezago.querySelector(
                    '.data-education-metric-primary > strong'
                )?.textContent?.trim() || 'Sin dato';
                const personasRezago = rezago.querySelector(
                    '.data-education-metric:not(.data-education-metric-primary) > strong'
                )?.textContent?.trim() || 'Sin dato';
                const periodoRezago = rezago.querySelector(
                    '.data-education-period'
                )?.textContent?.trim() || '';

                rezagoDetalle.innerHTML =
                    '<summary>' +
                        '<span>' +
                            '<strong>Rezago educativo oficial</strong>' +
                            '<small>' +
                                escapar(porcentajeRezago) + ' · ' +
                                escapar(personasRezago) + ' personas' +
                                (periodoRezago ? ' · ' + escapar(periodoRezago) : '') +
                            '</small>' +
                        '</span>' +
                        '<i class="bi bi-chevron-down"></i>' +
                    '</summary>' +
                    '<div class="data-education-subcontext-body" data-education-official-body></div>';

                cuerpo.appendChild(rezagoDetalle);
            }

            const rezagoBody = rezagoDetalle.querySelector(
                '[data-education-official-body]'
            );

            if (rezagoBody && !rezagoBody.contains(rezago)) {
                rezagoBody.appendChild(rezago);
            }

            rezago.style.display = '';

            return {
                contexto: contexto,
                cuerpo: cuerpo
            };
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
                        const poblacionGrupo =
                            item[1] ??
                            grupo.poblacion_total ??
                            null;
                        const brecha = grupo.sin_estudios_media_superior ??
                            grupo.sin_media_superior_concluida;
                        const brechaPct = grupo.sin_estudios_media_superior_pct;
                        return (
                            '<div>' +
                                '<span>' + escapar(item[0]) + ' años</span>' +
                                '<strong>' + numero(poblacionGrupo) + '</strong>' +
                                '<small>' +
                                    (brecha !== null && brecha !== undefined
                                        ? numero(brecha) +
                                            ' sin estudios de media superior' +
                                            (brechaPct !== null && brechaPct !== undefined
                                                ? ' · <b>' + porcentaje(brechaPct) + '</b>'
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
                                    '<span>Personas · % del grupo 25–49</span>' +
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

        const construirRecomendacionesOferta = function (metricas) {
            const pctSinMedia = Number(
                metricas?.sin_estudios_media_superior_pct ??
                metricas?.sin_media_superior_concluida_pct ??
                0
            );
            const pctMediaSinSuperior = Number(
                metricas?.media_superior_sin_superior_pct || 0
            );

            const sectores = Array.isArray(perfilEconomico.sectores)
                ? perfilEconomico.sectores
                : [];
            const sobre = Array.isArray(
                perfilEconomico.sectores_sobrerrepresentados
            )
                ? perfilEconomico.sectores_sobrerrepresentados
                : [];

            const normalizarClave = function (clave) {
                return String(clave || '').trim();
            };

            const fuerzaSector = function (claves) {
                let fuerza = 0;

                sectores.forEach(function (sector) {
                    if (claves.includes(normalizarClave(sector.clave))) {
                        fuerza += Math.max(0, Number(sector.porcentaje || 0));
                    }
                });

                sobre.forEach(function (sector) {
                    if (claves.includes(normalizarClave(sector.clave))) {
                        fuerza += Math.max(
                            0,
                            Number(sector.diferencia_puntos || 0)
                        ) * 1.5;
                    }
                });

                return fuerza;
            };

            const sectorRelacionado = function (claves) {
                const candidatos = sectores
                    .filter(function (sector) {
                        return claves.includes(normalizarClave(sector.clave));
                    })
                    .sort(function (a, b) {
                        return Number(b.porcentaje || 0) -
                            Number(a.porcentaje || 0);
                    });

                return candidatos[0]?.nombre || '';
            };

            const recomendaciones = [
                {
                    id: 'bachillerato',
                    nombre: 'Bachillerato / Ruta SEP-286',
                    oferta: 'Acreditación y continuidad de educación media superior',
                    score: pctSinMedia * 1.3,
                    razon:
                        porcentaje(pctSinMedia) +
                        ' de la población 25–49 no registra estudios de media superior.',
                    evidencia: 'Escolaridad'
                },
                {
                    id: 'ejecutivas',
                    nombre: 'Carreras ejecutivas',
                    oferta: 'Continuidad profesional en modalidad flexible',
                    score:
                        pctMediaSinSuperior * 1.15 +
                        fuerzaSector(['52', '54', '55', '56', '61']),
                    razon:
                        porcentaje(pctMediaSinSuperior) +
                        ' del grupo 25–49 tiene media superior sin educación superior.' +
                        (
                            sectorRelacionado(['52', '54', '55', '56', '61'])
                                ? ' Además destaca ' +
                                  sectorRelacionado(['52', '54', '55', '56', '61']) +
                                  ' en la estructura económica estatal.'
                                : ''
                        ),
                    evidencia: 'Escolaridad + economía'
                },
                {
                    id: 'seguridad',
                    nombre: 'Seguridad Pública',
                    oferta: 'TSU y Licenciatura en Seguridad Pública',
                    score:
                        pctMediaSinSuperior +
                        fuerzaSector(['93']) * 2.2,
                    razon:
                        sectorRelacionado(['93'])
                            ? 'La presencia de ' +
                              sectorRelacionado(['93']) +
                              ' incrementa la afinidad territorial de esta oferta.'
                            : 'La oferta requiere bachillerato; su afinidad se apoya principalmente en la base con media superior disponible.',
                    evidencia: sectorRelacionado(['93'])
                        ? 'Escolaridad + sector público'
                        : 'Escolaridad'
                },
                {
                    id: 'ciberseguridad',
                    nombre: 'Ciberseguridad',
                    oferta: 'Licenciatura en Ciberseguridad',
                    score:
                        pctMediaSinSuperior +
                        fuerzaSector(['51', '54']) * 1.8,
                    razon:
                        sectorRelacionado(['51', '54'])
                            ? 'La presencia de ' +
                              sectorRelacionado(['51', '54']) +
                              ' aporta afinidad adicional para una oferta tecnológica.'
                            : 'La base con media superior permite considerarla, aunque el territorio no muestra una señal económica tecnológica fuerte.',
                    evidencia: sectorRelacionado(['51', '54'])
                        ? 'Escolaridad + economía'
                        : 'Escolaridad'
                }
            ];

            recomendaciones.sort(function (a, b) {
                return b.score - a.score;
            });

            return recomendaciones.slice(0, 3).map(function (item, indice) {
                return {
                    ...item,
                    posicion: indice + 1,
                    nivel:
                        indice === 0
                            ? 'Mayor afinidad'
                            : (indice === 1 ? 'Afinidad relevante' : 'Afinidad complementaria')
                };
            });
        };

        const renderRecomendacionesOferta = function (metricas) {
            const recomendaciones = construirRecomendacionesOferta(metricas);
            const estado = perfilEconomico.estado || 'el territorio';

            return (
                '<div class="data-education-program-opportunity">' +
                    '<div class="data-education-program-heading">' +
                        '<div>' +
                            '<strong>Ofertas con mayor afinidad territorial</strong>' +
                            '<span>Recomendación orientativa para ' +
                                escapar(estado) +
                                ', construida con escolaridad 25–49 y estructura económica DENUE. No representa demanda confirmada.</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="data-education-recommendation-list">' +
                        recomendaciones.map(function (item) {
                            return (
                                '<article class="data-education-recommendation-card">' +
                                    '<div class="data-education-recommendation-rank">' +
                                        '<span>' + item.posicion + '</span>' +
                                    '</div>' +
                                    '<div class="data-education-recommendation-copy">' +
                                        '<div class="data-education-recommendation-title">' +
                                            '<strong>' + escapar(item.nombre) + '</strong>' +
                                            '<span>' + escapar(item.nivel) + '</span>' +
                                        '</div>' +
                                        '<small>' + escapar(item.oferta) + '</small>' +
                                        '<p>' + escapar(item.razon) + '</p>' +
                                    '</div>' +
                                    '<div class="data-education-recommendation-source">' +
                                        escapar(item.evidencia) +
                                    '</div>' +
                                '</article>'
                            );
                        }).join('') +
                    '</div>' +
                    '<p class="data-education-program-caveat">' +
                        '<strong>Titulación por experiencia laboral:</strong> se mantiene como oportunidad a validar directamente con aliados y población ocupada; el sistema no estima elegibilidad sin evidencia de experiencia laboral.' +
                    '</p>' +
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

            if (!prioridadDisponible) {
                const mensaje =
                    perfilPrioritario?.actualizacion_automatica?.mensaje ||
                    'El cruce oficial edad × escolaridad para 25–49 años todavía no está sincronizado en este territorio.';

                return (
                    '<div class="data-education-priority">' +
                        '<div class="data-education-priority-heading">' +
                            '<div>' +
                                '<span class="data-education-target-eyebrow">Población educativa prioritaria</span>' +
                                '<h4>Adultos de 25 a 49 años</h4>' +
                                '<p>La ficha utilizará únicamente el cruce oficial de edad × escolaridad; no se estiman cifras con porcentajes generales.</p>' +
                            '</div>' +
                            '<span class="data-education-target-period">Sin sincronizar</span>' +
                        '</div>' +
                        '<div class="data-education-priority-method">' +
                            '<i class="bi bi-cloud-arrow-down"></i>' +
                            '<div><strong>Información oficial pendiente de sincronización</strong>' +
                            '<span>' + escapar(mensaje) + '</span></div>' +
                        '</div>' +
                    '</div>'
                );
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

                    '<div class="data-education-priority-segment-label">Distribución educativa del universo 25–49</div>' +
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
                            '<small>Personas sin grados aprobados de educación media superior.</small>' +
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

                    renderRecomendacionesOferta(prioridadMetricas) +

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
            const disponible =
                datos?.contexto_general_disponible !== false &&
                Object.keys(m).length > 0;

            if (!disponible) {
                return (
                    '<div class="data-education-priority-method">' +
                        '<i class="bi bi-info-circle"></i>' +
                        '<div><strong>Contexto educativo adicional sin sincronizar</strong>' +
                        '<span>' +
                            escapar(
                                datos?.mensaje_contexto_general ||
                                'El contexto general se cargará desde la próxima sincronización oficial.'
                            ) +
                        '</span></div>' +
                    '</div>'
                );
            }

            return (
                '<details class="data-education-subcontext">' +
                    '<summary>' +
                        '<span><strong>Indicadores generales del Censo ' + escapar(datos.periodo || '2020') + '</strong><small>Secundaria completa, educación posbásica y escolaridad promedio</small></span>' +
                        '<i class="bi bi-chevron-down"></i>' +
                    '</summary>' +
                    '<div class="data-education-subcontext-body">' +
                        '<div class="data-education-general-compact">' +
                            '<div><span>Secundaria completa · 15+</span><strong>' + numero(m.secundaria_completa) + '</strong><small>' + porcentaje(m.secundaria_completa_pct) + '</small></div>' +
                            '<div><span>Educación posbásica · 18+</span><strong>' + numero(m.educacion_posbasica_18_mas) + '</strong><small>Referencia de continuidad educativa</small></div>' +
                            '<div><span>Escolaridad promedio</span><strong>' + decimal(m.grado_promedio_escolaridad) + ' años</strong><small>Promedio estatal</small></div>' +
                        '</div>' +
                        '<p class="data-education-subcontext-note">Estos indicadores describen el entorno educativo general y no sustituyen el perfil prioritario de 25–49.</p>' +
                    '</div>' +
                '</details>' +

                '<details class="data-education-subcontext">' +
                    '<summary>' +
                        '<span><strong>Contexto juvenil</strong><small>Asistencia escolar de 15 a 24 años · referencia secundaria</small></span>' +
                        '<i class="bi bi-chevron-down"></i>' +
                    '</summary>' +
                    '<div class="data-education-subcontext-body">' +
                        '<div class="data-education-youth-context-body">' +
                            '<div><span>15 a 17 años fuera de la escuela</span><strong>' + numero(m.fuera_15_17) + '</strong><small>' + porcentaje(m.fuera_15_17_pct) + ' del grupo</small></div>' +
                            '<div><span>18 a 24 años fuera de la escuela</span><strong>' + numero(m.fuera_18_24) + '</strong><small>' + porcentaje(m.fuera_18_24_pct) + ' del grupo</small></div>' +
                            '<div><span>15 a 24 años no registrados como asistentes</span><strong>' + numero(m.fuera_15_24) + '</strong><small>' + porcentaje(m.fuera_15_24_pct) + ' del grupo</small></div>' +
                        '</div>' +
                        '<details class="data-education-youth-municipal">' +
                            '<summary><span><strong>Ver detalle municipal juvenil</strong><small>Municipios con mayor población de 15 a 24 años no registrada como asistente</small></span><i class="bi bi-chevron-down"></i></summary>' +
                            '<div class="data-education-youth-municipal-body">' +
                                renderMunicipiosJuveniles(datos.municipios || []) +
                            '</div>' +
                        '</details>' +
                    '</div>' +
                '</details>' +

                '<div class="data-education-context-source">' +
                    '<span>Fuente: <strong>' + escapar(datos.fuente || 'INEGI') + '</strong></span>' +
                    '<span>Periodo: <strong>' + escapar(datos.periodo || '2020') + '</strong></span>' +
                '</div>'
            );
        };

        const render = function (datos) {
            contenedor.innerHTML = renderPrioridad(datos);
            consultaEducativaTerminada = true;

            const contextoPreparado = asegurarContextoAdicional();

            if (!contextoPreparado) {
                return;
            }

            const cuerpo = contextoPreparado.cuerpo;

            let contextoGeneral = cuerpo.querySelector(
                '[data-education-generated-context]'
            );

            if (!contextoGeneral) {
                contextoGeneral = document.createElement('div');
                contextoGeneral.setAttribute('data-education-generated-context', '');
                cuerpo.appendChild(contextoGeneral);
            }

            contextoGeneral.innerHTML = renderContexto(datos);
        };

        const cargar = async function () {
            renderCarga();

            const controlador = new AbortController();
            const timeout = window.setTimeout(
                function () {
                    controlador.abort();
                },
                10000
            );

            try {
                const respuesta = await fetch(
                    'public/inegi_educacion_objetivo.php?estado_id=' +
                        encodeURIComponent(estadoId),
                    {
                        headers: { 'X-Requested-With': 'fetch' },
                        cache: 'no-store',
                        signal: controlador.signal
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
                consultaEducativaTerminada = true;
                renderError(
                    error?.name === 'AbortError'
                        ? 'La información local tardó más de lo esperado. Recarga la vista o solicita una sincronización oficial.'
                        : (
                            error.message ||
                            'No fue posible consultar la información educativa.'
                        )
                );
                asegurarContextoAdicional();
            } finally {
                window.clearTimeout(timeout);
            }
        };

        const contextoInicial = asegurarContextoAdicional();
        if (contextoInicial?.contexto) {
            contextoInicial.contexto.open = false;
        }
        cargar();
    });
})();
