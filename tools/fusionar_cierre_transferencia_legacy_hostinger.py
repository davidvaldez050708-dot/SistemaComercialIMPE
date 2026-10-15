#!/usr/bin/env python3
"""Fusión exacta del cierre en shell histórico sin finishedSdk.
Solo trabaja sobre copia temporal. Python 3.6 compatible.
"""
import pathlib
import sys


def fail(message):
    raise SystemExit('CONFLICTO: shell histórico incompatible (%s). PRODUCCIÓN INTACTA.' % message)


def run(path):
    raw = path.read_bytes()
    try:
        source = raw.decode('utf-8')
    except UnicodeDecodeError:
        fail('archivo no UTF-8')
    signature = (
        'let recentFinishedIncoming = null;',
        "const finishingIncoming = incoming && pending === 'hangup';",
        'const incomingFinishedPreview = !state?.active',
        "transferToggle?.setAttribute('aria-expanded', 'false');",
    )
    flags = [v in source for v in signature]
    if all(flags):
        print('YA ACTUALIZADO: cierre del shell histórico.')
        return
    if any(flags):
        fail('cierre parcialmente instalado')
    if 'const finishedSdk = Boolean(' in source:
        fail('no es un shell histórico')
    for required in (
        'const updateState = function (value) {',
        'const render = function () {',
        'const hangupIncoming = function () {',
        'const workingCall = Boolean(ongoing && state.phase ===',
        'const transferPane = document.querySelector(',
        'const capturePane = document.querySelector(',
        'const pendingFor = state =>',
    ):
        if source.count(required) != 1:
            fail('estructura ausente: ' + required)
    crlf = '\r\n' in source
    def nl(value):
        return value.replace('\n','\r\n') if crlf else value

    rules = []
    def add(name, old, new):
        rules.append((name,nl(old),nl(new)))
    add('memoria final',
        'let recentFinishedOutgoing = null;',
        'let recentFinishedOutgoing = null;\n'
        '    // La llamada entrante finalizada sólo permanece en esta sesión.\n'
        '    let recentFinishedIncoming = null;')
    add('transición PBX',
        '        // El resumen de Marketing sólo nace de una transición REAL de',
        """        // Resumen de Recepción sólo tras confirmar el fin desde PBX.
        if (!cfg.analystIncomingResults && currentState?.active &&
            currentState.direction === 'incoming' && !value.active &&
            value.direction === 'incoming' && value.phase === 'incoming-finished') {
            recentFinishedIncoming = {state:value, id:incomingCallId(value)};
            incomingCompact = false;
        } else if (value.active) {
            recentFinishedIncoming = null;
        }
        // El resumen de Marketing sólo nace de una transición REAL de""")
    add('resumen recibido',
        '        const shownCall = state?.active ? state : (preview || finishedPreview);',
        """        const incomingFinishedPreview = !state?.active
            ? recentFinishedIncoming?.state || null : null;
        const shownCall = state?.active ? state :
            (incomingFinishedPreview || preview || finishedPreview);
        const finishedIncoming = Boolean(incomingFinishedPreview);""")
    # En el shell personalizado confirmado por SHA-256 no existe la guarda
    # de panel nativo del Analista en customVisible. Preservar exactamente
    # esa decisión histórica; NO introducir la guarda de otra versión.
    visibility_variants = (
        ("""        const customVisible = (incoming || shownOutgoing) &&
            !(cfg.analystIncomingResults && nativeVisible) &&
            !minimized && pending !== 'hangup' && pending !== 'decline';""",
         """        const finishingIncoming = incoming && pending === 'hangup';
        const customVisible = (incoming || finishedIncoming || shownOutgoing) &&
            !(cfg.analystIncomingResults && nativeVisible) &&
            !minimized && pending !== 'decline';"""),
        ("""        const customVisible = (incoming || shownOutgoing) &&
            !minimized && pending !== 'hangup' && pending !== 'decline';""",
         """        const finishingIncoming = incoming && pending === 'hangup';
        const customVisible = (incoming || finishedIncoming || shownOutgoing) &&
            !minimized && pending !== 'decline';"""),
    )
    matches = [(old, new) for old, new in visibility_variants
               if source.count(nl(old)) == 1]
    if len(matches) != 1:
        fail('visibilidad: variante no reconocida (%d)' % len(matches))
    add('visibilidad', matches[0][0], matches[0][1])
    add('bloquear transferencia al colgar',
        "        const workingCall = Boolean(ongoing && state.phase === 'in-progress');",
        """        const workingCall = Boolean(ongoing && state.phase === 'in-progress' &&
            !finishingIncoming && !pendingOutgoingHangup);""")
    add('ocultar ficha lateral',
        '            if (capturePane) capturePane.hidden = true;',
        """            if (capturePane) capturePane.hidden = true;
            transferToggle?.setAttribute('aria-expanded', 'false');
            captureToggle?.setAttribute('aria-expanded', 'false');""")
    add('pintar tarjeta final',
        '        if ((incoming || shownOutgoing) && incomingPanel) {',
        '        if ((incoming || finishedIncoming || shownOutgoing) && incomingPanel) {')
    add('título final',
        """            incomingTitle.textContent = incoming
                ? (ringing ? 'Llamada entrante' : 'En conversación')""",
        """            incomingTitle.textContent = finishedIncoming ? 'Llamada finalizada' : incoming
                ? (finishingIncoming ? 'Finalizando llamada…' :
                    (ringing ? 'Llamada entrante' : 'En conversación'))""")
    add('etiqueta final',
        "            incomingEyebrow.textContent = finished ? 'LLAMADA FINALIZADA' :",
        "            incomingEyebrow.textContent = (finished || finishedIncoming) ? 'LLAMADA FINALIZADA' :")
    add('texto final',
        '            incomingState.textContent = shownOutgoing',
        """            incomingState.textContent = finishedIncoming
                ? (phoneState.status === 'transferred'
                    ? 'Finalizada · transferencia confirmada por la centralita'
                    : 'Finalizada · llamada concluida')
                : shownOutgoing""")
    add('texto finalizando',
        """                : (ringing ? 'Llamada entrante a la extensión ' + cfg.extension
                    : 'Conversación en curso');""",
        """                : (finishingIncoming ? 'Esperando confirmación de la centralita…' :
                    (ringing ? 'Llamada entrante a la extensión ' + cfg.extension
                    : 'Conversación en curso'));""")
    add('duración',
        """            if (preview || finished) {
                const duration = finished ? formatDuration(phoneState.duration) : '00:00';""",
        """            if (preview || finished || finishedIncoming) {
                const duration = (finished || finishedIncoming)
                    ? formatDuration(phoneState.duration) : '00:00';""")
    add('ocultar silenciar',
        '            muteButton.hidden = finished || finishing || ringing ||',
        """            muteButton.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || ringing ||""")
    add('ocultar colgar',
        '            hangupButton.hidden = finished || finishing || ringing;',
        """            hangupButton.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || ringing;""")
    add('ocultar silenciar compacto',
        '            compactMute.hidden = finished || finishing || ringing ||',
        """            compactMute.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || ringing ||""")
    add('ocultar colgar compacto',
        '            compactHangup.hidden = finished || finishing || ringing;',
        """            compactHangup.hidden = finished || finishedIncoming || finishing ||
                finishingIncoming || ringing;""")
    add('botón cerrar',
        '            if (closeFinished) closeFinished.hidden = !finished;',
        '            if (closeFinished) closeFinished.hidden = !(finished || finishedIncoming);')
    add('título superior',
        "        callTitle.textContent = incoming ? 'Llamada entrante' :",
        """        callTitle.textContent = finishedIncoming ? 'Llamada finalizada' :
            incoming ? 'Llamada entrante' :""")
    add('cerrar resumen',
        """    closeFinished?.addEventListener('click', function () {
        if (!recentFinishedOutgoing) return;""",
        """    closeFinished?.addEventListener('click', function () {
        if (recentFinishedIncoming) {
            recentFinishedIncoming = null;
            render();
            return;
        }
        if (!recentFinishedOutgoing) return;""")

    # Verificar TODAS las zonas antes de empezar: un Hostinger personalizado
    # debe informar de todos los cambios incompatibles en una sola revisión.
    missing = ['%s (%d)' % (name,source.count(old))
               for name,old,new in rules if source.count(old) != 1]
    if missing:
        fail('anclas incompatibles: ' + ', '.join(missing))
    result = source
    undo = []
    for name, old, new in rules:
        if result.count(old) != 1:
            fail('%s: ancla encontrada %d veces' % (name, result.count(old)))
        result = result.replace(old,new,1)
        if result.count(new) != 1:
            fail('%s: resultado ambiguo' % name)
        undo.append((name,old,new))
    for mark in signature:
        if result.count(mark) != 1:
            fail('resultado incompleto: ' + mark)
    recovered = result
    for name,old,new in reversed(undo):
        if recovered.count(new) != 1:
            fail('reversión ambigua: ' + name)
        recovered = recovered.replace(new,old,1)
    if recovered.encode('utf-8') != raw:
        fail('no se preservaría todo el código original')
    path.write_bytes(result.encode('utf-8'))
    print('FUSIÓN LEGACY COMPATIBLE: %d bloques, WebRTC intacto.' % len(rules))


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Uso: fusionar_cierre_transferencia_legacy_hostinger.py copia-temporal.js')
    run(pathlib.Path(sys.argv[1]))
