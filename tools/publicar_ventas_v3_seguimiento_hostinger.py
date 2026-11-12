#!/usr/bin/env python3
"""Publicación selectiva de Seguimiento Operativo V3."""

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
        ('app/services/EmbudoComercialV3SeguimientoService.php',
         None, 'fe3d2caa879f441fa101fe66d15ba8a54ea6d817'),
        ('app/services/EmbudoComercialRepository.php',
         '6b518fe3e45fedb6d29f16f2443ffd4ed7dd4016', '150269b1a6687dd8011e1a968b6162fa2f9ff656'),
        ('app/controllers/EmbudoComercialController.php',
         '7974c6166e64bf04bbca906de69e5d9c934476b6', '7f3ae6e1410d3e05389327cb6339b26ee57202bb'),
        ('public/css/embudo_v3_operativo.css',
         'a09c9a95390c095c9ea2d0bb986edd3f455260ce', '866e0b44e9678125fb13aa2b3ccc2fe1487a99e6'),
        ('public/javascript/embudo_comercial_v3_operativo.js',
         '23fcd40817493c2a65c7bd7b62d27065efdc55b9', '19dda8dad24fc6471fd8f08342c988c2538f49be'),
        ('app/views/embudo_comercial/operativo_v3.php',
         'ea578ad62808b5fb39f6f50f1da51f55af6c95c3', 'd266598cd6659c6fcd566020a03e9d6dc56a2a94'),
    ]
    publicador.NO_MODIFICAR = {
        "app/services/EmbudoComercialResultadoBridge.php": "f03f8da725dfe3645db9ce9e1a76e11c38c81427",
        "app/services/EmbudoComercialV3GestionPotencialService.php": "4c9bb4ef399001759a2ad469f3fb0d8e570d45b8",
    }
    sitio = publicador.raiz(args.site)
    movimiento = publicador.confiable(sitio, 'app/services/EmbudoComercialMovimientoService.php')
    if publicador.blob(movimiento) not in (
        'b8ff5c14127fcf049ff1080f1e9ae3fd181fb362',
        '9621a3e2a2b96d0c89ecd597a17c323c4ee09139',
    ):
        raise publicador.Bloqueo("La versión del movimiento anterior no está acreditada.")

    if args.rollback:
        if args.confirm != "REVERTIR_SEGUIMIENTO_V3_IMPE" or not args.backup_dir:
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
        print("CHECK_OK: " + ("ya aplicado." if estado == "READY" else "seis archivos compatibles."))
        return
    if args.confirm != "PUBLICAR_SEGUIMIENTO_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
