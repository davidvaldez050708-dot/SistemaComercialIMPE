(function () {
    'use strict';

    const escapeHtml = function (value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    };

    const number = function (value) {
        const n = Number(value);
        return Number.isFinite(n) && n > 0
            ? new Intl.NumberFormat('es-MX').format(n)
            : 'No registrada';
    };

    const parseList = function (value) {
        try {
            const parsed = JSON.parse(value || '[]');
            return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
        } catch (error) {
            return [];
        }
    };

    const parseObject = function (value) {
        try {
            const parsed = JSON.parse(value || '{}');
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
            return {};
        }
    };

    const factors = function (items) {
        if (!items.length) {
            return '<p class="data-municipality-analysis-empty">Todavía no hay factores suficientes para explicar la oportunidad.</p>';
        }

        return '<div class="data-municipality-analysis-factors">' +
            items.map(function (item) {
                return '<div><i class="bi bi-check-circle"></i><span>' + escapeHtml(item) + '</span></div>';
            }).join('') +
            '</div>';
    };

    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-municipio-analysis]');
        const content = document.getElementById('municipioAnalisisContenido');

        if (!button || !content) {
            return;
        }

        const name = button.dataset.nombre || 'Municipio';
        const president = button.dataset.presidente || '';
        const party = button.dataset.partido || '';
        const social = button.dataset.redes || '';
        const photo = button.dataset.foto || '';
        const score = Number(button.dataset.puntaje || 0);
        const coverage = Number(button.dataset.cobertura || 0);
        const rank = Number(button.dataset.ranking || 0);
        const totalRank = Number(button.dataset.totalRanking || 0);
        const reasons = parseList(button.dataset.motivos);
        const limitations = parseList(button.dataset.limitaciones);
        const adultProfile = parseObject(button.dataset.perfilAdulto);
        const action = button.dataset.accion || 'OBSERVAR';
        const priority = (button.dataset.prioridad || 'BAJA').toLowerCase();

        const photoHtml = photo
            ? '<img src="' + escapeHtml(photo) + '" alt="Fotografía de ' + escapeHtml(president || name) + '">'
            : '<i class="bi bi-person"></i>';

        let institutionalMeta = '';
        if (president) {
            institutionalMeta += '<strong>' + escapeHtml(president) + '</strong>';
            institutionalMeta += '<span>' + escapeHtml(party ? party : 'Partido no registrado') + '</span>';
        } else {
            institutionalMeta += '<strong>Presidente municipal</strong><span>Información no registrada</span>';
        }

        if (social) {
            const isUrl = /^https?:\/\//i.test(social);
            institutionalMeta += isUrl
                ? '<a href="' + escapeHtml(social) + '" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Red social</a>'
                : '<span>' + escapeHtml(social) + '</span>';
        }

        const adultAvailable = adultProfile.disponible === true;
        const adultPeriod = adultProfile.anio || '';
        const adultSource = adultProfile.fuente || 'INEGI - Censo de Población y Vivienda 2020';
        const adultHtml = adultAvailable
            ? '<section class="data-municipality-analysis-section data-municipality-analysis-adult-section">' +
                '<div class="data-municipality-analysis-section-heading"><h4>Perfil adulto y laboral</h4><span>INEGI · ' +
                    escapeHtml(adultPeriod || '2020') + '</span></div>' +
                '<div class="data-municipality-analysis-adult-grid">' +
                    '<div><span>25–34 años</span><strong>' + number(adultProfile.poblacion_25_34) + '</strong></div>' +
                    '<div><span>35–44 años</span><strong>' + number(adultProfile.poblacion_35_44) + '</strong></div>' +
                    '<div><span>45–54 años</span><strong>' + number(adultProfile.poblacion_45_54) + '</strong></div>' +
                    '<div class="data-municipality-analysis-adult-total"><span>Total 25–54</span><strong>' +
                        number(adultProfile.poblacion_25_54) + '</strong></div>' +
                '</div>' +
                '<div class="data-municipality-analysis-labor-context">' +
                    '<div><span>PEA</span><strong>' + number(adultProfile.poblacion_economicamente_activa) + '</strong></div>' +
                    '<div><span>Población ocupada</span><strong>' + number(adultProfile.poblacion_ocupada) + '</strong></div>' +
                '</div>' +
                '<p class="data-municipality-analysis-caption">PEA y población ocupada son contexto laboral general de 12 años y más; no corresponden exclusivamente al grupo de 25 a 54 años. Fuente: ' +
                    escapeHtml(adultSource) + '.</p>' +
              '</section>'
            : '<section class="data-municipality-analysis-section data-municipality-analysis-adult-section">' +
                '<div class="data-municipality-analysis-section-heading"><h4>Perfil adulto y laboral</h4><span>Pendiente</span></div>' +
                '<p class="data-municipality-analysis-empty">Este municipio todavía no cuenta con el perfil adulto/laboral oficial importado.</p>' +
              '</section>';

        const methodology = limitations.length
            ? '<details class="data-municipality-analysis-methodology">' +
                '<summary><i class="bi bi-info-circle"></i><span>Metodología actual</span><i class="bi bi-chevron-down"></i></summary>' +
                '<div>' + limitations.map(function (item) {
                    return '<p>' + escapeHtml(item) + '</p>';
                }).join('') +
                '<p>La cobertura mostrada corresponde a los componentes disponibles del modelo actual; no significa que el modelo definitivo esté completo.</p></div>' +
              '</details>'
            : '';

        content.innerHTML =
            '<div class="data-municipality-analysis">' +
                '<header class="data-municipality-analysis-hero">' +
                    '<div class="data-municipality-analysis-hero-copy">' +
                        '<div class="data-municipality-analysis-kicker">Municipio · ' +
                            escapeHtml(button.dataset.claveInegi || 'Sin clave INEGI') + '</div>' +
                        '<div class="data-municipality-analysis-name-row">' +
                            '<h3>' + escapeHtml(name) + '</h3>' +
                            '<span class="data-municipality-analysis-action data-municipality-analysis-action-' + escapeHtml(priority) + '">' +
                                escapeHtml(action) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="data-municipality-analysis-index"><strong>' + score + '</strong><span>Índice provisional</span></div>' +
                '</header>' +
                '<div class="data-municipality-analysis-summary">' +
                    '<div><span>Población</span><strong>' + number(button.dataset.poblacion) + '</strong></div>' +
                    '<div><span>Posición</span><strong>' +
                        (rank > 0 && totalRank > 0 ? rank + ' de ' + totalRank : 'No disponible') +
                    '</strong></div>' +
                    '<div><span>Cobertura de datos</span><strong>' + coverage + '%</strong></div>' +
                '</div>' +
                '<section class="data-municipality-analysis-section">' +
                    '<div class="data-municipality-analysis-section-heading"><h4>¿Por qué se prioriza?</h4><span>Lectura actual</span></div>' +
                    factors(reasons) +
                '</section>' +
                adultHtml +
                '<section class="data-municipality-analysis-section data-municipality-analysis-institutional-section">' +
                    '<div class="data-municipality-analysis-section-heading"><h4>Información institucional</h4><span>Contexto</span></div>' +
                    '<div class="data-municipality-official">' +
                        '<div class="data-municipality-official-photo">' + photoHtml + '</div>' +
                        '<div class="data-municipality-official-copy">' + institutionalMeta + '</div>' +
                    '</div>' +
                    '<p class="data-municipality-analysis-caption">Estos datos sirven como referencia institucional y no modifican el índice.</p>' +
                '</section>' +
                methodology +
            '</div>';
    });
})();