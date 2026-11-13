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
        ('app/controllers/EmbudoComercialController.php', '650222ef180491719c9fd269d1b19e805359f2f4', '6b9a88ec75d725995ba77cedabe6d06f1a401ec2'),
        ('app/services/EmbudoComercialV3InscripcionService.php', 'c79575dd97f445700e2f10e93c92211dfad2fa55', '3fc50b732edcd2421e3f2e851a80cd71ef46a7c4'),
        ('app/views/embudo_comercial/inscripcion_v3_modal.php', 'bd74039d75e42556c06b8b6598746fb12590fe04', 'ef55ed0e8630ae6fb6fc9595f55398f70c521b25'),
        ('app/views/embudo_comercial/operativo_v3.php', '5c340f08e082d6bdad417044033235fda51e18da', '58c8783402cf0eccd96c157ea67709f44490ffbf'),
        ('public/javascript/embudo_comercial_v3_inscripcion.js', 'edb6396c459f9ffb1d62de415322fb68db98437b', '98bac0e72830fadfe030eaca073e0dfaa83b3577'),
    ]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_TRAMITE_V3_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_TRAMITE_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
