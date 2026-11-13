#!/usr/bin/env python3
"""Publicación selectiva de descarte comercial V3 V3."""

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
        ('app/services/EmbudoComercialResultadoBridge.php', '7374d9ce5cf0bc31ec60867268e9d4684970db93', '89aa3745b3d25a99c3b4aed38e9b7a0c56f32866'),
        ('app/services/EmbudoComercialV3BandejasService.php', 'b1c5135b80ba02c201a5d7fa8cc3972d9692ae12', '291340eadde761ce20fb3646f8392dc0eccad8ad'),
        ('app/views/embudo_comercial/bandejas_v3_modal.php', '0d1d1b306aac7e3e1ab4b685b71cf4e4fdf78706', '8cd41c4e9f10bceb2a78690eca670e76824caf24'),
        ('app/views/embudo_comercial/operativo_v3.php', '5d25255a3d618a4e74379f4083623db2e1519373', '297380af64619da4ece86daa8a7549766bb971ba'),
        ('public/javascript/embudo_comercial_v3_bandejas.js', '2476f6af122577b31bb384ed1bba0fb73ea6556a', '172434d9dde862dd9bd2596e7fe0eb657b741b59'),
        ('public/javascript/embudo_comercial_v3_operativo.js', '33d85c83e044ed9054f8f2ddfd14b70dd21c001c', '139ef42b910b23d3accca4cdda2f4e8f3d296986'),
        ('public/javascript/telefonia_ventas_resultados.js', 'c3cf96511de93462b2cdb02a3d0a96d7d36c5d40', '7c49c5d9634124f938f92b5ed183f6179b1600c9'),
        ('app/services/TelefoniaResultadoVentasService.php', '6cc48fc8f5fc07efcd5ea9def76963e1720be9dc', 'd0cc4cfd94de94363343906d2ce3df2cad135211'),
    ]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_DESCARTE_COMERCIAL_V3_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_DESCARTE_COMERCIAL_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
