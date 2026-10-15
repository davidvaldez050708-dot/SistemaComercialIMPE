#!/usr/bin/env python3
"""Fusión conservadora del cierre de llamadas en el shell personalizado.

Sólo modifica fragmentos exactos del parche autorizado, conservando cualquier
otra personalización del teléfono WebRTC. Actúa sobre una copia temporal.
Compatible con Python 3.6 de Hostinger.
"""
import difflib
import pathlib
import sys


def fail(message):
    raise SystemExit('CONFLICTO: cierre no fusionable (%s). PRODUCCIÓN INTACTA.' % message)


def appearances(text, snippet):
    return text.count(snippet) if snippet else 0


def unique_anchor(text, lines, after):
    """Encuentra una línea inalterada y única para insertar código nuevo."""
    for length in range(1, min(7, len(lines)) + 1):
        part = ''.join(lines[:length] if after else lines[-length:])
        if appearances(text, part) == 1:
            return part
    return None


def run(staged, base, target):
    old_bytes = staged.read_bytes()
    try:
        live = old_bytes.decode('utf-8')
        source = base.read_bytes().decode('utf-8')
        updated = target.read_bytes().decode('utf-8')
    except UnicodeDecodeError:
        fail('código JS no UTF-8')

    if '\r\n' in live:
        source = source.replace('\r\n', '\n').replace('\n', '\r\n')
        updated = updated.replace('\r\n', '\n').replace('\n', '\r\n')
    elif '\r\n' in source or '\r\n' in updated:
        source = source.replace('\r\n', '\n')
        updated = updated.replace('\r\n', '\n')

    if live == updated:
        print('YA ACTUALIZADO: cierre de llamada idéntico al repositorio.')
        return

    required = (
        'window.IMPE_TELEPHONY_SHELL = Object.freeze({',
        'const pendingFor = state =>',
        'const updateState = function (value) {',
        'const render = function () {',
        'const hangupIncoming = function () {',
        '    let recentFinishedOutgoing = null;',
        'const finishedSdk = Boolean(',
        'requestAuthorizedTransfer: function (token) {',
        "document.querySelector('[data-shell-transfer-panel]')",
        "const incoming = Boolean(state?.active && state.direction === 'incoming');",
    )
    for marker in required:
        if appearances(live, marker) != 1:
            fail('estructura telefónica desconocida: %s' % marker[:66])

    signature = (
        '    let recentFinishedIncoming = null;',
        "        const finishingIncoming = incoming && pending === 'hangup';",
        '        const incomingFinishedPreview = !state?.active',
        "            transferToggle?.setAttribute('aria-expanded', 'false');",
    )
    has = [marker in live for marker in signature]
    if any(has) and not all(has):
        fail('cambio de cierre previamente instalado sólo en parte')

    base_lines = source.splitlines(True)
    target_lines = updated.splitlines(True)
    matcher = difflib.SequenceMatcher(None, base_lines, target_lines, autojunk=False)
    changes = [(i1, i2, j1, j2) for tag, i1, i2, j1, j2 in matcher.get_opcodes()
               if tag != 'equal']
    if len(changes) < 8 or len(changes) > 50:
        fail('revisión base no corresponde al parche esperado')

    merged = live
    undo = []
    applied = 0
    for step, (i1, i2, j1, j2) in enumerate(changes, 1):
        previous = ''.join(base_lines[i1:i2])
        following = ''.join(target_lines[j1:j2])
        if previous:
            if appearances(merged, previous) == 1:
                merged = merged.replace(previous, following, 1)
                undo.append((following, previous, step))
                applied += 1
            elif appearances(merged, previous) == 0 and appearances(merged, following) == 1:
                continue
            else:
                fail('líneas de cierre modificadas, bloque %d (línea %d)' % (step, i1 + 1))
        else:
            if appearances(merged, following) == 1:
                continue
            if appearances(merged, following) != 0:
                fail('inserción duplicada, bloque %d' % step)
            right = unique_anchor(merged, base_lines[i1:], True)
            if right:
                merged = merged.replace(right, following + right, 1)
                undo.append((following + right, right, step))
            else:
                left = unique_anchor(merged, base_lines[:i1], False)
                if not left:
                    fail('punto de inserción no reconocido, bloque %d' % step)
                merged = merged.replace(left, left + following, 1)
                undo.append((left + following, left, step))
            applied += 1

    for marker in signature:
        if appearances(merged, marker) != 1:
            fail('resultado sin marcador único: %s' % marker)

    # Deshacer los cambios en memoria debe producir exactamente el archivo
    # original. Si no, una personalización habría sido alterada.
    restored = merged
    for introduced, old, step in reversed(undo):
        if appearances(restored, introduced) != 1:
            fail('verificación reversible falló en bloque %d' % step)
        restored = restored.replace(introduced, old, 1)
    if restored.encode('utf-8') != old_bytes:
        fail('se alteraría una personalización existente')
    if live == source and merged != updated:
        fail('la réplica base no produce la revisión oficial')

    if applied:
        staged.write_bytes(merged.encode('utf-8'))
        print('FUSIÓN COMPATIBLE: %d bloques de cierre; personalizaciones preservadas.' % applied)
    else:
        print('YA ACTUALIZADO: cierre de llamada presente en shell personalizado.')


if __name__ == '__main__':
    if len(sys.argv) != 4:
        raise SystemExit('Uso: fusionar_cierre_transferencia_hostinger.py copia-live base.js destino.js')
    run(*[pathlib.Path(p) for p in sys.argv[1:]])
