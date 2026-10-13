/**
 * Clasificación de llamadas entrantes de Vinculación.
 * El cliente nunca inventa duración, número ni identidad de la llamada:
 * el servidor vuelve a obtener todo de los webhooks firmados.
 */
(() => {
    'use strict';
    const page = document.querySelector('[data-linkage-incoming]');
    const form = document.querySelector('[data-incoming-form]');
    const modalEl = document.getElementById('modalLlamadaEntranteAnalista');
    if (!page || !form || !modalEl || !window.bootstrap) return;

    const modal = new bootstrap.Modal(modalEl);
    const decision = form.querySelector('[data-incoming-decision]');
    const existing = form.querySelector('[data-incoming-existing]');
    const fresh = form.querySelector('[data-incoming-new]');
    const results = form.querySelector('[data-incoming-results]');
    const errorBox = form.querySelector('[data-incoming-error]');
    const lookup = form.querySelector('[data-incoming-lookup]');
    const followup = form.querySelector('[data-incoming-followup]');
    const submit = form.querySelector('[data-incoming-submit]');
    const resultInput = form.querySelector('[name="resultado"]');
    const evidence = form.querySelector('[data-incoming-evidence]');
    const sdkNotice = form.querySelector('[data-incoming-sdk-notice]');
    const sdkConfirm = form.querySelector('[data-incoming-sdk-confirm]');
    const sdkCheck = sdkConfirm.querySelector('input[type="checkbox"]');
    let isSdkCall = false;
    const discard = form.querySelector('[data-incoming-discard]');
    const discardToggle = form.querySelector('[data-incoming-discard-toggle]');
    const discardReason = form.querySelector('[data-incoming-discard-reason]');
    const discardText = form.querySelector('[name="motivo_descarte"]');
    const searchUrl = page.dataset.searchUrl || '';
    const saveUrl = page.dataset.saveUrl || '';
    let searchTimer = null;
    let searchSequence = 0;
    let isSubmitting = false;
    let hasAnswer = true;

    const showError = (message) => {
        errorBox.textContent = String(message || 'No fue posible guardar la llamada.');
        errorBox.classList.remove('d-none');
    };
    const clearError = () => {
        errorBox.textContent = '';
        errorBox.classList.add('d-none');
    };

    const renderEvidence = () => {
        const enabled = decision.value !== 'sin_identificar';
        const result = String(resultInput.value || '');
        const allowEvidence = !isSdkCall && enabled && [
            'CONTACTO_CORRECTO', 'SOLICITO_INFORMACION',
            'SOLICITO_LLAMAR_DESPUES', 'NO_INTERESADO'
        ].includes(result);
        evidence.hidden = !allowEvidence;
        if (!allowEvidence) {
            evidence.querySelectorAll('input[type="checkbox"]')
                .forEach(input => { input.checked = false; });
        }
        discard.hidden = !enabled || result !== 'NO_INTERESADO';
        if (discard.hidden) discardToggle.checked = false;
        discardReason.hidden = discard.hidden || !discardToggle.checked;
        discardText.required = !discardReason.hidden;
        if (discardReason.hidden) discardText.value = '';
    };
    resultInput.addEventListener('change', renderEvidence);
    discardToggle.addEventListener('change', renderEvidence);

    const renderDecision = () => {
        const value = decision.value;
        existing.hidden = value !== 'existente';
        fresh.hidden = value !== 'nueva';
        results.hidden = value === 'sin_identificar';
        const followupRequired = value === 'existente';
        followup.required = followupRequired;
        form.querySelector('[name="nombre_entidad"]').required = value === 'nueva';
        form.querySelector('[name="estado_id"]').required = value === 'nueva';
        form.querySelector('[name="resultado"]').required = value !== 'sin_identificar';
        sdkConfirm.hidden = !isSdkCall || value === 'sin_identificar';
        sdkCheck.required = !sdkConfirm.hidden;
        if (sdkConfirm.hidden) sdkCheck.checked = false;
        renderEvidence();
        clearError();
    };
    decision.addEventListener('change', renderDecision);

    const searchFollowups = async (query, seq) => {
        if (query.trim().length < 2) {
            followup.replaceChildren(new Option('Escribe al menos dos caracteres', ''));
            return;
        }
        const uri = new URL(searchUrl, window.location.href);
        uri.searchParams.set('q', query.trim());
        const res = await fetch(uri, {
            credentials: 'same-origin', cache: 'no-store',
            headers: {Accept: 'application/json'}
        });
        const data = await res.json();
        if (seq !== searchSequence) return;
        if (!res.ok || !data.ok) throw new Error(data.mensaje || 'No fue posible buscar.');
        followup.replaceChildren(new Option('Selecciona un expediente', ''));
        for (const row of (data.resultados || [])) {
            const option = new Option(
                String(row.nombre_entidad || 'Institución') +
                    ' · ' + String(row.estado_nombre || ''),
                String(row.id || '')
            );
            followup.add(option);
        }
        if (!data.resultados?.length) {
            followup.replaceChildren(new Option('No se encontraron expedientes', ''));
        }
    };
    lookup.addEventListener('input', () => {
        const seq = ++searchSequence;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            searchFollowups(lookup.value, seq).catch(err => {
                if (seq === searchSequence) showError(err.message);
            });
        }, 250);
    });

    const openRegistration = button => {
        if (!button) return;
        form.reset();
        clearError();
        const id = String(button.dataset.callId || '');
        isSdkCall = button.dataset.source === 'SDK_WEBRTC';
        form.querySelector('[data-incoming-pbx]').value = isSdkCall ? '' : id;
        form.querySelector('[data-incoming-sdk-id]').value = isSdkCall ? id : '';
        sdkNotice.hidden = !isSdkCall;
        form.querySelector('[data-incoming-number]').textContent =
            String(button.dataset.phone || 'Número privado');
        form.querySelector('[data-incoming-duration]').textContent =
            String(button.dataset.duration || '00:00');
        followup.replaceChildren(new Option('Busca un expediente', ''));
        lookup.value = '';
        ++searchSequence;
        clearTimeout(searchTimer);
        hasAnswer = button.dataset.answer === '1';
        decision.value = hasAnswer ? 'existente' : 'sin_identificar';
        for (const option of Array.from(decision.options)) {
            if (option.value !== 'sin_identificar') option.disabled = !hasAnswer;
        }
        modalEl.querySelector('[data-incoming-modal-description]').textContent = hasAnswer
            ? (isSdkCall
                ? 'Clasificación manual de una sesión observada por WebRTC, sin evento PBX firmado.'
                : 'Relaciona la conversación con el seguimiento existente o crea uno nuevo.')
            : 'Esta llamada no fue contestada. Puedes clasificarla sin crear expediente.';
        renderDecision();
        modal.show();
    };

    page.addEventListener('click', event => {
        const button = event.target.closest('[data-call-id]');
        if (button && page.contains(button)) openRegistration(button);
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (isSubmitting) return;
        clearError();
        if (!form.reportValidity()) return;
        isSubmitting = true;
        submit.disabled = true;
        const original = submit.innerHTML;
        submit.textContent = 'Guardando…';
        try {
            const res = await fetch(saveUrl, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'fetch'},
                body: new FormData(form)
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.mensaje || 'No fue posible registrar la llamada.');
            }
            modal.hide();
            // La clasificación técnica y el expediente ya están confirmados
            // por el servidor; reconstruir las filas de la fuente de verdad.
            window.location.assign(
                new URL('index.php?controller=seguimientoVinculacion&action=llamadasEntrantes',
                    window.location.href).toString()
            );
        } catch (error) {
            showError(error.message || 'No fue posible guardar. Intenta nuevamente.');
        } finally {
            submit.disabled = false;
            submit.innerHTML = original;
            isSubmitting = false;
        }
    });

    renderDecision();
    const focusId = String(page.dataset.autoCall || '').trim();
    if (focusId) {
        const found = Array.from(page.querySelectorAll('[data-call-id]'))
            .find(el => el.dataset.callId === focusId);
        if (found) window.setTimeout(() => openRegistration(found), 100);
    }
})();
