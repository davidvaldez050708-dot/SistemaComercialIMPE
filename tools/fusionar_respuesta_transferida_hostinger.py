#!/usr/bin/env python3
"""Fusiona SOLO la transición Contestar del host WebRTC personalizado.

Uso (sobre una COPIA del archivo, jamás directamente sobre producción):
  python3 tools/fusionar_respuesta_transferida_hostinger.py COPIA_HOST_JS BASE_JS TARGET_JS

BASE y TARGET son las versiones Git de telefonia_host.js antes y después
del arreglo. La coincidencia de los bloques críticos es OBLIGATORIA.
No altera funciones personalizadas ajenas a responder llamadas SDK.
"""
import pathlib
import re
import sys


class Conflicto(Exception):
    pass


def require(condition: bool, reason: str) -> None:
    if not condition:
        raise Conflicto(reason)


def span_between(source: str, start: str, stop: str, region: str) -> str:
    require(source.count(start) == 1, region + ': inicio no único')
    a = source.index(start)
    b = source.find(stop, a + len(start))
    require(b > a, region + ': fin no encontrado')
    return source[a:b]


def strip_comments_and_whitespace(chunk: str) -> str:
    # El bloque esperado usa comillas para mensajes y objetos literales.
    # Quitar SOLO comentarios de línea y espacios para permitir variaciones
    # cosméticas; cualquier operación personalizada distinta se rechaza.
    chunk = re.sub(r'(?m)^\s*//[^\n]*\n', '', chunk)
    chunk = re.sub(r'\s+', '', chunk)
    return chunk


def find_answer_branch(body: str) -> tuple[str, str, str]:
    prefix = re.search(
        r'if\s*\(\s*sdkOnly\s*&&\s*state\.active\s*&&'
        r'\s*String\(state\.callToken\s*\|\|\s*[\'"]{2}\)\s*===\s*token\s*\)\s*\{',
        body
    )
    require(prefix is not None, 'Contestar: guarda SDK personalizada incompatible')
    end = re.search(
        r'\n\s*\}\s*\n\s*actualizarEstadoTrasAccion\(\);',
        body[prefix.start():]
    )
    require(end is not None, 'Contestar: falta confirmación del estado')
    end_index = prefix.start() + end.start()
    closing = re.match(r'\n\s*\}', body[end_index:])
    require(closing is not None, 'Contestar: bloque SDK abierto')
    after = end_index + closing.end()
    return body[:prefix.start()], body[prefix.start():after], body[after:]


def section_answer(source: str) -> str:
    api = 'window.IMPE_ZADARMA_INCOMING = Object.freeze({'
    require(source.count(api) == 1, 'API entrante ausente o duplicada')
    pos = source.index(api)
    section = source[pos:]
    return span_between(section, '        answer: function () {',
                        '        decline: function () {', 'Contestar')


def section_callback(source: str) -> str:
    observer = 'const startAnalystSdkObserver = function () {'
    ending = 'const ensureWidget = async function () {'
    require(source.count(observer) == 1 and source.count(ending) == 1,
            'Observador del Analista ausente o duplicado')
    start = source.index(observer)
    finish = source.index(ending)
    require(start < finish, 'Observador del Analista fuera de orden')
    return source[start:finish]


def merge(current: str, before: str, after: str) -> tuple[str, list[str]]:
    operations = []
    obs_before = section_callback(before)
    obs_after = section_callback(after)
    cb_new = span_between(
        obs_after, '                onConnected: function () {',
        '                onStopped: function () {', 'Callback de conexión'
    )
    assert 'reportSdkEvent(\'answered\'' in cb_new
    live_obs = section_callback(current)
    require(live_obs.count('onStopped: function () {') == 1,
            'Observador WebRTC: onStopped personalizado')
    if 'onConnected: function () {' in live_obs:
        require(cb_new.strip() in live_obs,
                'Observador WebRTC: onConnected existe pero difiere; revisión manual')
    else:
        old = '                onStopped: function () {'
        live_obs_new = live_obs.replace(old, cb_new + old, 1)
        current = current.replace(live_obs, live_obs_new, 1)
        operations.append('evento onConnected (sin tocar onStopped ni SDK)')

    old_body = section_answer(before)
    new_body = section_answer(after)
    live_body = section_answer(current)
    orig_left, old_branch, orig_right = find_answer_branch(old_body)
    new_left, new_branch, new_right = find_answer_branch(new_body)
    left, live_branch, right = find_answer_branch(live_body)
    require(strip_comments_and_whitespace(orig_left) ==
            strip_comments_and_whitespace(new_left) and
            strip_comments_and_whitespace(orig_right) ==
            strip_comments_and_whitespace(new_right),
            'Referencias Git: Contestar cambió fuera de guarda SDK')
    if strip_comments_and_whitespace(live_branch) == strip_comments_and_whitespace(new_branch):
        require('markAnswerRequested' in live_branch and
                "'answering'" in live_branch,
                'Contestar actualizado de forma parcial')
    else:
        require(strip_comments_and_whitespace(live_branch) ==
                strip_comments_and_whitespace(old_branch),
                'Contestar: la guarda del SDK tiene lógica adicional; no se eliminará')
        patched = left + new_branch + right
        require(live_body in current, 'Contestar: no se reconoce el bloque actual')
        current = current.replace(live_body, patched, 1)
        operations.append('bloqueo de segundo clic en Contestar (sin tocar otras acciones)')

    require('onConnected: function () {' in section_callback(current) and
            'markAnswerRequested' in section_answer(current) and
            'phase:\'answering\'' in section_answer(current),
            'Validación final de respuesta incompleta')
    return current, operations


def main():
    if len(sys.argv) != 4:
        print('Uso: fusionar_respuesta_transferida_hostinger.py COPIA BASE TARGET',
              file=sys.stderr)
        return 2
    path, before, after = (pathlib.Path(x) for x in sys.argv[1:])
    try:
        current = path.read_text(encoding='utf-8')
        patched, operations = merge(
            current,
            before.read_text(encoding='utf-8'),
            after.read_text(encoding='utf-8')
        )
        if patched != current:
            path.write_text(patched, encoding='utf-8')
            print('FUSIÓN COMPATIBLE: ' + ', '.join(operations))
        else:
            print('YA ACTUALIZADO: Contestar en host WebRTC')
        return 0
    except (Conflicto, OSError, UnicodeError) as exc:
        print('CONFLICTO: ' + str(exc) + '. PRODUCCIÓN INTACTA.',
              file=sys.stderr)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
