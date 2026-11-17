#!/usr/bin/env python3
"""Publicación selectiva: próximas acciones a Seguimiento V3. Sin SQL de escritura."""

import argparse
import os
import sys

import publicar_ventas_v3_gestion_potenciales_hostinger as publicador


ARCHIVOS = [('app/services/EmbudoComercialV3ProximaAccion.php', None, 'f87c3d688cdafa3118158af88453baa02dd178a4'), ('app/services/EmbudoComercialPoliticaPropuesta.php', 'e5ec54f6fac872d09bf534a06debf7e38ee410c1', '7db5633df7417252a1d84b5298b44bf6612c2a1d'), ('app/services/EmbudoComercialV3SeguimientoService.php', '47f5bccf5fa5d6555b56107921bb09906e30faac', '82826856bbeb79465e5af8fda2dc057aeee28e45'), ('app/services/EmbudoComercialV3GestionPotencialService.php', '4c9bb4ef399001759a2ad469f3fb0d8e570d45b8', '86519bcf2d6aea461a619035d7d6acbea814b27c'), ('app/services/EmbudoComercialV3InscripcionService.php', '3fc50b732edcd2421e3f2e851a80cd71ef46a7c4', '78117b36187694a383a638aecf4de1bae64606b4'), ('app/services/EmbudoComercialResultadoBridge.php', '89aa3745b3d25a99c3b4aed38e9b7a0c56f32866', 'a9666352990f2a5965deaaf98264f6a22e4be40d'), ('app/views/embudo_comercial/operativo_v3.php', '297380af64619da4ece86daa8a7549766bb971ba', '94ffd131aad08d1125bddc86ebe6c46eedc12556'), ('public/javascript/embudo_comercial_v3_inscripcion.js', '3f94e7056818cceb21c645dcc6087d8aaf122ce9', '426d4f12b95696c5f2c37a13d05c350e5f769c74'), ('public/javascript/embudo_comercial_v3_operativo.js', 'c25955e92bd78af3c60a916ce316081c6c421434', 'ade8e1194e9a55d3bbf1a57b68e5fa70896727f9')]

DEPENDENCIAS = {'app/services/EmbudoComercialRepository.php': 'f3761b4a749308f3c538f09294d96e7c16101a7f', 'app/services/EmbudoComercialV3MovimientosPermitidos.php': 'e5544573ece59ee13d7a6485237eca50a833d216', 'app/services/EmbudoComercialV3AccesoControlado.php': '480ce33e3622eb44116c3aed578c0a753378eaa9', 'app/controllers/EmbudoComercialController.php': 'e518765e4d02b588b886bb40e08410618f5ce3c9', 'public/javascript/telefonia_ventas_resultados.js': '9a147e373eb4de8899692f1f1a6c424439f0d1af', 'app/services/TelefoniaResultadoVentasService.php': '24c1f7d6c18656388595305bde2c01a1a6a3a175'}

# Variante exacta de Hostinger revisada por diff: conserva el puente post-commit,
# pero no escribe la columna opcional embudo_asignacion_id en resultados PBX.
TELEFONIA_REL = 'app/services/TelefoniaResultadoVentasService.php'
TELEFONIA_HOSTINGER = 'd0cc4cfd94de94363343906d2ce3df2cad135211'

def configurar_dependencias(sitio):
    esperados=DEPENDENCIAS.copy()
    ruta=publicador.confiable(sitio,TELEFONIA_REL)
    if not os.path.isfile(ruta):
        raise publicador.Bloqueo('dependencia protegida ausente: '+TELEFONIA_REL)
    actual=publicador.blob(ruta)
    if actual not in (DEPENDENCIAS[TELEFONIA_REL],TELEFONIA_HOSTINGER):
        raise publicador.Bloqueo('dependencia protegida cambió: '+TELEFONIA_REL)
    esperados[TELEFONIA_REL]=actual
    publicador.NO_MODIFICAR=esperados
    publicador.validar_intangibles(sitio)
    if actual==TELEFONIA_HOSTINGER:
        print('COMPATIBLE_TELEFONIA_HOSTINGER: variante exacta revisada; se conserva intacta.')


