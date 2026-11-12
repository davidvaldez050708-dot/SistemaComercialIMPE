#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicador selectivo del historial visual de actividades V3 (Python 3.6)."""
import argparse
import sys
import publicar_ventas_v3_gestion_potenciales_hostinger as p

# Cambiar únicamente tres archivos de presentación del tablero.
p.ARCHIVOS = [
    ("app/views/embudo_comercial/operativo_v3.php",
     "d266598cd6659c6fcd566020a03e9d6dc56a2a94",
     "3eaaa4d66489e31406613e67edee166f105f4619"),
    ("public/css/embudo_v3_operativo.css",
     "866e0b44e9678125fb13aa2b3ccc2fe1487a99e6",
     "3bc6931cdc2d84b229b1604c77b59cdc9c004fa9"),
    ("public/javascript/embudo_comercial_v3_operativo.js",
     "19dda8dad24fc6471fd8f08342c988c2538f49be",
     "243a20ef4fcd37fe4eb01c377fd3912e4d30ba50"),
]
# Si una dependencia funcional cambió, no sobrescribir la vista.
p.NO_MODIFICAR = {
    "app/services/EmbudoComercialV3SeguimientoService.php":
        "fe3d2caa879f441fa101fe66d15ba8a54ea6d817",
    "app/services/EmbudoComercialRepository.php":
        "150269b1a6687dd8011e1a968b6162fa2f9ff656",
    "app/controllers/EmbudoComercialController.php":
        "7f3ae6e1410d3e05389327cb6339b26ee57202bb",
    "app/services/EmbudoComercialResultadoBridge.php":
        "f03f8da725dfe3645db9ce9e1a76e11c38c81427",
    "app/services/EmbudoComercialV3GestionPotencialService.php":
        "4c9bb4ef399001759a2ad469f3fb0d8e570d45b8",
}
p.CONFIRMAR = "PUBLICAR_HISTORIAL_ACTIVIDADES_UI_V3_IMPE"
p.REVERTIR = "REVERTIR_HISTORIAL_ACTIVIDADES_UI_V3_IMPE"


def verificar_paquete(stage):
    for ruta, _, nuevo in p.ARCHIVOS:
        destino = p.confiable(stage, ruta)
        if p.blob(destino) != nuevo:
            raise p.Bloqueo("Archivo fuera de la versión autorizada: " + ruta)
        if ruta.endswith(".php"):
            p.lint_php(destino)
        if ruta.endswith(".js"):
            p.lint_js(destino)
    vista = p.leer(p.confiable(stage, p.ARCHIVOS[0][0]))
    css = p.leer(p.confiable(stage, p.ARCHIVOS[1][0]))
    js = p.leer(p.confiable(stage, p.ARCHIVOS[2][0]))
    for ok, mensaje in [
        (b'impe-v3o-actividad-cuerpo' in vista, "cuerpo desplazable"),
        (b'impe-v3o-actividad-historial-scroll' in vista, "historial independiente"),
        (b'data-actividad-historial' in vista, "contenedor de eventos"),
        (b'data-actividad-mas' in vista, "paginación"),
        (b'max-height:min(88dvh,720px)' in css, "altura del modal"),
        (b'max-height:min(32dvh,252px)' in css, "altura del historial"),
        (b'li.append(cabecera,descripcion,metadatos)' in js, "registros legibles"),
        (b'comprobarRespuesta' in js and b'cargarHistorial' in js, "endpoint previo"),
        (b'confirmarGuardado' in js and b'telefonoActivo()' in js, "avisos y PBX"),
    ]:
        if not ok:
            raise p.Bloqueo("Contrato visual ausente: " + mensaje)
    print("CONTRATOS_HISTORIAL_UI_OK: historial, scroll, paginacion, avisos y PBX.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True)
    parser.add_argument("--stage")
    parser.add_argument("--backup-root")
    parser.add_argument("--backup-dir")
    parser.add_argument("--confirm")
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    modo.add_argument("--rollback", action="store_true")
    a = parser.parse_args()
    site = p.raiz(a.site)
    if a.rollback:
        if a.confirm != p.REVERTIR or not a.backup_dir:
            raise p.Bloqueo("Rollback necesita confirmación y backup-dir exacto.")
        respaldo = p.raiz(a.backup_dir)
        if p.dentro(respaldo, site):
            raise p.Bloqueo("Respaldo dentro del sitio público.")
        p.revertir(site, respaldo)
        print("ROLLBACK_HISTORIAL_UI_OK")
        return
    if not a.stage:
        raise p.Bloqueo("Falta --stage.")
    stage = p.raiz(a.stage)
    if p.dentro(stage, site):
        raise p.Bloqueo("Stage no puede estar dentro del sitio.")
    verificar_paquete(stage)
    status = p.estado(site)
    if a.check:
        print("CHECK_HISTORIAL_UI_OK: " + ("ya aplicado." if status == "READY" else "listo para publicar."))
        return
    if a.confirm != p.CONFIRMAR or not a.backup_root:
        raise p.Bloqueo("Publicación no autorizada; falta confirmación y respaldo.")
    backup_root = p.raiz(a.backup_root, False)
    if p.dentro(backup_root, site):
        raise p.Bloqueo("Respaldo dentro de public_html.")
    p.publicar(site, stage, backup_root)
    print("APPLY_HISTORIAL_UI_OK: no se modificaron tablas ni servicios.")
    print("Verificar CHECK y probar modal en navegador.")


if __name__ == "__main__":
    try:
        main()
    except (p.Bloqueo, OSError, ValueError) as exc:
        print("BLOQUEADO: " + str(exc), file=sys.stderr)
        sys.exit(2)
