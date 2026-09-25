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

    const list = function (items, emptyText) {
        if (!items.length) {
            return '<div class="data-municipality-analysis-warning"><i class="bi bi-info-circle"></i><span>' +
                escapeHtml(emptyText) + '</span></div>';
        }

        return '<ul class="data-municipality-analysis-list">' +
            items.map(function (item) {
                return '<li>' + escapeHtml(item) + '</li>';
            }).join('') +
            '</ul>';
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
        const action = button.dataset.accion || 'OBSERVAR';

        const photoHtml = photo
            ? '<img src="' + escapeHtml(photo) + '" alt="Fotografía de ' + escapeHtml(president || name) + '">'
            : '<i class="bi bi-person"></i>';

        let socialHtml = '';
        if (social) {
            const isUrl = /^https?:\/\//i.test(social);
            socialHtml = isUrl
                ? '<a href="' + escapeHtml(social) + '" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Abrir red social</a>'
                : '<span>Redes: ' + escapeHtml(social) + '</span>';
        } else {
            socialHtml = '<span>Redes sociales no registradas</span>';
        }

        content.innerHTML =
            '<div class="data-municipality-analysis">' +
                '<div class="data-municipality-analysis-identity">' +
                    '<div><h3>' + escapeHtml(name) + '</h3><p>Clave INEGI: ' +
                    escapeHtml(button.dataset.claveInegi || 'No registrada') + '</p></div>' +
                    '<div class="data-municipality-analysis-score"><strong>' + score +
                    '/100</strong><span>Índice provisional</span></div>' +
                '</div>' +
                '<section class="data-municipality-analysis-section">' +
                    '<h4>Lectura de oportunidad</h4>' +
                    '<div class="data-municipality-analysis-metrics">' +
                        '<div><span>Acción sugerida</span><strong>' + escapeHtml(action) + '</strong></div>' +
                        '<div><span>Población</span><strong>' + number(button.dataset.poblacion) + '</strong></div>' +
                        '<div><span>Ranking territorial</span><strong>' +
                            (rank > 0 && totalRank > 0 ? rank + ' de ' + totalRank : 'No disponible') +
                        '</strong></div>' +
                        '<div><span>Cobertura de datos</span><strong>' + coverage + '%</strong></div>' +
                    '</div>' +
                    list(reasons, 'Todavía no hay factores suficientes para explicar la oportunidad del municipio.') +
                '</section>' +
                '<section class="data-municipality-analysis-section">' +
                    '<h4>Alcance actual del análisis</h4>' +
                    list(limitations, 'No hay limitaciones adicionales registradas.') +
                '</section>' +
                '<section class="data-municipality-analysis-section">' +
                    '<h4>Información institucional</h4>' +
                    '<div class="data-municipality-official">' +
                        '<div class="data-municipality-official-photo">' + photoHtml + '</div>' +
                        '<div class="data-municipality-official-copy">' +
                            '<strong>' + escapeHtml(president || 'Presidente municipal no registrado') + '</strong>' +
                            '<span>' + escapeHtml(party ? 'Partido: ' + party : 'Partido no registrado') + '</span>' +
                            socialHtml +
                        '</div>' +
                    '</div>' +
                    '<div class="data-municipality-analysis-warning"><i class="bi bi-info-circle"></i>' +
                        '<span>La información del presidente, partido y redes se conserva como contexto institucional y no aumenta el Índice de Oportunidad Municipal.</span></div>' +
                '</section>' +
            '</div>';
    });
})();