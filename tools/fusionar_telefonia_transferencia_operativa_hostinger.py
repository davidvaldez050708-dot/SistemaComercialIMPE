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
        # El shell de Hostinger puede carecer COMPLETAMENTE de
        # showProviderControls. No sustituir el teléfono de 881 líneas ni
        # depender de su orden de funciones: añadir el método en la API.
        # Sólo una pulsación explícita abre controles nativos en entrantes.
        import re

        show_marker = 'SOPORTE_RECEPTOR_TRANSFER_20261015'
        native_marker = 'VISIBILIDAD_RECEPTOR_TRANSFER_20261015'
        preference_marker = (
            'if (autoEnabled && !cfg.alwaysReceive && '
            '!cfg.analystIncomingResults)'
        )
        marks = (show_marker in s, native_marker in s,
                 preference_marker in s)
        if all(marks) and 'let nativeTransferOpen = false;' in s and (
                '!nativeVisible &&' in s):
            print('YA ACTUALIZADO: controles del shell y recepción de extensiones')
            return
        if any(marks):
            fail('actualización parcial detectada: revisar antes de aplicar')

        # Guardas explícitas de función, estado y UI; no usar controles
        # nativos en una llamada entrante si no se solicitó expresamente.
        legacy_method = norm("""        showProviderControls: function () {
            // SOPORTE_RECEPTOR_TRANSFER_20261015
            if (!hostFrame || !freshState(currentState) ||
                !currentState.hostReady || !currentState.active) return false;
            nativeTransferOpen = true;
            forceNativeControls = true;
            minimized = false;
            sendPhoneCommand('SHOW_CONTROLS');
            render();
            return true;
        },
""")
        show_start = 'showProviderControls: function () {'
        if s.count(show_start) == 0:
            api = 'window.IMPE_TELEPHONY_SHELL = Object.freeze({'
            anchor = '        getActiveCall: function () {'
            if s.count(api) != 1 or s.count(anchor) != 1 or (
                    s.find(api) > s.find(anchor)):
                fail('abrir WebRTC: API del shell desconocida')
            show_old, show_new = anchor, legacy_method + anchor
        elif s.count(show_start) == 1:
            begin = s.find(show_start)
            end = s.find(norm("""
        },
        openDialer:"""), begin)
            if end < 0 or end - begin > 1800:
                fail('abrir WebRTC: método existente irreconocible')
            block = s[begin:end]
            for required in ('forceNativeControls = true;',
                             'minimized = false;', 'render()', 'return true',
                             'hostFrame', 'freshState(currentState)'):
                if required not in block:
                    fail('abrir WebRTC: falta ' + required)
            show_old = show_start
            show_new = show_start + norm("""
            // SOPORTE_RECEPTOR_TRANSFER_20261015
            if (hostFrame && freshState(currentState) &&
                currentState.hostReady && currentState.active) {
                nativeTransferOpen = true;
                forceNativeControls = true;
                minimized = false;
                sendPhoneCommand('SHOW_CONTROLS');
                render();
                return true;
            }""")
        else:
            fail('abrir WebRTC: método duplicado')

        # La variable explícita evita romper el diseño: una transferencia
        # puede abrir el panel oficial, pero NUNCA lo abre solo al timbrar.
        flag_old = '    let forceNativeControls = false;'
        flag_new = flag_old + norm("""
    let nativeTransferOpen = false;""")
        reset_old = norm("""        if (!ongoing) {
            forceNativeControls = false;""")
        reset_new = reset_old + norm("""
            nativeTransferOpen = false;""")

        # Respeta tanto la fórmula moderna con varias líneas como la
        # fórmula compacta real de Hostinger (const nativeVisible ... ongoing).
        vis_match = re.search(
            r'(?P<head>const nativeVisible\s*=\s*)'
            r'(?P<expr>[\s\S]{10,900}?)'
            r'(?P<end>;\s*\r?\n\s*const pending = pendingFor\(state\);)',
            s)
        if not vis_match or s.count('const nativeVisible') != 1:
            fail('visibilidad del widget: fórmula no reconocida')
        expr = vis_match.group('expr')
        for required in ('state?.hostReady', 'forceNativeControls',
                         '!minimized', 'ongoing'):
            if required not in expr:
                fail('visibilidad del widget: falta ' + required)
        old_vis = vis_match.group('head') + expr + ';'
        new_vis = (
            vis_match.group('head') + '(' + expr.strip() + ')' +
            norm("""
            || (Boolean(state?.hostReady) && ongoing && forceNativeControls &&
                nativeTransferOpen && !minimized)""") +
            '; // VISIBILIDAD_RECEPTOR_TRANSFER_20261015'
        )
        if 'customVisible' not in s or 'call.classList.toggle' not in s:
            fail('visibilidad del modal: elementos ausentes')

        guard = norm("""!(cfg.analystIncomingResults && nativeVisible) &&
            !minimized""")
        if s.count(guard) == 1:
            custom_old = guard
            custom_new = norm("""!nativeVisible &&
            !minimized""")
        else:
            custom_old = choose(s, [
                norm("""!minimized && pending !== 'decline'"""),
                norm("""!minimized && pending !== 'hangup' && pending !== 'decline'""")
            ], 'visibilidad del modal')
            custom_new = '!nativeVisible && ' + custom_old

        # Autoconectar todas las extensiones receptoras, manteniendo
        # intacta la preferencia manual de los demás casos.
        pref_start = 'let autoEnabled = cfg.autoReception === true;'
        pos = s.find(pref_start)
        if pos < 0 or s.count(pref_start) != 1:
            fail('autoconectar receptor: configuración ausente')
        pref_section = s[pos:pos + 600]
        pref_match = re.search(
            r'if\s*\(\s*autoEnabled'
            r'(?:\s*&&\s*!cfg\.analystIncomingResults)?\s*\)\s*\{'
            r'(?=\s*try\s*\{\s*autoEnabled\s*=\s*localStorage\.getItem)',
            pref_section
        )
        if not pref_match:
            fail('autoconectar receptor: preferencia desconocida')
        pref_old = pref_match.group(0)
        pref_new = preference_marker + ' {'

        rules = [
            ('indicador transferencias', flag_old, flag_new),
            ('reinicio de visibilidad', reset_old, reset_new),
            ('abrir WebRTC', show_old, show_new),
            ('visibilidad del widget', old_vis, new_vis),
            ('ocultar modal durante teclado', custom_old, custom_new),
            ('autoconectar receptor', pref_old, pref_new),
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
