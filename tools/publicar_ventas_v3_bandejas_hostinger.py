#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Gestión Comercial V3 · bandejas seguras. Nueve archivos, CHECK/APPLY/ROLLBACK.
Sin DDL/DML, sin modificar Zadarma ni programas automáticos.
"""
import argparse
import os
import subprocess
import sys
import publicar_ventas_v3_gestion_potenciales_hostinger as p

p.ARCHIVOS = [
    # Instalación de archivos nuevos antes de las vistas/controladores existentes.
    # Así no se expone un require de un archivo todavía ausente.
    ("app/services/EmbudoComercialV3BandejasService.php",None,
     "295c8510e63aa7f2ca4ba1f802d18ae5ebb85393"),
    ("app/views/embudo_comercial/bandejas_v3_modal.php",None,
     "f085408107f37d83a7df29bd2dd4d1e0138afdd6"),
    ("app/views/embudo_comercial/bandejas_v3.php",None,
     "cab2a1c4c293aafa798148f4382425ae692e2a5b"),
    ("public/css/embudo_v3_bandejas.css",None,
     "9792c5b366458ec3a228c47400961a6608fc7ae9"),
    ("public/javascript/embudo_comercial_v3_bandejas.js",None,
     "ea4093008c1e9dd6aaa869867b141e3d81b6c1d3"),
    # Regla PBX aislada para asesores V3, sin reintroducir el piloto.
    ("app/services/EmbudoComercialResultadoBridge.php",
     "4388551ad7aa46bf6b027716d7455edd4751431c",
     "e4f6b23d989ff99539088153df58d4ec2404f79d"),
    ("app/services/EmbudoComercialRepository.php",
     "150269b1a6687dd8011e1a968b6162fa2f9ff656",
     "e89800e97c819e331ed29e51d9d211a1d8d4e5b5"),
    ("app/controllers/EmbudoComercialController.php",
     "7f3ae6e1410d3e05389327cb6339b26ee57202bb",
     "be6249a96ff26fb46cc9616da1a9767e036b826f"),
    ("app/views/embudo_comercial/operativo_v3.php",
     "3eaaa4d66489e31406613e67edee166f105f4619",
     "bddf4fa6a69e248ef71770c61346145392542f58"),
]
p.NO_MODIFICAR = {
    "app/services/EmbudoComercialV3AccesoControlado.php":
        "480ce33e3622eb44116c3aed578c0a753378eaa9",
    "app/services/EmbudoComercialV3SeguimientoService.php":
        "fe3d2caa879f441fa101fe66d15ba8a54ea6d817",
    "app/services/EmbudoComercialV3GestionPotencialService.php":
        "4c9bb4ef399001759a2ad469f3fb0d8e570d45b8",
    "app/services/EmbudoComercialV3CalificacionService.php":
        "04d321ac7d8e9850824e3e3291e4848036ba174f",
    "app/services/EmbudoComercialPoliticaPropuesta.php":
        "e5ec54f6fac872d09bf534a06debf7e38ee410c1",
    "app/services/EmbudoComercialV3IndicadoresVista.php":
        "c7419b2521392c48a0eebefb54bc9c7fd1cfda1c",
    "public/css/embudo_v3_operativo.css":
        "3bc6931cdc2d84b229b1604c77b59cdc9c004fa9",
    "public/javascript/embudo_comercial_v3_operativo.js":
        "243a20ef4fcd37fe4eb01c377fd3912e4d30ba50"
}
p.CONFIRMAR = "PUBLICAR_BANDEJAS_VENTAS_V3_IMPE"
p.REVERTIR = "REVERTIR_BANDEJAS_VENTAS_V3_IMPE"


def paquete(stage):
    for ruta,base,destino in p.ARCHIVOS:
        archivo=p.confiable(stage,ruta)
        if not os.path.isfile(archivo) or p.blob(archivo)!=destino:
            raise p.Bloqueo("Archivo de entrega inesperado: "+ruta)
        if ruta.endswith(".php"):p.lint_php(archivo)
        if ruta.endswith(".js"):p.lint_js(archivo)
    requisitos={
        "app/services/EmbudoComercialResultadoBridge.php":[
            b'bloquearRecuperacionAutomaticaV3',
            b"($nueva==='CONTACTADO' && $etapaAnterior==='RECUPERACION')",
            b'EmbudoComercialV3AccesoControlado::habilitadoParaAsesor($usuarioId)'],
        "app/services/EmbudoComercialV3BandejasService.php":[
            b'resumenJornada(',b'conversacionPosteriorARecuperacion',
            b"DESCARTES_HABILITADOS",b"begin_transaction",b"rollback",
        ],
        "app/controllers/EmbudoComercialController.php":[
            b"bandejasOperativasV3()",b'EmbudoComercialV3AccesoControlado::habilitadoParaAsesor',
            b'Utiliza las acciones verificadas de las bandejas V3',
            b"hash_equals("],
        "app/views/embudo_comercial/operativo_v3.php":[
            b'data-v3b-accion="RECUPERACION"',b'data-v3b-accion="DESCARTAR"',
            b"bandejas_v3_modal.php"],
        "app/views/embudo_comercial/bandejas_v3.php":[
            b"bandejas_v3_modal.php",b"data-v3b-accion=\"REACTIVAR\"",
            b'Registro hist',b'prospectos'],
        "app/views/embudo_comercial/bandejas_v3_modal.php":[
            b'data-v3b-error',b'NO_INTERESADO',b'NUMERO_INVALIDO'],
        "public/javascript/embudo_comercial_v3_bandejas.js":[
            b'bandejasOperativasV3',b"X-CSRF-Token",b"confirmacion",b"telefonoActivo()"],
        "public/css/embudo_v3_bandejas.css":[
            b"max-height:min(88dvh,690px)",b"overflow-y:auto"],
    }
    for ruta,marcas in requisitos.items():
        data=p.leer(p.confiable(stage,ruta))
        for marca in marcas:
            if marca not in data:
                raise p.Bloqueo("Contrato V3 incompleto en "+ruta+": "+repr(marca))
    bridge=p.leer(p.confiable(stage,"app/services/EmbudoComercialV3BandejasService.php"))
    if b"IMPE_VENTAS_V3_PILOTO" in bridge or b"enviarWhatsApp" in bridge:
        raise p.Bloqueo("No activar pilotos ni mensajería en bandejas V3.")
    print("PAQUETE_BANDEJAS_V3_OK: 9 archivos exactos, sin DDL ni automatización.")


def chequear_bd(site):
    p.check_db_lectura(site)
    # Metadatos exclusivamente; sin consultas de prospectos ni datos personales.
    auditor=r"""
