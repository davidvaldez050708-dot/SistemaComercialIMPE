#!/usr/bin/env python3
"""Publicación selectiva de actividades comerciales V3 V3."""

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
    publicador.ARCHIVOS = [
        ('app/services/EmbudoComercialRepository.php', '820c0022782e75ce204bf6ea12f3130dbe683fb0', 'f3761b4a749308f3c538f09294d96e7c16101a7f'),
        ('app/controllers/EmbudoComercialController.php', 'a1e98a909539975d76b3d9f772d2b076338052e7', 'ca4a20cd3d75dc29f86d0e150cf201677df3a83b'),
        ('app/views/embudo_comercial/detalle.php', 'a17416d9f4dba4f44fae74c3b84bfe101f65941d', 'd2b23a3ae7423df81e7fa07023aae1a405f3c6b6'),
        ('app/views/embudo_comercial/operativo_v3.php', 'e6172ad143106c4b48bd43ef5bb902ebf724ba42', 'fe32b9c8ab34da38e2250d2b6bbac8d9bd43a8c3'),
        ('public/javascript/embudo_comercial_v3_operativo.js', '2e5836f8840a879e16a32cdc839c0f6a07876812', 'f397b770a84efff2788e9976f9bbf1d0bba71061'),
    ]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_ACTIVIDADES_V3_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_ACTIVIDADES_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
