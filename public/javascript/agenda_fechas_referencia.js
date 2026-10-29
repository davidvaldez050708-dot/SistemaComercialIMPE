(function () {
    'use strict';
    function iniciar() {
        const root = document.querySelector('[data-agenda-root]');
        const legend = root?.querySelector('[data-agenda-fechas-legend]');
        const source = root?.querySelector('[data-agenda-fechas-datos]');
        if (!legend || !source || root.dataset.fechasReferenciaReady === '1') return;
        let data;
        try { data = JSON.parse(source.textContent); } catch (_) { legend.hidden = true; return; }
        if (!data || !data.eventos || typeof data.eventos !== 'object' || !Array.isArray(data.fines_semana_largos)) { legend.hidden = true; return; }
        root.dataset.fechasReferenciaReady = '1';
        const categories = {
            oficial: {label:'Oficiales', symbol:'●'},
            tradicional: {label:'Tradicionales', symbol:'◆'},
            santa: {label:'Semana Santa', symbol:'✦'},
            fsl: {label:'Fines de semana largos', symbol:'▰'}
        };
        const priority = ['oficial','santa','tradicional','fsl'];
        const days = Array.from(root.querySelectorAll('.agenda-calendar-grid [data-agenda-date]'));
        const cells = days.map(day => {
            const date = day.dataset.agendaDate;
            const events = (Array.isArray(data.eventos[date]) ? data.eventos[date] : [])
                .filter(e => categories[e.categoria] && typeof e.nombre === 'string');
            data.fines_semana_largos.forEach(range => {
                // Comparar ISO como texto evita cambios de día por zonas horarias.
                if (date >= range.desde && date <= range.hasta) events.push({categoria:'fsl',nombre:'Fin de semana largo · '+range.motivo});
            });
            if (!events.length) return null;
            const holder = document.createElement('div');
            holder.className = 'agenda-fechas-del-dia';
            holder.setAttribute('aria-label','Fechas de referencia del '+date);
            const seen = new Set();
            events.forEach(event => {
                const key = event.categoria+'|'+event.nombre;
                if (seen.has(key)) return;
                seen.add(key);
                const item = document.createElement('span');
                item.className = 'agenda-fecha-etiqueta agenda-fecha-'+event.categoria;
                item.dataset.agendaFechaTipo = event.categoria;
                item.setAttribute('aria-label',categories[event.categoria].label+': '+event.nombre);
                item.title = categories[event.categoria].label+': '+event.nombre;
                const symbol = document.createElement('span');
                symbol.setAttribute('aria-hidden','true');symbol.textContent = categories[event.categoria].symbol;
                const name = document.createElement('span');name.textContent = event.nombre;
                item.append(symbol,name);holder.append(item);
            });
            day.querySelector('.agenda-day-events')?.after(holder);
            return {day,holder,number:day.querySelector('.agenda-day-number > span')};
        }).filter(Boolean);
        function actualizar() {
            const enabled = new Set(Array.from(legend.querySelectorAll('input:checked')).map(input=>input.dataset.agendaFechaCategoria));
            cells.forEach(({holder,number,day}) => {
                const visible = new Set();
                holder.querySelectorAll('[data-agenda-fecha-tipo]').forEach(item => {
                    item.hidden = !enabled.has(item.dataset.agendaFechaTipo);
                    if (!item.hidden) visible.add(item.dataset.agendaFechaTipo);
                });
                holder.hidden = visible.size === 0;
                priority.forEach(type => number?.classList.remove('agenda-fecha-numero-'+type));
                // Hoy y la selección de comentarios conservan su identidad visual.
                const main = priority.find(type => visible.has(type));
                if (main && !day.classList.contains('is-today')) number?.classList.add('agenda-fecha-numero-'+main);
            });
        }
        legend.querySelectorAll('[data-agenda-fecha-categoria]').forEach(input=>input.addEventListener('change',actualizar));
        actualizar();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',iniciar,{once:true});else iniciar();
})();
