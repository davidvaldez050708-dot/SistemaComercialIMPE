#!/usr/bin/env python3
"""Publicación selectiva de Recuperación automática V3."""

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
        ('app/services/EmbudoComercialV3BandejasService.php', '118eb819b21fbfb7d49bcaa1877e899b5667cb50', '7a5c329f316d9e74dd514b60fb27aed1c8bd17cf'),
        ('app/services/EmbudoComercialResultadoBridge.php', '3e30a05cfdb00a2718080bfc5b6d59666a0418ea', '7374d9ce5cf0bc31ec60867268e9d4684970db93'),
        ('app/services/TelefoniaResultadoVentasService.php', '52ba1ab67c9fed06c60fa12822203334fb06822f', '47ca42f2d6a0aaf5ca7b4765ea7e0650d37a18bf'),
        ('public/javascript/telefonia_ventas_resultados.js', '44375f479ffc037363d78dada4cc76c5eb913b82', 'c3cf96511de93462b2cdb02a3d0a96d7d36c5d40'),
        ('app/views/embudo_comercial/operativo_v3.php', 'e1b92a296876a1be3ecba1bfd5e7bf35c6949bf3', '639f6dc6b0fdf96d45f3b69e338a71fe737f9193'),
        ('public/javascript/embudo_comercial_v3_operativo.js', '75da8954c8bcd41fc1ae9ffccb3609814eacfa70', 'd74f1d70ce5ca5007a1b9fa4255463f4dcb1343a'),
        ('app/services/EmbudoComercialIntentosPbxRepository.php', '072f5e829f8377fd486b8b7c83341d5df2ecd132', '1c9ab67afaff7ed0d3f24e5ca1f3677ad02284d4'),
    ]
    publicador.NO_MODIFICAR = {}
    sitio = publicador.raiz(args.site)

    if args.rollback:
        if args.confirm != "REVERTIR_RECUPERACION_AUTO_IMPE" or not args.backup_dir:
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
    if args.confirm != "PUBLICAR_RECUPERACION_AUTO_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
