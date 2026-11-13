#!/usr/bin/env python3
"""Publicación selectiva de trámite de inscripción V3."""

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
        ('app/services/EmbudoComercialV3MovimientosPermitidos.php', '542567e9b615c6631e647830a44e29d81c2658be', 'b8127592e1e3f9611535d8520871e204b8f2e2bb'),
        ('app/services/EmbudoComercialV3SeguimientoService.php', '41c376b36861f81302435ea54d8d6df7beb399f7', '2a0a5ba62b3a347ce36c6356316f4ea1928e8a6e'),
        ('app/views/embudo_comercial/inscripcion_v3_modal.php', 'ef55ed0e8630ae6fb6fc9595f55398f70c521b25', '87aeac8012649267c5413c71080f93c6f094c161'),
        ('public/css/embudo_v3_bandejas.css', '26857d52d4eb673856cc2b03d1bb2bb8e349c8fa', 'b7aa3c9acccefcc70f67acd7a43c53a1b0b6bc6e'),
        ('public/javascript/embudo_comercial_v3_inscripcion.js', '98bac0e72830fadfe030eaca073e0dfaa83b3577', '0933c836ccda049b921f4583a5339103b7c5e647'),
    ]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_TRAMITE_UI_V3_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_TRAMITE_UI_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
