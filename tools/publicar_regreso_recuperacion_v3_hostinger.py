#!/usr/bin/env python3
"""Publicación selectiva de regreso desde Recuperación V3."""

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
    publicador.ARCHIVOS = [('app/services/EmbudoComercialV3BandejasService.php', '7a5c329f316d9e74dd514b60fb27aed1c8bd17cf', 'b1c5135b80ba02c201a5d7fa8cc3972d9692ae12'), ('app/controllers/EmbudoComercialController.php', 'ca4a20cd3d75dc29f86d0e150cf201677df3a83b', 'e518765e4d02b588b886bb40e08410618f5ce3c9'), ('app/views/embudo_comercial/bandejas_v3.php', '5f0db80357e83ec3a82e9b069a95ae9db942b183', 'b68b0feec94c290fd0d71fe9a3c72837aa46c754')]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_REGRESO_RECUPERACION_V3_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_REGRESO_RECUPERACION_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
