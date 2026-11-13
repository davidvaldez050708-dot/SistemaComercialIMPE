#!/usr/bin/env python3
"""Publicación selectiva de arrastre e icono V3."""

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
        ('app/controllers/EmbudoComercialController.php', '3d02468e4b561abf83c720dc370d6cccf422c6bc', '650222ef180491719c9fd269d1b19e805359f2f4'),
        ('app/services/EmbudoComercialRepository.php', 'b89744e35c369ac24bbd2c30403a7db5a3e00a5a', '1cd8c3b8cb6bd4c3d5c3c8c2dae2d7de86221079'),
        ('app/services/EmbudoComercialV3CalificacionService.php', '04d321ac7d8e9850824e3e3291e4848036ba174f', 'a600fc95a844a9d261c7500b0dabaa6cc52b1aaa'),
        ('app/services/EmbudoComercialV3MovimientosPermitidos.php', 'd3f4248699d5034722bcde36eb84740c58509bd2', '542567e9b615c6631e647830a44e29d81c2658be'),
        ('app/views/embudo_comercial/operativo_v3.php', '5d5b297a986bcf92ab2ca224ef3d9ba30f9ae078', '2a040f772b5a93b9f31dcfd29632c939cb0b2e6a'),
        ('public/css/embudo_v3_operativo.css', 'bf0234e15b6f87bf2f7edf1d9b1c13aec5398654', 'e2ef2ecc08755d53ef74c18d405f360acd18d453'),
        ('public/javascript/embudo_comercial_v3_operativo.js', '2adf7d3952a6f5e3c8400ecaf7355b861ecbca38', '903abf05c5d7ca9b060afdd3a4d780fe778e42be'),
    ]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_ARRASTRE_ICONO_V3_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_ARRASTRE_ICONO_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
