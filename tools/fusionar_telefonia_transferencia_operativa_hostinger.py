#!/usr/bin/env python3
"""Fusión mínima de la recepción operativa: sólo copia de staging.
Preserva variantes históricas del shell Hostinger sin reemplazar WebRTC.
Python 3.6 compatible.
"""
import pathlib
import sys


def fail(message):
    raise SystemExit('CONFLICTO: recepción operativa (' + message +
                     '). PRODUCCIÓN INTACTA.')


def once(s, old, new, label, undo):
    if old not in s or s.count(old) != 1:
        fail(label + ': ancla ausente o duplicada')
    result = s.replace(old, new, 1)
    undo.append((new, old, label))
    return result


def choose(s, variants, label):
    matches = [v for v in variants if s.count(v) == 1]
    if len(matches) != 1:
        fail(label + ': variante no reconocida')
    return matches[0]


def run(path, kind):
    raw = path.read_bytes()
    try:
        s = raw.decode('utf-8')
    except UnicodeDecodeError:
        fail('archivo no UTF-8')
    newline = '\r\n' if '\r\n' in s else '\n'
    def norm(x):
        return x.replace('\n', newline)

    if kind == 'view':
        if 'alwaysReceive' in s and '$puedeRecibirShell' in s:
            print('YA ACTUALIZADO: vista de recepción por rol')
            return
        old_header = "$esAsesorShell = in_array($rolTelefonoShell, ['ASESOR','ASESOR DE VENTAS'], true);"
        new_header = old_header + norm("""
            // Recepción para toda extensión autorizada, no sólo Marketing.
            $puedeRecibirShell = !empty($asignacionTelefonica['permite_entrantes']) &&
                tienePermiso('telefonia.recibir') && tienePermiso('telefonia.usar');""").replace(
                    newline + '            ', newline)
        # Mantener el sangrado del archivo fuente en los dos cambios.
        new_header = old_header + norm("""
$puedeRecibirShell = !empty($asignacionTelefonica['permite_entrantes']) &&
    tienePermiso('telefonia.recibir') && tienePermiso('telefonia.usar');""")
        rules = [
            ('cabecera', old_header, new_header),
            ('activación WebRTC',
             "'autoReception' => $marketingShell || $analistaEntrantesShell,",
             "'autoReception' => $puedeRecibirShell," + norm("""
            'alwaysReceive' => $puedeRecibirShell && !$marketingShell,"""))
        ]
    elif kind == 'shell':
        # No reescribir bloques WebRTC personalizados de Hostinger. Las
        # versiones históricas difieren en la condición para abrir controles
        # y en cómo calculan nativeVisible; insertar sólo una mejora acotada.
        show_marker = 'SOPORTE_RECEPTOR_TRANSFER_20261015'
        native_marker = 'VISIBILIDAD_RECEPTOR_TRANSFER_20261015'
        preference_marker = 'if (autoEnabled && !cfg.alwaysReceive && !cfg.analystIncomingResults)'
        marks = (show_marker in s, native_marker in s, preference_marker in s)
        if all(marks) and '!nativeVisible &&' in s:
            print('YA ACTUALIZADO: controles del shell y recepción de extensiones')
            return
        if any(marks):
            fail('actualización parcial detectada: revisar antes de aplicar')

        # Autorizar únicamente un método ya existente con el mismo nombre y
        # estructura; conservar su cuerpo original por compatibilidad SDK.
        show_start = 'showProviderControls: function () {'
        show_end = norm("""
        },
        openDialer:""")
        if s.count(show_start) != 1:
            fail('abrir WebRTC: método no único')
        begin = s.find(show_start)
        end = s.find(show_end, begin)
        if end < 0 or end - begin > 1600:
            fail('abrir WebRTC: bloque inesperado')
        show_body = s[begin:end]
        for required in ('forceNativeControls', 'render()', 'return true',
                         'hostFrame', 'freshState(currentState)'):
            if required not in show_body:
                fail('abrir WebRTC: falta comprobación ' + required)
        show_new = show_start + norm("""
            // SOPORTE_RECEPTOR_TRANSFER_20261015
            // Extensión activa y host autorizado: permitir abrir el widget
            // oficial sin borrar el flujo antiguo de Analista.
            if (hostFrame && freshState(currentState) &&
                currentState.hostReady && currentState.active) {
                nativeAnalystOpen = true;
                forceNativeControls = true;
                minimized = false;
                sendPhoneCommand('SHOW_CONTROLS');
                render();
                return true;
            }""")
        # Se actualiza exclusivamente el encabezado; no se toca su contenido.
        show_old = show_start

        # Ampliar la visibilidad para entrantes solicitadas por el usuario
        # sin cambiar las otras condiciones nativas del archivo personalizado.
        import re
        vmatch = re.search(
            r'(?P<prefix>\bconst nativeVisible\s*=\s*)'
            r'(?P<expr>[\s\S]{50,900}?)'
            r'(?P<suffix>;\s*\n\s*const pending = pendingFor\(state\);)',
            s
        )
        if not vmatch or s.count('const nativeVisible') != 1:
            fail('visibilidad del widget: fórmula desconocida')
        current_expr = vmatch.group('expr').strip()
        for required in ('state?.hostReady', 'forceNativeControls',
                         'minimized', 'ongoing'):
            if required not in current_expr:
                fail('visibilidad del widget: falta ' + required)
        old_visibility = vmatch.group('prefix') + vmatch.group('expr') + ';'
        new_visibility = (
            vmatch.group('prefix') + '(' + current_expr + ')' +
            norm("""
            || (Boolean(state?.hostReady) && forceNativeControls &&
                !minimized && ongoing && state?.direction === 'incoming')""") +
            '; // VISIBILIDAD_RECEPTOR_TRANSFER_20261015'
        )

        guarded = norm("""!(cfg.analystIncomingResults && nativeVisible) &&
            !minimized""")
        if s.count(guarded) == 1:
            visibility = guarded
            visibility_new = norm("""!nativeVisible &&
            !minimized""")
        else:
            visibility = choose(s, [
                norm("""!minimized && pending !== 'decline'"""),
                norm("""!minimized && pending !== 'hangup' && pending !== 'decline'"""),
            ], 'visibilidad existente')
            visibility_new = '!nativeVisible && ' + visibility
        pref = choose(s, [
            'if (autoEnabled && !cfg.analystIncomingResults) {',
            'if (autoEnabled) {' + norm("""
        try {
            autoEnabled = localStorage.getItem("""),
        ], 'preferencia de recepción')
        if pref.startswith('if (autoEnabled) {'):
            pref_new = pref.replace('if (autoEnabled) {',
                                    preference_marker + ' {', 1)
        else:
            pref_new = preference_marker + ' {'
        rules = [
            ('abrir WebRTC', show_old, show_new),
            ('visibilidad del widget', old_visibility, new_visibility),
            ('ocultar modal durante teclado', visibility, visibility_new),
            ('autoconectar receptor', pref, pref_new),
        ]
    else:
        fail('tipo desconocido')

    rules = [(label, norm(old), norm(new)) for label, old, new in rules]
    missing = [label for label,old,new in rules if s.count(old) != 1]
    if missing:
        fail('anclas no compatibles: ' + ', '.join(missing))
    original = s
    undo = []
    for label, old, new in rules:
        s = once(s, old, new, label, undo)
    for new, old, label in reversed(undo):
        if s.count(new) != 1:
            fail('reversión ambigua ' + label)
        s = s.replace(new,old,1)
    if s.encode('utf-8') != raw:
        fail('reversibilidad incompleta')
    final = original
    for label, old, new in rules:
        final = final.replace(old,new,1)
    path.write_bytes(final.encode('utf-8'))
    print('FUSIÓN COMPATIBLE: %s; WebRTC existente conservado.' % kind)


if __name__ == '__main__':
    if len(sys.argv) != 3:
        raise SystemExit('Uso: fusionar_telefonia_transferencia_operativa_hostinger.py shell.php|telefonia_shell.js view|shell')
    run(pathlib.Path(sys.argv[1]), sys.argv[2])
