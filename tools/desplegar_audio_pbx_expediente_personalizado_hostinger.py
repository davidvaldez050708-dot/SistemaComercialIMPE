#!/usr/bin/env python3
"""Despliegue de audio PBX conservando el JS personalizado de Hostinger.

Reutiliza backup/rollback/validación de todos los archivos del instalador
original y sólo relaja el diff de un JS mediante anclas semánticas estrictas.
No sustituye el archivo completo ni elimina funciones personalizadas.
"""
import importlib.util
import pathlib
import sys

BASE = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location(
    "instalador_audio_base", BASE / "desplegar_audio_pbx_expediente_hostinger.py"
)
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
original_aplicar_hunks = base.aplicar_hunks

AUDIO_OLD = "const estadoAudio = document.createElement('span');"
AUDIO_NEW = "const pbxVerificado = /^[A-Za-z0-9_-]{6,100}$/.test("
FIN_BLOQUE = "const usuario = String(llamada.usuario || '').trim();"
ETIQUETA_OLD = (
    "llamada.grabacion_sin_vincular === true\n"
    "                                    ? 'Contacto reportado' : 'Contacto efectivo'"
)
ETIQUETA_NEW = (
    "String(llamada.proveedor || '').toUpperCase() === 'ZADARMA_WEBRTC'\n"
    "                                    ? 'Contacto reportado' : 'Contacto efectivo'"
)
PANEL_OLD = "cuerpo.appendChild(pie);\n\n                    tarjeta.appendChild(icono);"
PANEL_NEW = (
    "cuerpo.appendChild(pie);\n"
    "                    if (panelAudio) cuerpo.appendChild(panelAudio);\n\n"
    "                    tarjeta.appendChild(icono);"
)
CONTADOR_OLD = "return Boolean(llamada.tiene_grabacion && llamada.grabacion_url);"
CONTADOR_NEW = (
    "return Boolean((llamada.tiene_grabacion && llamada.grabacion_url) ||\n"
    "                                    llamada.grabacion_transferida_pbx);"
)


def segmento(codigo, inicio, fin):
    if codigo.count(inicio) != 1:
        raise ValueError("Ancla inicial ausente o repetida: " + inicio[:42])
    pos = codigo.index(inicio)
    e = codigo.find(fin, pos)
    if e < 0:
        raise ValueError("No se localizó el final del bloque de grabaciones")
    return codigo[pos:e]


def sustituir_exacto(texto, previo, posterior, etiqueta):
    if texto.count(previo) == 1:
        return texto.replace(previo, posterior, 1)
    if texto.count(posterior) == 1:
        return texto
    raise ValueError("Personalización incompatible en " + etiqueta)


def parche_semantico(actual, original, nuevo):
    # No realizar coincidencias difusas; exige las secciones originales
    # identificadas por el diagnóstico de producción.
    if AUDIO_NEW in actual:
        if (actual.count(AUDIO_NEW) != 1 or
            actual.count(PANEL_NEW) != 1 or
            actual.count(CONTADOR_NEW) != 1 or
            actual.count(ETIQUETA_NEW) != 1):
            raise ValueError("Instalación PBX parcial o reproductor duplicado")
        return actual, "ya actualizado (JS)"
    old = segmento(original, AUDIO_OLD, FIN_BLOQUE)
    new = segmento(nuevo, AUDIO_NEW, FIN_BLOQUE)
    if actual.count(AUDIO_OLD) != 1 or actual.count(old) != 1:
        raise ValueError("Bloque de audio del expediente modificado; no se sobrescribe")
    salida = actual.replace(old, new, 1)
    salida = sustituir_exacto(salida, ETIQUETA_OLD, ETIQUETA_NEW, "estado de contacto")
    salida = sustituir_exacto(salida, PANEL_OLD, PANEL_NEW, "panel de grabación")
    salida = sustituir_exacto(salida, CONTADOR_OLD, CONTADOR_NEW, "contador de grabaciones")
    if salida.count(AUDIO_NEW) != 1 or salida.count(PANEL_NEW) != 1:
        raise ValueError("La integración final no es inequívoca")
    # Validación de integridad: el resultado sobre la base original debe
    # coincidir EXACTAMENTE con el commit aprobado.
    if actual == original and salida != nuevo:
        raise ValueError("El parche no reproduce el commit aprobado")
    return salida, "anclas semánticas compatibles, personalizaciones intactas"


def aplicar_hunks(actual, original, nuevo):
    if "const crearTarjetaHistorial = function (llamada)" not in original:
        return original_aplicar_hunks(actual, original, nuevo)
    if actual == nuevo:
        return actual, "ya actualizado"
    return parche_semantico(actual, original, nuevo)


base.aplicar_hunks = aplicar_hunks
if __name__ == "__main__":
    sys.exit(base.main())
