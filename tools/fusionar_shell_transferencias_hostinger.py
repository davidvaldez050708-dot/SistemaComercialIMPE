#!/usr/bin/env python3
"""Fusiona sólo los tres bloques de transferencia de telefonia_shell.js.

A diferencia de copiar el JS completo del repositorio, preserva byte a byte
las personalizaciones de llamadas recibidas, WebRTC e historial de Hostinger.

Uso: python3 fusionar_shell_transferencias_hostinger.py <copia-staged-live> <fuente-github>
Sin modificaciones directas en producción; sólo se toca la copia temporal.
"""
import pathlib
import sys


def fail(message: str) -> None:
    raise SystemExit("CONFLICTO: " + message + ". PRODUCCIÓN INTACTA.")


def once(text: str, value: str, context: str) -> None:
    if not value or text.count(value) != 1:
        fail(f"Ancla única ausente o duplicada: {context}")


def segment(text: str, first: str, last: str, name: str) -> str:
    once(text, first, name + " inicio")
    begin = text.index(first)
    rest = text[begin:]
    once(rest, last, name + " final")
    return text[begin : begin + rest.index(last)]


def run(target: pathlib.Path, source: pathlib.Path) -> None:
    oldbytes = target.read_bytes()
    canonicalbytes = source.read_bytes()
    try:
        live = oldbytes.decode("utf-8")
        canonical = canonicalbytes.decode("utf-8")
    except UnicodeDecodeError:
        fail("telefonia_shell.js no está codificado en UTF-8")

    snippets = [
        segment(
            canonical,
            "    const transferToggle = document.querySelector('[data-shell-transfer-toggle]');\n",
            "    const analystResultNotice = document.querySelector('[data-analyst-call-result]');",
            "controles de transferencia",
        ),
        segment(
            canonical,
            "        getActiveCall: function () {\n",
            "        isPhoneReady: function () {",
            "API de transferencia",
        ),
        segment(
            canonical,
            "        const workingCall = Boolean(ongoing && state.phase === 'in-progress');\n",
            "        restore.hidden = !minimized || !(incoming || (ongoing && forceNativeControls));",
            "visibilidad durante conversación",
        ),
    ]

    # Los tres bloques DEBEN corresponder al mismo build autorizado.
    for name, forbidden in (
        ("elementos", "    const transferToggle = document.querySelector("),
        ("API", "        requestAuthorizedTransfer: function ("),
        ("estado", "        const workingCall = Boolean("),
    ):
        if (forbidden in live) != (snippets[("elementos", "API", "estado").index(name)] in live):
            fail(f"se detectó una variante parcial no homologada de {name}")

    installed = [snippet in live for snippet in snippets]
    if all(installed):
        print("YA ACTUALIZADO: telefonia_shell.js personalizado, tres bloques homologados.")
        return
    if any(installed):
        fail("el archivo contiene sólo parte de la transferencia")

    # Anclas son compartidas por la versión de Hostinger diagnosticada y por
    # el shell moderno. Nunca escribir un archivo que no las cumpla.
    anchors = [
        "    const analystResultNotice = document.querySelector('[data-analyst-call-result]');",
        "        openDialer: openDialer,",
        "        restore.hidden = !minimized || !(incoming || (ongoing && forceNativeControls));",
        "    const freshState = function (value) {",
        "    const sendPhoneCommand = function (action, payload) {",
        "    const render = function () {",
    ]
    for anchor in anchors:
        once(live, anchor, anchor)
    # La API de telefonía debe seguir siendo el objeto único del shell.
    for marker in (
        "window.IMPE_TELEPHONY_SHELL = Object.freeze({",
        "ensureHost: ensureHost",
        "const incomingCallId = value =>",
        "const ongoing = Boolean(state?.active);",
        "incomingPanel.hidden = !customVisible",
    ):
        if marker not in live:
            fail("motor telefónico no reconoce el esquema actual (" + marker + ")")

    newline = "\r\n" if b"\r\n" in oldbytes else "\n"
    snippets = [v.replace("\n", newline) for v in snippets]
    anchors = [v.replace("\n", newline) for v in anchors]

    # Los fragmentos son inserciones puras, sin borrar ni modificar el resto
    # del teléfono existente.
    insertions = [
        (anchors[0], snippets[0]),
        (anchors[1], snippets[1]),
        (anchors[2], snippets[2]),
    ]
    merged = live
    for anchor, addition in insertions:
        once(merged, anchor, "punto de inserción")
        merged = merged.replace(anchor, addition + anchor, 1)
    if merged == live:
        fail("no se aplicó ningún bloque")

    # Comprobación reversible: al retirar exactamente nuestras adiciones,
    # el resultado DEBE ser idéntico byte a byte al archivo de producción.
    recovered = merged
    for addition in snippets:
        once(recovered, addition, "verificación de preservación")
        recovered = recovered.replace(addition, "", 1)
    if recovered.encode("utf-8") != oldbytes:
        fail("la fusión modificó código ajeno a transferencias")

    target.write_bytes(merged.encode("utf-8"))
    print("FUSIÓN COMPATIBLE: telefonia_shell.js; WebRTC e historial intactos, sólo tres bloques de transferencia.")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        raise SystemExit("Uso: fusionar_shell_transferencias_hostinger.py <copia-staged> <fuente>")
    run(pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2]))
