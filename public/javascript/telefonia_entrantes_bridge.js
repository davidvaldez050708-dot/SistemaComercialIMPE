/* Control seguro del widget WebRTC de Zadarma desde el modal del CRM.
 * Algunos botones del proveedor son DIV/SVG, no botones HTML.
 * Nunca reubicar ni ocultar elementos internos del SDK: el motor está en
 * un iframe persistente y sólo se hace clic tras la acción del operador.
 */
(function (root) {
    'use strict';
    const ANSWER = /\b(?:answer|accept|contestar|atender|responder)\b/i;
    const DECLINE = /\b(?:decline|reject|rechazar|colgar|hangup|hang-up|end.call)\b/i;

    function colorKind(value) {
        const raw = String(value || '').toLowerCase().trim();
        const match = raw.match(/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/);
        let r,g,b;
        if (match) {r=+match[1];g=+match[2];b=+match[3];}
        else if (/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/.test(raw)) {
            let h=raw.slice(1);
            if (h.length===3) h=h.split('').map(x=>x+x).join('');
            r=parseInt(h.slice(0,2),16);
            g=parseInt(h.slice(2,4),16);
            b=parseInt(h.slice(4,6),16);
        } else return '';
        if (g>=90 && g>r*1.35 && g>b*1.18) return 'answer';
        if (r>=110 && r>g*1.35 && r>b*1.18) return 'decline';
        return '';
    }

    function label(node) {
        return ['aria-label','title','data-action','data-testid']
            .map(key=>node.getAttribute?.(key)).filter(Boolean).join(' ').toLowerCase();
    }
    function nodeKind(node,win) {
        const text=label(node);
        if (ANSWER.test(text)) return 'answer';
        if (DECLINE.test(text)) return 'decline';
        const cls=String(node.getAttribute?.('class')||'');
        if (/green|accept/i.test(cls) && !/red|reject|decline/i.test(cls)) return 'answer';
        if (/red|reject|decline/i.test(cls) && !/green|accept/i.test(cls)) return 'decline';
        const vals=[
            node.getAttribute?.('fill'),node.getAttribute?.('stroke'),
            node.getAttribute?.('color'),node.style?.color,node.style?.fill,
            node.style?.stroke,node.style?.backgroundColor
        ];
        try {
            const css=win?.getComputedStyle?.(node);
            if (css) vals.push(css.color,css.fill,css.stroke,css.backgroundColor);
        } catch (_) {}
        const types=new Set(vals.map(colorKind).filter(Boolean));
        return types.size===1?[...types][0]:'';
    }
    function bounds(node) {
        if (typeof node?.getBoundingClientRect!=='function') return null;
        const r=node.getBoundingClientRect();
        if (!r || r.width<12 || r.height<12 ||
            r.width>125 || r.height>125) return null;
        return r;
    }
    function documents(doc) {
        const frames=[{doc,win:doc.defaultView}];
        try {
            for (const frame of doc.querySelectorAll('iframe')) {
                try {
                    if (frame.contentDocument?.body) {
                        frames.push({doc:frame.contentDocument,win:frame.contentWindow});
                    }
                } catch (_) {/* no inspeccionar iframe de otro origen */}
            }
        } catch (_) {}
        return frames;
    }
    function candidates(doc,win) {
        let nodes=[];
        try {
            nodes=Array.from(doc.querySelectorAll(
                'button,[role="button"],[onclick],a,svg,svg *,i,span,div,img'
            ));
        } catch (_) {return [];}
        const result=[];
        for (const node of nodes.slice(0,5000)) {
            if (node.disabled || node.getAttribute?.('aria-disabled')==='true') continue;
            let rect=bounds(node);
            if (!rect) continue;
            const kind=nodeKind(node,win);
            if (!kind) continue;
            let target=node;
            // Pulsar icono hijo también funciona cuando el SDK escucha clicks
            // delegados. Ante un ancestro explícitamente interactivo, preferirlo.
            for (let parent=node.parentElement,n=0;parent&&n<5;
                parent=parent.parentElement,n++) {
                if (!bounds(parent)) break;
                const tag=String(parent.tagName||'').toUpperCase();
                let cursor='';
                try {cursor=win?.getComputedStyle?.(parent)?.cursor||'';}catch(_){}
                if(tag==='BUTTON'||tag==='A'||parent.getAttribute?.('role')==='button'||
                    parent.hasAttribute?.('onclick')||cursor==='pointer') {
                    target=parent;break;
                }
            }
            rect=bounds(target)||rect;
            result.push({node:target,kind,rect});
        }
        return result;
    }
    function pair(doc) {
        let best=null,smallest=Infinity;
        for (const {doc:inner,win} of documents(doc)) {
            const all=candidates(inner,win);
            const green=all.filter(x=>x.kind==='answer');
            const red=all.filter(x=>x.kind==='decline');
            for(const yes of green) for(const no of red) {
                if(yes.node===no.node) continue;
                const cy=x=>x.rect.top+x.rect.height/2;
                const cx=x=>x.rect.left+x.rect.width/2;
                const dx=Math.abs(cx(yes)-cx(no));
                const dy=Math.abs(cy(yes)-cy(no));
                if(dx<16||dx>195||dy>65)continue;
                if(dx+dy<smallest) {
                    best={answer:yes.node,decline:no.node};
                    smallest=dx+dy;
                }
            }
        }
        return best;
    }
    function hangingButton(doc) {
        const items=[];
        for(const {doc:inner,win} of documents(doc)) {
            for(const item of candidates(inner,win)) {
                if(item.kind!=='decline') continue;
                const cx=item.rect.left+item.rect.width/2;
                const cy=item.rect.top+item.rect.height/2;
                // Un icono SVG puede contener paths y spans del mismo botón.
                // Agrupar por ubicación evita contar varios controles ficticios.
                if(items.some(x=>Math.abs(x.cx-cx)<12 && Math.abs(x.cy-cy)<12)) continue;
                items.push({node:item.node,cx,cy});
            }
        }
        // No pulsar arbitrariamente un icono rojo ajeno a la llamada.
        return items.length===1?items[0].node:null;
    }
    function create(doc,isRinging,isTalking) {
        let lastError='';
        function perform(action) {
            lastError='';
            if(action==='hangup'?!isTalking():!isRinging()) {
                lastError='La llamada ya cambió de estado.';
                return false;
            }
            const node=action==='hangup'?hangingButton(doc):pair(doc)?.[action];
            if(!node) {
                lastError='Zadarma todavía no expone ese control en el navegador.';
                return false;
            }
            try {
                if(typeof node.click==='function') node.click();
                else if(typeof node.dispatchEvent==='function') {
                    // Algunos botones Zadarma se dibujan como SVG, sin .click().
                    const View=doc.defaultView || root;
                    if(typeof View.MouseEvent!=='function') throw new Error('No MouseEvent');
                    node.dispatchEvent(new View.MouseEvent('click', {
                        bubbles:true,cancelable:true,view:View
                    }));
                } else throw new Error('Control no interactivo');
                return true;
            } catch (_) {
                lastError='Zadarma no permitió accionar el botón.';
                return false;
            }
        }
        return Object.freeze({
            available:()=>Boolean(isRinging()&&pair(doc)),
            canHangup:()=>Boolean(isTalking()&&hangingButton(doc)),
            answer:()=>perform('answer'),
            decline:()=>perform('decline'),
            hangup:()=>perform('hangup'),
            error:()=>lastError
        });
    }
    const api=Object.freeze({create,colorKind});
    root.IMPE_ZADARMA_NATIVE_BRIDGE_FACTORY=api;
    if (typeof module!=='undefined'&&module.exports)module.exports=api;
})(typeof window!=='undefined'?window:{});
