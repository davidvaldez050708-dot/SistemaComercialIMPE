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
        if 'if (autoEnabled && !cfg.alwaysReceive && !cfg.analystIncomingResults)' in s and (
            "!nativeVisible &&" in s) and "sendPhoneCommand('SHOW_CONTROLS');" in s:
            print('YA ACTUALIZADO: controles del shell y recepción de extensiones')
            return
        show_old = norm("""showProviderControls: function () {
            if (!cfg.analystIncomingResults || !hostFrame ||
                !freshState(currentState) || !currentState.hostReady) return false;
            nativeAnalystOpen = true;
            forceNativeControls = true;
            minimized = false;
            render();
            return true;
        },""")
        show_new = norm("""showProviderControls: function () {
            if (!hostFrame || !freshState(currentState) ||
                !currentState.hostReady || !currentState.active) return false;
            nativeAnalystOpen = true;
            forceNativeControls = true;
            minimized = false;
            sendPhoneCommand('SHOW_CONTROLS');
            render();
            return true;
        },""")
        native_old = norm("""(cfg.analystIncomingResults && nativeAnalystOpen) ||
                (ongoing && state?.direction !== 'incoming')""")
        native_new = norm("""(cfg.analystIncomingResults && nativeAnalystOpen) ||
                ongoing""")
        visibility = choose(s, [
            norm("""!(cfg.analystIncomingResults && nativeVisible) &&
            !minimized"""),
            norm("""!minimized && pending !== 'decline'"""),
            norm("""!minimized && pending !== 'hangup' && pending !== 'decline'"""),
        ], 'visibilidad existente')
        if visibility.startswith('!('):
            visibility_new = norm("""!nativeVisible &&
            !minimized""")
        else:
            visibility_new = '!nativeVisible && ' + visibility
        pref = choose(s, [
            'if (autoEnabled && !cfg.analystIncomingResults) {',
            'if (autoEnabled) {' + norm("""
        try {
            autoEnabled = localStorage.getItem("""),
        ], 'preferencia de recepción')
        if pref.startswith('if (autoEnabled) {'):
            pref_new = pref.replace('if (autoEnabled) {',
                                    'if (autoEnabled && !cfg.alwaysReceive && !cfg.analystIncomingResults) {',1)
        else:
            pref_new = 'if (autoEnabled && !cfg.alwaysReceive && !cfg.analystIncomingResults) {'
        rules = [
            ('abrir WebRTC', show_old, show_new),
            ('visibilidad del widget', native_old, native_new),
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