def comprobar_esquema(sitio):
    """Sólo metadatos; no se consultan datos ni se imprimen credenciales."""
    import subprocess
    codigo=r"""
require $argv[1].'/config/db_connection.php';
$db=(new Database())->connect(false);
$tablas=[
 'prospecto_embudo_actividad_v3'=>['id','asignacion_id','usuario_id','etapa','accion','motivo','fecha_anterior','fecha_pendiente','completada_at','subetapa_anterior','subetapa_nueva','creado_at'],
 'prospecto_embudo_subetapas'=>['asignacion_id','subetapa','actualizado_por','actualizado_at','version'],
 'prospecto_embudo_subetapas_historial'=>['asignacion_id','etapa_en_evento','subetapa_anterior','subetapa_nueva','motivo','origen','usuario_id','creado_at'],
 'prospecto_embudo_etapas'=>['asignacion_id','etapa','cambiado_por','actualizado_at'],
 'prospecto_embudo_movimientos'=>['asignacion_id','etapa_anterior','etapa_nueva','motivo','usuario_id','creado_at'],
 'prospecto_asignaciones'=>['id','asesor_id','vigente']
];
foreach($tablas as $tabla=>$campos){
 $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $s->bind_param('s',$tabla);$s->execute();$fila=$s->get_result()->fetch_assoc();$s->close();
 if(strtoupper($fila['ENGINE']??'')!=='INNODB')exit(2);
 $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $s->bind_param('s',$tabla);$s->execute();$columnas=array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'COLUMN_NAME');$s->close();
 if(array_diff($campos,$columnas))exit(3);
}
$db->close();echo 'ESQUEMA_SEGUIMIENTO_OK';
"""
    try:
        salida=subprocess.check_output(['php','-r',codigo,'--',sitio],stderr=subprocess.STDOUT).decode().strip()
    except (OSError,subprocess.CalledProcessError):
        raise publicador.Bloqueo('esquema V3 no compatible o conexión no disponible; sin cambios.')
    if salida!='ESQUEMA_SEGUIMIENTO_OK':
        raise publicador.Bloqueo('respuesta inesperada en comprobación de metadatos; sin cambios.')
    print(salida)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for opcion in ("site", "stage", "backup-root", "backup-dir", "confirm"):
        parser.add_argument("--" + opcion, required=opcion == "site")
    modo = parser.add_mutually_exclusive_group(required=True)
    for opcion in ("check", "apply", "rollback"):
        modo.add_argument("--" + opcion, action="store_true")
    args = parser.parse_args()
    publicador.ARCHIVOS = ARCHIVOS
    publicador.NO_MODIFICAR = DEPENDENCIAS
    sitio = publicador.raiz(args.site)
    configurar_dependencias(sitio)

    if args.rollback:
        if args.confirm != "REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE" or not args.backup_dir:
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
    publicador.estado(sitio)
    comprobar_esquema(sitio)
    if args.check:
        estado = publicador.estado(sitio)
        print("CHECK_OK: " + ("ya aplicado." if estado == "READY" else "archivos compatibles."))
        return
    if args.confirm != "PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE" or not args.backup_root:
        raise publicador.Bloqueo("Apply requiere confirmación y respaldo privado.")
    publicador.publicar(sitio, stage, publicador.raiz(args.backup_root, False))
    print("SEGUIMIENTO_ACCIONES_V3_OK: próximas acciones y auditoría; sin migrar históricos.")


if __name__ == "__main__":
    try:
        main()
    except (publicador.Bloqueo, OSError, ValueError) as error:
        print("BLOQUEADO: " + str(error), file=sys.stderr)
        sys.exit(2)
