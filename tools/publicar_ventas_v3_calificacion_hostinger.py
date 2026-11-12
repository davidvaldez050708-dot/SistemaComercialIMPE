#!/usr/bin/env python3
"""Publicación selectiva de Contactados → Potenciales, después del aviso V3."""

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
        ("app/services/EmbudoComercialV3CalificacionService.php",
         None, "04d321ac7d8e9850824e3e3291e4848036ba174f"),
        ("app/services/EmbudoComercialV3MovimientosPermitidos.php",
         "92950fe72f02c938050c8598cd0ed96cd1552c6e", "2bbc296fff1c310963637ed3f95402b9254635a3"),
        ("app/services/EmbudoComercialRepository.php",
         "3e4c6e6acc909e4478c76358f20d96a8f261c055", "6b518fe3e45fedb6d29f16f2443ffd4ed7dd4016"),
        ("app/controllers/EmbudoComercialController.php",
         "16ba342a7fd88534e072eb5c28d98d6702926ea7", "7974c6166e64bf04bbca906de69e5d9c934476b6"),
        ("public/css/embudo_v3_operativo.css",
         "b7b0bbe56a9bc191c0475ac1efaaa57a41e0bc48", "a09c9a95390c095c9ea2d0bb986edd3f455260ce"),
        ("public/javascript/embudo_comercial_v3_operativo.js",
         "7c762035b559be80f4f232952a32a40bac71df8d", "23fcd40817493c2a65c7bd7b62d27065efdc55b9"),
        ("app/views/embudo_comercial/operativo_v3.php",
         "e5b26a84ad2baa178feb74c54cd4b4771ee3e00d", "ea578ad62808b5fb39f6f50f1da51f55af6c95c3"),
    ]
    publicador.NO_MODIFICAR = {
        "app/services/EmbudoComercialResultadoBridge.php": "f03f8da725dfe3645db9ce9e1a76e11c38c81427",
        "app/services/EmbudoComercialV3GestionPotencialService.php": "4c9bb4ef399001759a2ad469f3fb0d8e570d45b8",
    }
    sitio = publicador.raiz(args.site)
    if args.rollback:
        if args.confirm != "REVERTIR_CALIFICACION_V3_IMPE" or not args.backup_dir:
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
    servicio = publicador.leer(publicador.confiable(stage, publicador.ARCHIVOS[0][0]))
    if not all(fragmento in servicio for fragmento in (
        b"FOR UPDATE", b"conversacionAcreditada", b"prospecto_embudo_subetapas_historial",
        b"prospecto_embudo_movimientos", b"rollback()", b"tienePermiso",
    )) or b"IMPE_VENTAS_V3_PILOTO" in servicio:
        raise publicador.Bloqueo("El servicio no cumple los contratos de calificación.")
    if args.check:
        estado = publicador.estado(sitio)
        print("CHECK_OK: " + ("ya aplicado." if estado == "READY" else "siete archivos compatibles."))
        return
    if args.confirm != "PUBLICAR_CALIFICACION_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