$site=$argv[1];
require $site.'/config/db_connection.php';
$db=(new Database())->connect(false);
$req=[
  'prospecto_embudo_movimientos'=>['asignacion_id','etapa_anterior','etapa_nueva','motivo','usuario_id','creado_at'],
  'prospecto_embudo_llamadas'=>['asignacion_id','usuario_id','extension','pbx_call_id','resultado'],
  'telefonia_ventas_llamadas'=>['usuario_id','extension','pbx_call_id','direccion','fecha'],
  'telefonia_ventas_resultados'=>['usuario_id','extension','pbx_call_id','resultado'],
  'telefonia_ventas_resultado_selecciones'=>['resultado_id','codigo'],
  'prospecto_embudo_etapas'=>['asignacion_id','etapa'],
  'prospecto_embudo_actividad_v3'=>['asignacion_id','usuario_id','accion','fecha_pendiente']
];
foreach($req as $tabla=>$columnas){
 $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $s->bind_param('s',$tabla);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();
 if (!$r || strtoupper((string)$r['ENGINE'])!=='INNODB')exit(3);
 $s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $s->bind_param('s',$tabla);$s->execute();$rows=$s->get_result();$found=[];
 while($row=$rows->fetch_assoc())$found[$row['COLUMN_NAME']]=true;
 $s->close();
 foreach($columnas as $c)if (!isset($found[$c]))exit(4);
}
$db->close();echo 'BD_BANDEJAS_READONLY_OK';
"""
    try:
        result=subprocess.check_output(["php","-r",auditor,"--",site],
                    stderr=subprocess.STDOUT).decode("utf-8","replace").strip()
    except (OSError,subprocess.CalledProcessError):
        raise p.Bloqueo("Estructura de BD incompatible con Bandejas V3.")
    if result!="BD_BANDEJAS_READONLY_OK":
        raise p.Bloqueo("Preflight de estructura no confirmado.")
    print("BD_BANDEJAS_SOLO_LECTURA_OK: estructura InnoDB compatible.")


def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--site",required=True)
    ap.add_argument("--stage")
    ap.add_argument("--backup-root")
    ap.add_argument("--backup-dir")
    ap.add_argument("--confirm")
    mode=ap.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check",action="store_true")
    mode.add_argument("--apply",action="store_true")
    mode.add_argument("--rollback",action="store_true")
    args=ap.parse_args()
    site=p.raiz(args.site)
    if not os.path.isfile(p.confiable(site,"config/db_connection.php")):
        raise p.Bloqueo("Sitio incorrecto.")
    if args.rollback:
        if args.confirm!=p.REVERTIR or not args.backup_dir:
            raise p.Bloqueo("Faltan confirmación y respaldo de reversión.")
        backup=p.raiz(args.backup_dir)
        if p.dentro(backup,site):raise p.Bloqueo("Respaldo dentro del sitio.")
        p.revertir(site,backup)
        print("ROLLBACK_BANDEJAS_V3_OK")
        return
    if not args.stage:raise p.Bloqueo("Falta stage.")
    stage=p.raiz(args.stage)
    if p.dentro(stage,site):raise p.Bloqueo("Entrega dentro de sitio público.")
    paquete(stage)
    chequear_bd(site)
    state=p.estado(site)
    if args.check:
        print("CHECK_BANDEJAS_V3_OK: "+("ya aplicado." if state=="READY" else "listo para publicar."))
        return
    if args.confirm!=p.CONFIRMAR or not args.backup_root:
        raise p.Bloqueo("Se requiere autorización expresa y respaldo privado.")
    backup=p.raiz(args.backup_root,False)
    if p.dentro(backup,site):raise p.Bloqueo("Respaldo dentro del sitio.")
    p.publicar(site,stage,backup)
    print("APPLY_BANDEJAS_V3_OK: sin SQL, sin llamadas ni comunicaciones.")


if __name__=="__main__":
    try:main()
    except (p.Bloqueo,OSError,ValueError) as exc:
        print("BLOQUEADO: "+str(exc),file=sys.stderr)
        sys.exit(2)
