/* Teléfono institucional reutilizable. La llamada continúa bajo el motor
 * Zadarma existente; este módulo sólo proporciona controles visuales seguros.
 * En el shell se monta una sola vez, fuera del iframe de navegación PHP.
 */
(function (global) {
    'use strict';
    function normalizar(numero) {
        const raw = String(numero || '').trim();
        if (!/^\+?[\d\s().-]+$/.test(raw)) return '';
        let digits = raw.replace(/\D/g, '');
        if (digits.length === 10) digits = '52' + digits;
        if (!/^[1-9]\d{10,14}$/.test(digits) ||
            /^52(\d)\1{9}$/.test(digits)) return '';
        return digits;
    }
    function create(options) {
        const opt = options || {};
        const root = opt.root || document.body;
        let visible = false;
        let busy = false;
        let callState = null;
        let capabilities = false;
        let context = {type:'TELEPHONY_MANUAL'};
        let previousFocus = null;
        let lastPhone = '';
        const wrap = document.createElement('div');
        wrap.className = 'impe-dialer';
        wrap.setAttribute('data-impe-dialer', '');
        wrap.hidden = true;
        wrap.innerHTML =
            '<div class="impe-dialer-backdrop" data-dialer-dismiss></div>' +
            '<section class="impe-dialer-dialog" role="dialog" aria-modal="true" aria-labelledby="impeDialerTitle" tabindex="-1">' +
              '<header class="impe-dialer-heading">' +
                '<span class="impe-dialer-symbol" aria-hidden="true"><i class="bi bi-telephone-fill"></i></span>' +
                '<div class="impe-dialer-heading-text"><small>TELÉFONO INSTITUCIONAL</small>' +
                  '<h2 id="impeDialerTitle">Nueva llamada</h2>' +
                  '<p data-dialer-ext></p></div>' +
                '<button type="button" class="impe-dialer-control" data-dialer-close aria-label="Cerrar o minimizar teléfono" title="Cerrar o minimizar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>' +
              '</header>' +
              '<form class="impe-dialer-body" data-dialer-form autocomplete="off">' +
                '<div class="impe-dialer-state" data-dialer-state role="status" aria-live="polite" hidden><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span data-dialer-state-label></span></div>' +
                '<label for="impeDialerNumber">Número de teléfono</label>' +
                '<div class="impe-dialer-number-row"><input id="impeDialerNumber" type="tel" inputmode="tel" maxlength="25" placeholder="Ej. 55 1234 5678" data-dialer-input></div>' +
                '<p class="impe-dialer-context" data-dialer-context hidden></p>' +
                '<div class="impe-dialer-keys" role="group" aria-label="Teclado telefónico">' +
                  ['1','2','3','4','5','6','7','8','9','+','0','⌫'].map(function (k) {
                      return '<button type="button" data-dialer-key="' + k +
                          '" class="' + (k === '⌫' ? 'impe-dialer-key-delete' : '') +
                          '" aria-label="' + (k === '⌫' ? 'Borrar dígito' : k) + '">' +
                          (k === '⌫' ? '<i class="bi bi-backspace" aria-hidden="true"></i>' : k) +
                          '</button>';
                  }).join('') +
                '</div>' +
                '<button class="impe-dialer-primary" data-dialer-call type="submit"><i class="bi bi-telephone-outbound" aria-hidden="true"></i><span>Llamar</span></button>' +
                '<p class="impe-dialer-help">México: 10 dígitos. Internacional: incluye el prefijo.</p>' +
              '</form>' +
              '<section class="impe-dialer-live" data-dialer-live hidden>' +
                '<p class="impe-dialer-label">LLAMADA EN CURSO</p>' +
                '<strong data-dialer-live-number>—</strong>' +
                '<div class="impe-dialer-state" data-dialer-live-state aria-live="polite"></div>' +
                '<div class="impe-dialer-actions">' +
                  '<button type="button" data-dialer-mute><i class="bi bi-mic-mute" aria-hidden="true"></i><span data-dialer-mute-label>Silenciar</span></button>' +
                  '<button type="button" data-dialer-transfer><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Transferir</button>' +
                  '<button type="button" class="is-danger" data-dialer-hangup><i class="bi bi-telephone-x" aria-hidden="true"></i>Colgar</button>' +
                '</div>' +
                '<div class="impe-dialer-transfer" data-dialer-transfer-panel hidden>' +
                  '<label for="impeDialerTransfer">Extensión de destino</label>' +
                  '<input id="impeDialerTransfer" type="text" maxlength="6" inputmode="numeric" placeholder="Ej. 101" data-dialer-transfer-input>' +
                  '<div class="impe-dialer-transfer-actions">' +
                    '<button type="button" data-dialer-transfer-direct>Transferir directamente</button>' +
                    '<button type="button" data-dialer-transfer-consult>Consultar primero</button>' +
                  '</div>' +
                  '<small>Se aplican los permisos de transferencia y las capacidades de Zadarma.</small>' +
                '</div>' +
              '</section>' +
              '<footer class="impe-dialer-footer">' +
                '<button type="button" data-dialer-minimize>Minimizar y seguir trabajando</button>' +
              '</footer>' +
            '</section>' +
            '<button type="button" class="impe-dialer-restore" data-dialer-restore hidden><i class="bi bi-telephone-fill" aria-hidden="true"></i> Mostrar teléfono</button>';
        root.appendChild(wrap);
        const qs = function (s) {return wrap.querySelector(s);};
        const input = qs('[data-dialer-input]');
        const stateText = qs('[data-dialer-state]');
        const submit = qs('[data-dialer-call]');
        const live = qs('[data-dialer-live]');
        const form = qs('[data-dialer-form]');
        const note = qs('[data-dialer-context]');
        const restore = qs('[data-dialer-restore]');
        const transfer = qs('[data-dialer-transfer-panel]');
        function phoneApi() {
            try { return typeof opt.getApi === 'function' ? opt.getApi() : null; }
            catch (error) { return null; }
        }
        function activeState() {
            try { return typeof opt.getState === 'function'
                ? opt.getState() : phoneApi()?.getState?.(); }
            catch (error) { return null; }
        }
        function setMessage(message, isError) {
            const showError = Boolean(isError && message);
            qs('[data-dialer-state-label]').textContent = showError
                ? String(message) : '';
            stateText.hidden = !showError;
            stateText.classList.toggle('is-error', showError);
            const visibleCallState = qs('[data-dialer-live-state]');
            if (!live.hidden) {
                visibleCallState.textContent = String(message || '');
                visibleCallState.classList.toggle('is-error', Boolean(isError));
            }
        }
        function render() {
            const active = Boolean(callState?.active && callState.direction !== 'incoming');
            const finished = callState?.phase === 'finished';
            wrap.classList.toggle('is-active-call', active);
            form.hidden = active;
            live.hidden = !active;
            qs('[data-dialer-ext]').textContent = 'Extensión ' + String(opt.extension || '—');
            const enabled = capabilities && !busy;
            input.disabled = active || busy;
            submit.disabled = !enabled || active;
            qs('[data-dialer-live-number]').textContent = String(callState?.destination || lastPhone || '—');
            qs('[data-dialer-live-state]').textContent = String(callState?.message ||
                (callState?.status === 'in-progress' ? 'En conversación' : 'Preparando llamada…'));
            qs('[data-dialer-mute-label]').textContent = callState?.muted ? 'Activar micrófono' : 'Silenciar';
            qs('[data-dialer-transfer]').disabled = !callState?.active ||
                callState?.status !== 'in-progress';
            qs('[data-dialer-mute]').disabled = !callState?.active ||
                callState?.status !== 'in-progress';
            qs('[data-dialer-hangup]').disabled = !callState?.active;
            if (!active) transfer.hidden = true;
            if (finished && !visible) restore.hidden = true;
        }
        function show() {
            wrap.hidden = false;
            wrap.classList.add('is-open');
            wrap.classList.remove('is-minimized');
            restore.hidden = true;
            visible = true;
            render();
            if (form.hidden) qs('.impe-dialer-dialog').focus();
            else input.focus();
        }
        function handoffToCall() {
            // El marcador sólo introduce números. El panel persistente único
            // del shell controla la llamada entrante o saliente.
            visible = false;
            wrap.classList.remove('is-open');
            wrap.classList.remove('is-minimized');
            restore.hidden = true;
            wrap.hidden = true;
            previousFocus?.focus?.();
        }
        function hide() {
            visible = false;
            wrap.classList.remove('is-open');
            wrap.classList.add('is-minimized');
            restore.hidden = !(callState?.active && callState.direction !== 'incoming');
            if (!restore.hidden) wrap.hidden = false;
            else wrap.hidden = true;
            previousFocus?.focus?.();
        }
        async function checkCapability() {
            try {
                if (!opt.hasOutgoingRole || !opt.hasOutgoingRole()) {
                    capabilities = false;
                    setMessage('Tu perfil no tiene permiso para realizar llamadas salientes.', true);
                    render();
                    return;
                }
                const api = phoneApi();
                if (!api?.probe) throw new Error('El teléfono se está preparando. Intenta de nuevo.');
                const info = await api.probe();
                capabilities = Boolean(info?.permite_salientes);
                setMessage(capabilities ? '' :
                    'La extensión no tiene habilitadas las llamadas salientes.', !capabilities);
            } catch (error) {
                capabilities = false;
                setMessage(error?.message || 'No se pudo consultar la extensión.', true);
            }
            render();
        }
        function open(params) {
            const data = params || {};
            const current = activeState();
            if (current?.active && current.direction === 'incoming') {
                opt.showIncoming?.();
                return;
            }
            if (opt.singleCallPanel && current?.active &&
                current.direction === 'outgoing') {
                handoffToCall();
                opt.showOngoing?.();
                return;
            }
            callState = current?.active ? current : null;
            if (!callState) {
                context = data.context && typeof data.context === 'object'
                    ? {
                        type: data.context.type === 'MARKETING_CALLBACK'
                            ? 'MARKETING_CALLBACK' : 'TELEPHONY_MANUAL',
                        llamada_origen: String(data.context.llamada_origen || '').slice(0,120)
                    } : {type:'TELEPHONY_MANUAL'};
                // Una llamada manual nueva nunca hereda por accidente el
                // número usado en una devolución anterior.
                input.value = typeof data.number === 'string'
                    ? data.number.slice(0,25) : '';
                note.hidden = context.type !== 'MARKETING_CALLBACK';
                note.textContent = note.hidden ? '' :
                    'Comprueba el número y pulsa Llamar.';
            }
            previousFocus = document.activeElement;
            show();
            void checkCapability();
        }
        async function dial(event) {
            event?.preventDefault?.();
            if (busy || callState?.active) return;
            const phone = normalizar(input.value);
            if (!phone) {setMessage('Escribe un teléfono válido antes de llamar.',true); return;}
            if (!capabilities) {await checkCapability(); if (!capabilities) return;}
            const api = phoneApi();
            if (!api?.startCall) {setMessage('El teléfono aún no está listo.',true);return;}
            busy = true;
            lastPhone = phone;
            submit.disabled = true;
            setMessage('', false);
            try {
                await api.startCall({
                    destination: phone,
                    institution: context.type === 'MARKETING_CALLBACK' ? 'Recepción telefónica' : '',
                    context: context
                });
                if (opt.singleCallPanel) {
                    handoffToCall();
                    opt.onCallRequested?.({ number: phone });
                } else {
                    setMessage('Llamada solicitada. Esperando confirmación de Zadarma…');
                }
            } catch (error) {
                setMessage(error?.message || 'No se pudo iniciar la llamada.',true);
            } finally {
                busy = false;
                render();
            }
        }
        function sync(next) {
            if (!next || typeof next !== 'object') return;
            if (next.direction === 'incoming') {
                if (visible) hide();
                return;
            }
            if (next.active && next.direction !== 'incoming' &&
                (visible || !wrap.hidden)) {
                callState = next;
                if (opt.singleCallPanel) handoffToCall();
                else render();
            } else if (callState?.active && !next.active) {
                callState = next;
                if (visible) {
                    setMessage('Llamada finalizada.');
                    render();
                } else hide();
            }
        }
        qs('[data-dialer-form]').addEventListener('submit', dial);
        wrap.querySelectorAll('[data-dialer-key]').forEach(function (key) {
            key.addEventListener('click',function () {
                if (key.dataset.dialerKey === '⌫') input.value = input.value.slice(0,-1);
                else input.value += key.dataset.dialerKey;
                input.focus();
            });
        });
        qs('[data-dialer-close]').addEventListener('click', hide);
        qs('[data-dialer-dismiss]').addEventListener('click', function () {
            if (!callState?.active) hide();
        });
        qs('[data-dialer-minimize]').addEventListener('click', hide);
        restore.addEventListener('click', show);
        qs('[data-dialer-mute]').addEventListener('click', function () {
            phoneApi()?.toggleMute?.();
        });
        qs('[data-dialer-hangup]').addEventListener('click', function () {
            phoneApi()?.hangup?.();
        });
        qs('[data-dialer-transfer]').addEventListener('click', function () {
            transfer.hidden = !transfer.hidden;
            if (!transfer.hidden) qs('[data-dialer-transfer-input]').focus();
        });
        async function transferCall(attended) {
            const extension = String(qs('[data-dialer-transfer-input]').value || '').trim();
            if (!/^\d{3,6}$/.test(extension)) {
                setMessage('Introduce una extensión válida para transferir.',true);return;
            }
            try {
                const api = phoneApi();
                if (!api?.transferCall) {
                    throw new Error('No está disponible la transferencia telefónica.');
                }
                await api.transferCall(extension,attended);
                transfer.hidden = true;
                setMessage('Transferencia solicitada.');
            } catch (error) {
                setMessage(error?.message || 'No fue posible transferir.',true);
            }
        }
        qs('[data-dialer-transfer-direct]').addEventListener('click',function () {void transferCall(false);});
        qs('[data-dialer-transfer-consult]').addEventListener('click',function () {void transferCall(true);});
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && visible && !callState?.active) hide();
        });
        return {
            open:open, sync:sync, close:hide, element:wrap,
            reportError:function (message) {
                // Si Zadarma rechazó la marcación, permitir corregir el número.
                callState = null;
                show();
                setMessage(message || 'No se pudo iniciar la llamada.', true);
            },
            isManagingCall:function () {
                return visible || (!restore.hidden && !wrap.hidden);
            }
        };
    }
    global.IMPE_TELEPHONY_DIALER = {create:create, normalize:normalizar};
})(typeof window !== 'undefined' ? window : globalThis);
