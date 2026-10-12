/* Puente de interacción con la interfaz nativa de Zadarma, sin SDK privado.
 * Sólo acepta controles identificables como pareja verde/rojo en timbrado.
 * Las acciones se ejecutan al hacer clic el operador, no durante un sondeo.
 */
(function (root) {
    'use strict';
    const state = {lastError: ''};

    function colorKind(value) {
        const raw = String(value || '').toLowerCase();
        const m = raw.match(/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
        if (m) {
            const r=+m[1], g=+m[2], b=+m[3];
            if (g >= 90 && g > r * 1.25 && g > b * 1.2) return 'answer';
            if (r >= 110 && r > g * 1.35 && r > b * 1.2) return 'decline';
        }
        if (/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/.test(raw)) {
            let h=raw.slice(1);
            if (h.length === 3) h=h.split('').map(c => c+c).join('');
            return colorKind('rgb('+parseInt(h.slice(0,2),16)+','+
                parseInt(h.slice(2,4),16)+','+parseInt(h.slice(4,6),16)+')');
        }
        return '';
    }

    function accessibleLabel(node) {
        return [
            node.getAttribute?.('aria-label'), node.getAttribute?.('title'),
            node.getAttribute?.('data-action'), node.getAttribute?.('data-testid')
        ].filter(Boolean).join(' ').toLowerCase();
    }

    function detectKind(node, win) {
        const label = accessibleLabel(node);
        if (/\b(answer|accept|contestar|atender|responder)\b/i.test(label)) return 'answer';
        if (/\b(decline|reject|rechazar|colgar|hangup|hang-up|end.call)\b/i.test(label)) return 'decline';
        const parts = [node];
        try {parts.push(...node.querySelectorAll('svg,svg *,i,span,img'));} catch (_) {}
        const kinds = new Set();
        for (const el of parts.slice(0, 28)) {
            const values = [
                el.getAttribute?.('fill'), el.getAttribute?.('stroke'),
                el.getAttribute?.('color'), el.style?.color,
                el.style?.fill, el.style?.backgroundColor,
                el.getAttribute?.('src'), el.getAttribute?.('class')
            ];
            try {
                const c=win.getComputedStyle?.(el);
                if (c) values.push(c.color, c.fill, c.stroke, c.backgroundColor);
            } catch (_) {}
            for (const v of values) {
                const val=String(v || '').toLowerCase();
                if (/phone.*(green|accept)|(?:green|accept).*phone/.test(val)) kinds.add('answer');
                if (/phone.*(red|reject|decline)|(?:red|reject|decline).*phone/.test(val)) kinds.add('decline');
                const kind=colorKind(val);
                if (kind) kinds.add(kind);
            }
        }
        return kinds.size === 1 ? [...kinds][0] : '';
    }

    function clickable(node) {
        if (!node?.getBoundingClientRect || node.disabled ||
            node.getAttribute?.('aria-disabled') === 'true') return false;
        const r=node.getBoundingClientRect();
        return r.width >= 22 && r.width <= 115 && r.height >= 22 &&
            r.height <= 115;
    }

    function frames(doc) {
        const items = [{doc,win: doc.defaultView}];
        try {
            for (const frame of doc.querySelectorAll('iframe')) {
                try {
                    if (frame.contentDocument?.body) {
                        items.push({doc:frame.contentDocument,win:frame.contentWindow});
                    }
                } catch (_) { /* iframe externo: no acceder */ }
            }
        } catch (_) {}
        return items;
    }

    function findPair(doc) {
        for (const frame of frames(doc)) {
            const buttons = [];
            const selector = 'button,[role="button"],[onclick],a,' +
                '[class*="btn" i],[class*="button" i],[style*="cursor"]';
            try {
                for (const node of frame.doc.querySelectorAll(selector)) {
                    if (!clickable(node)) continue;
                    const kind=detectKind(node, frame.win);
                    if (kind) buttons.push({node,kind,rect:node.getBoundingClientRect()});
                }
            } catch (_) {}
            for (const green of buttons.filter(x=>x.kind==='answer')) {
                for (const red of buttons.filter(x=>x.kind==='decline')) {
                    const dx=Math.abs((green.rect.left+green.rect.width/2)-
                        (red.rect.left+red.rect.width/2));
                    const dy=Math.abs((green.rect.top+green.rect.height/2)-
                        (red.rect.top+red.rect.height/2));
                    if (dx >= 15 && dx <= 185 && dy <= 55) {
                        return {answer: green.node, decline: red.node};
                    }
                }
            }
        }
        return null;
    }

    function create(doc, isRinging, isTalking) {
        const available = () => Boolean(isRinging() && findPair(doc));
        const act = function (kind) {
            state.lastError = '';
            if (!isRinging()) {
                state.lastError='La llamada ya no está timbrando.';
                return false;
            }
            const pair=findPair(doc);
            if (!pair?.[kind]) {
                state.lastError='No se identificaron controles WebRTC seguros.';
                return false;
            }
            try {
                pair[kind].click();
                return true;
            } catch (_) {
                state.lastError='Zadarma no permitió accionar el control.';
                return false;
            }
        };
        const findHangup = function () {
            if (!isTalking()) return null;
            const candidates = [];
            for (const frame of frames(doc)) {
                try {
                    for (const node of frame.doc.querySelectorAll(
                        'button,[role="button"],[onclick],a,' +
                        '[class*="btn" i],[class*="button" i],[style*="cursor"]'
                    )) {
                        if (clickable(node) &&
                            detectKind(node,frame.win) === 'decline') {
                            candidates.push(node);
                        }
                    }
                } catch (_) {}
            }
            return candidates.length === 1 ? candidates[0] : null;
        };
        const hangup = function () {
            state.lastError = '';
            const button=findHangup();
            if (!button) {
                state.lastError='No se identificó un control seguro para colgar.';
                return false;
            }
            try {button.click(); return true;}
            catch (_) {
                state.lastError='Zadarma no permitió finalizar desde este control.';
                return false;
            }
        };
        return Object.freeze({
            available,
            canHangup: () => Boolean(findHangup()),
            answer: () => act('answer'),
            decline: () => act('decline'),
            hangup,
            error: () => state.lastError
        });
    }
    const factory=Object.freeze({create, colorKind});
    root.IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY=factory;
    if (typeof module !== 'undefined' && module.exports) module.exports=factory;
})(typeof window !== 'undefined' ? window : {});
