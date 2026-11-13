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
        ('app/controllers/EmbudoComercialController.php', '6b9a88ec75d725995ba77cedabe6d06f1a401ec2', 'a102ff7de3462d4c70449e918cc2f7bf1d80b76e'),
        ('app/services/EmbudoComercialRepository.php', 'c7bc5bf8c744d6cc151e7b7eb1728c6c386f369e', '7e2066a9de5f8479dd02b1db6c61e9cc7fe69a1d'),
        ('app/views/embudo_comercial/detalle.php', '14a2acb3668d6896e52ebec9812b0f23ec317d7c', 'cb79bc11a2cbdf133f88a6f8dd1d8048cdf6b5e8'),
        ('app/views/embudo_comercial/operativo_v3.php', '7f36c1befc8e167bf8ff213fe7e093c00aa4ae73', '595f9779a5b5a97172ede95d0b563e42ee41ae23'),
        ('app/views/embudo_comercial/expedientes.php', None, 'f9ad448e8f20fb1ff14c838321826918abfe2f71'),
        ('public/javascript/embudo_expediente_audio.js', None, 'e7197d08d13edc40ae53a47ef38b0b7c46692cae'),
    ]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_EXPEDIENTES_V3_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_EXPEDIENTES_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
