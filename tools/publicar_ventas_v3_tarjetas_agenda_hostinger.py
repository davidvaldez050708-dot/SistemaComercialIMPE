#!/usr/bin/env python3
"""Publicación selectiva de tarjetas y agenda V3."""

import argparse
import sys

import publicar_ventas_v3_gestion_potenciales_hostinger as publicador


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for opcion in ("site", "stage", "backup-root", "backup-dir", "confirm"):
        parser.add_argument("--" + opcion, required=opcion == "site")
    modo = parser.add_mutually_exclusive_group(required=True)
    for opcion in ("check", "apply", "rollback"):
        modo.add_argument("--" + opcion, action="store_true")
    args = parser.parse_args()
    publicador.ARCHIVOS = [('app/services/EmbudoComercialV3MovimientosPermitidos.php', '2bbc296fff1c310963637ed3f95402b9254635a3', '50734f6bc88c5c3c3751e440ac4ce8242fffeaa9'), ('app/services/EmbudoComercialV3SeguimientoService.php', 'fe3d2caa879f441fa101fe66d15ba8a54ea6d817', '41c376b36861f81302435ea54d8d6df7beb399f7'), ('app/views/embudo_comercial/operativo_v3.php', '23571f88687958519b902da855b914e665313d48', '5d5b297a986bcf92ab2ca224ef3d9ba30f9ae078'), ('public/css/embudo_v3_operativo.css', '3bc6931cdc2d84b229b1604c77b59cdc9c004fa9', 'bf0234e15b6f87bf2f7edf1d9b1c13aec5398654'), ('public/javascript/embudo_comercial_v3_operativo.js', '243a20ef4fcd37fe4eb01c377fd3912e4d30ba50', '2adf7d3952a6f5e3c8400ecaf7355b861ecbca38')]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_TARJETAS_AGENDA_V3_IMPE" or not args.backup_dir:
            raise publicador.Bloqueo("Rollback requiere confirmación y respaldo exacto.")
        respaldo = publicador.raiz(args.backup_dir)
        if publicador.dentro(respaldo, sitio):
            raise publicador.Bloqueo("El respaldo debe estar fuera de public_html.")
        publicador.revertir(sitio, respaldo)
        return
    if not args.stage:
        raise publicador.Bloqueo("Indica la carpeta privada del paquete con --stage.")
    stage = publicador.raiz(args.stage)
    if publicador.dentro(stage, sitio):
        raise publicador.Bloqueo("El paquete debe estar fuera de public_html.")
    for relativo, _, esperado in publicador.ARCHIVOS:
        ruta = publicador.confiable(stage, relativo)
        if publicador.blob(ruta) != esperado:
            raise publicador.Bloqueo("El paquete no coincide con la versión probada: " + relativo)
        if relativo.endswith(".php"):
            publicador.lint_php(ruta)
        if relativo.endswith(".js"):
            publicador.lint_js(ruta)
    if args.check:
        estado = publicador.estado(sitio)
        print("CHECK_OK: " + ("ya aplicado." if estado == "READY" else "archivos compatibles."))
        return
    if args.confirm != "PUBLICAR_TARJETAS_AGENDA_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
