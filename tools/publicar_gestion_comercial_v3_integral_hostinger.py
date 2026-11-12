#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Cierre integral de Gestión Comercial V3; publicación de código exacto.
CHECK / APPLY / ROLLBACK, con backup privado y sin SQL de escritura.
Python >=3.6. El esquema de inscripciones se instala por separado y
este publicador BLOQUEA APPLY hasta validar ese esquema.
"""
from __future__ import print_function
import argparse
import json
import os
import stat
import subprocess
import sys

import publicar_ventas_v3_gestion_potenciales_hostinger as p

# Orden: dependencias nuevas -> recursos -> servicios -> endpoints ->
# vistas. No se reinstala el piloto telefónico descartado de Hostinger.
p.ARCHIVOS = [
    ("app/services/ProspectoNoContactarV3.php",None,
     "27b957a3ef3ba45fa61ff57947a7443b1c725436"),
    ("app/services/EmbudoComercialV3InscripcionService.php",None,
     "3b2c1d8f4f4fbb5e9e502df80703c81ed07b4701"),
    ("app/views/embudo_comercial/inscripcion_v3_modal.php",None,
     "bd74039d75e42556c06b8b6598746fb12590fe04"),
    ("public/javascript/embudo_comercial_v3_inscripcion.js",None,
     "edb6396c459f9ffb1d62de415322fb68db98437b"),
    ("public/css/embudo_v3_bandejas.css",
     "9792c5b366458ec3a228c47400961a6608fc7ae9",
     "26857d52d4eb673856cc2b03d1bb2bb8e349c8fa"),
    ("public/javascript/embudo_comercial_v3_bandejas.js",
     "ea4093008c1e9dd6aaa869867b141e3d81b6c1d3",
     "2476f6af122577b31bb384ed1bba0fb73ea6556a"),
    ("app/services/EmbudoComercialV3BandejasService.php",
     "295c8510e63aa7f2ca4ba1f802d18ae5ebb85393",
     "118eb819b21fbfb7d49bcaa1877e899b5667cb50"),
    ("app/services/TelefoniaMarcacionesVentasService.php",
     "b593331b00b961da312a04326952572bcf6bb194",
     "9d2b57adc2c7cdc4171b40a8e1a9d3453364a95a"),
    ("app/models/WhatsAppModel.php",
     "04497159d1bcfe7c87334d31394f9745cd0090c1",
     "6778077a321e0ebc16ee19885fc5c32eda6496c6"),
    ("app/services/WhatsAppCloudApiService.php",
     "e4c838179ee920d8a3b0eb0e5fbb32117daab550",
     "19af3ebdc78936fddde32ee436f55699f1f34e24"),
    ("app/controllers/WhatsAppController.php",
     "1f4dfc8e8521333e6007dcc6669250a3808d343f",
     "527c2861d90ba04bd885c15ddf6c8f49f674d31f"),
    ("prueba_telefonia/api/zadarma_estado_llamada.php",
     "54faf612322f68935899857305b11413cf1bbc0c",
     "1fc93e96a5e70908c3b33fbd60f13b53fd58c9f1"),
    ("public/javascript/telefonia_marcador.js",
     "1880a0ab4914ed32dda851748941a1f3b8845716",
     "0287d86911a1851f0c756d905b48b8893df5116a"),
    ("app/controllers/TelefoniaController.php",
     "fbe30cfbd69b2d881c8e330fe5988e6674ff36b2",
     "40f57513643c8f91202c94b2c468b7d93d60e30d"),
    ("app/services/EmbudoComercialRepository.php",
     "e89800e97c819e331ed29e51d9d211a1d8d4e5b5",
     "b89744e35c369ac24bbd2c30403a7db5a3e00a5a"),
    ("app/controllers/EmbudoComercialController.php",
     "be6249a96ff26fb46cc9616da1a9767e036b826f",
     "3d02468e4b561abf83c720dc370d6cccf422c6bc"),
    ("app/views/embudo_comercial/detalle.php",
     "f4c7449bf96a4c680db7ce5e0552a3b0cd624068",
     "14a2acb3668d6896e52ebec9812b0f23ec317d7c"),
    ("app/views/embudo_comercial/bandejas_v3_modal.php",
     "f085408107f37d83a7df29bd2dd4d1e0138afdd6",
     "0d1d1b306aac7e3e1ab4b685b71cf4e4fdf78706"),
    ("app/views/embudo_comercial/bandejas_v3.php",
     "cab2a1c4c293aafa798148f4382425ae692e2a5b",
     "3e7c58436cdfb99f00d3c417213dd004afcc4d69"),
    ("app/views/embudo_comercial/operativo_v3.php",
     "bddf4fa6a69e248ef71770c61346145392542f58",
     "23571f88687958519b902da855b914e665313d48"),
]
p.NO_MODIFICAR = {
    "app/services/EmbudoComercialResultadoBridge.php":
        "e4f6b23d989ff99539088153df58d4ec2404f79d",
    "app/services/EmbudoComercialV3AccesoControlado.php":
        "480ce33e3622eb44116c3aed578c0a753378eaa9",
    "app/services/TelefoniaResultadoVentasService.php":
        "1d4472ba73e7de4097ef2f592104160b254d7f8f",
    "app/services/EmbudoComercialV3MovimientosPermitidos.php":
        "2bbc296fff1c310963637ed3f95402b9254635a3",
}
p.CONFIRMAR = "PUBLICAR_GESTION_COMERCIAL_V3_INTEGRAL_IMPE"
p.REVERTIR = "REVERTIR_GESTION_COMERCIAL_V3_INTEGRAL_IMPE"

MIGRACION = "database/migrations/2026_11_12_ventas_inscripcion_v3_evidencia.sql"
MIGRACION_HASH = "8374abf8aae014f6eb5e72b29d7ba61cee82c474"
CONTRATOS = {
    "app/services/ProspectoNoContactarV3.php": [
        b"class ProspectoNoContactarV3",b"sub.subetapa='NO_CONTACTAR'"
    ],
    "app/services/EmbudoComercialV3InscripcionService.php": [
        b"prospecto_embudo_inscripciones_v3",b"FOR UPDATE",
        b"formulario_inscripciones",b"begin_transaction",b"rollback()"
    ],
    "app/services/WhatsAppCloudApiService.php": [
        b"ProspectoNoContactarV3::exigirPermitido",b"conexionAutorizacion"
    ],
    "app/controllers/EmbudoComercialController.php": [
        b"inscripcionOperativaV3()",b"bandejasOperativasV3()",
        b"hash_equals"
    ],
    "prueba_telefonia/api/zadarma_estado_llamada.php": [
        b"if ($solicitarCheckpoint)",b"ProspectoNoContactarV3::exigirPermitido",
    ],
    "public/javascript/telefonia_marcador.js": [
        b"await registrar;",b"await api.startCall("
    ],
    "app/views/embudo_comercial/operativo_v3.php": [
        b"data-v3i-iniciar",b"inscripcion_v3_modal.php"
    ],
}

CHECKPOINT = "prueba_telefonia/api/zadarma_estado_llamada.php"
CHECKPOINT_HOSTINGER_SHA = "88956a12e6bfed364cb007e1555ed0644d8b8cb0"

def validar_checkpoint(stage):
    source=p.confiable(stage,CHECKPOINT)
    data=p.leer(source)
    # La variante productiva revisada conserva el pool y la respuesta 503
    # temporal. Su hash completo se fija en el manifiesto conciliado.
    if p.blob(source)==CHECKPOINT_HOSTINGER_SHA:
        required=(
            b"new TelefoniaExtensionService(Database::compartida())",
            b"new ZadarmaWebhookEventStoreService(Database::compartida())",
            b"Database::responderFalloTemporal",
        )
        if any(marker not in data for marker in required) or (
            b"(new Database())->connect()" in data):
            raise p.Bloqueo("checkpoint conciliado perdió conexión compartida")
    elif b"new TelefoniaExtensionService($conexionTelefonia)" not in data:
        raise p.Bloqueo("contrato de extensión telefónica faltante")

def paquete(stage):
    for path,old,new in p.ARCHIVOS:
        if len(new)!=40 or not all(c in "0123456789abcdef" for c in new):
            raise p.Bloqueo("hash de entrega no válido: "+path)
        if old is not None and (len(old)!=40 or
                not all(c in "0123456789abcdef" for c in old)):
            raise p.Bloqueo("SHA base pendiente de conciliación: "+path)
        source=p.confiable(stage,path)
        if not os.path.isfile(source) or p.blob(source)!=new:
            raise p.Bloqueo("paquete no íntegro: "+path)
        if path.endswith(".php"):p.lint_php(source)
        if path.endswith(".js"):p.lint_js(source)
    sql=p.confiable(stage,MIGRACION)
    if not os.path.isfile(sql) or p.blob(sql)!=MIGRACION_HASH:
        raise p.Bloqueo("SQL de migración no coincide con la versión revisada.")
    data=p.leer(sql)
    if b"CREATE TABLE IF NOT EXISTS prospecto_embudo_inscripciones_v3" not in data:
        raise p.Bloqueo("migración de evidencia inesperada.")
    for path,marcas in CONTRATOS.items():
        data=p.leer(p.confiable(stage,path))
        for marca in marcas:
            if marca not in data:raise p.Bloqueo("contrato faltante en "+path)
    validar_checkpoint(stage)
    print("PAQUETE_INTEGRAL_OK: 20 archivos PHP/JS/CSS y migración SQL íntegros.")

def preflight_esquema(site):
    script=r"""
$site=$argv[1];
require $site.'/config/db_connection.php';
$db=(new Database())->connect(false);
try {
  $refs=[
    'prospectos_comerciales'=>['id','telefono_normalizado','correo'],
    'prospecto_asignaciones'=>['id','prospecto_id','asesor_id','vigente'],
    'formulario_inscripciones'=>['id','movil','correo','created_at'],
    'prospecto_embudo_etapas'=>['asignacion_id','etapa'],
    'prospecto_embudo_subetapas'=>['asignacion_id','subetapa'],
    'prospecto_embudo_inscripciones_v3'=>[
      'asignacion_id','prospecto_id','formulario_inscripcion_id',
      'vinculado_por','evidencia_tipo','verificado_at']
  ];
  foreach ($refs as $table=>$cols) {
    $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->bind_param('s',$table);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if (!$row) {
      if ($table==='prospecto_embudo_inscripciones_v3') {echo 'ESQUEMA_PENDIENTE';exit(0);}
      exit(3);
    }
    if (strtoupper((string)$row['ENGINE'])!=='INNODB')exit(4);
    $s=$db->prepare('SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $s->bind_param('s',$table);$s->execute();$res=$s->get_result();$found=[];
    while($item=$res->fetch_assoc())$found[$item['COLUMN_NAME']]=$item;
    $s->close();
    foreach($cols as $c)if(!isset($found[$c]))exit(5);
    if (in_array($table,['prospectos_comerciales','prospecto_asignaciones'],true) ||
        $table==='formulario_inscripciones') {
      $id=$found['id'];
      $expected=$table==='formulario_inscripciones'?'int':'bigint';
      if($id['DATA_TYPE']!==$expected ||
         strpos(strtolower($id['COLUMN_TYPE']),'unsigned')===false)exit(6);
    }
  }
  $s=$db->query("SELECT COUNT(*) AS total FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='prospecto_embudo_inscripciones_v3'
         AND INDEX_NAME='uq_peiv3_formulario' AND NON_UNIQUE=0");
  if ((int)$s->fetch_assoc()['total']<1)exit(7);
  echo 'ESQUEMA_INTEGRAL_OK';
} finally {$db->close();}
"""
    try:
        value=subprocess.check_output(
            ["php","-r",script,"--",site],stderr=subprocess.STDOUT
        ).decode("utf-8","replace").strip()
    except (subprocess.CalledProcessError,OSError):
        raise p.Bloqueo("schema de Gestión Comercial incompatible (solo lectura).")
    if value not in ("ESQUEMA_INTEGRAL_OK","ESQUEMA_PENDIENTE"):
        raise p.Bloqueo("resultado inesperado de la verificación de schema.")
    print(value)
    return value=="ESQUEMA_INTEGRAL_OK"

def publicar(site,stage,root):
    if p.estado(site)=="READY":
        print("YA_PUBLICADA: entrega integral intacta.")
        return
    respaldo,data=p.respaldar(site,root)
    print("RESPALDO_PRIVADO_OK: "+respaldo)
    try:
        for rel,old,new in p.ARCHIVOS:
            dest=p.confiable(site,rel)
            source=p.confiable(stage,rel)
            if old is None:
                if os.path.lexists(dest):
                    raise p.Bloqueo("archivo nuevo aparecio durante APPLY: "+rel)
                mode=0o644
            else:
                if not os.path.isfile(dest) or p.blob(dest)!=old:
                    raise p.Bloqueo("personalizacion encontrada durante APPLY: "+rel)
                mode=stat.S_IMODE(os.stat(dest).st_mode)
            p.atomico(dest,source,mode)
            if p.blob(dest)!=new:raise p.Bloqueo("SHA posterior inválido: "+rel)
            if rel.endswith(".php"):p.lint_php(dest)
            print("PUBLICADO: "+rel)
        if p.estado(site)!="READY":
            raise p.Bloqueo("inconsistencia posterior de publicación.")
        data["estado"]="PUBLICADO"
        p.manifest(respaldo,data)
        print("APPLY_INTEGRAL_OK: teléfono, WhatsApp, consentimiento, embudo e inscripción.")
        print("Sin SQL de escritura durante publicación del código.")
        print("ROLLBACK_BACKUP: "+respaldo)
    except BaseException:
        try:
            p.restaurar(site,respaldo,data,permit_old=True)
            print("ROLLBACK_AUTOMATICO_OK")
        except Exception as exc:
            print("ROLLBACK_BLOQUEADO: "+str(exc),file=sys.stderr)
        raise

def revertir(site,backup):
    backup=p.raiz(backup)
    with open(os.path.join(backup,'manifest.json'),'r') as f:data=json.load(f)
    if (data.get("site")!=site or
        data.get("estado") not in ("RESPALDADO","PUBLICADO") or
        set(data.get("modes",{}))!=set(x for x,_,_ in p.ARCHIVOS)):
        raise p.Bloqueo("respaldo no corresponde a la entrega integral.")
    p.restaurar(site,backup,data,permit_old=data["estado"]=="RESPALDADO")
    print("ROLLBACK_INTEGRAL_OK: código restaurado. Tabla de evidencia conservada.")

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True)
    parser.add_argument('--stage')
    parser.add_argument('--backup-root')
    parser.add_argument('--backup-dir')
    parser.add_argument('--confirm')
    flags=parser.add_mutually_exclusive_group(required=True)
    flags.add_argument('--check',action='store_true')
    flags.add_argument('--apply',action='store_true')
    flags.add_argument('--rollback',action='store_true')
    args=parser.parse_args()
    site=p.raiz(args.site)
    if not os.path.isfile(p.confiable(site,'config/db_connection.php')):
        raise p.Bloqueo('no se identificó la raíz del CRM')
    if args.rollback:
        if args.confirm!=p.REVERTIR or not args.backup_dir:
            raise p.Bloqueo('reversión sin confirmación y respaldo exacto')
        revertir(site,args.backup_dir)
        return
    if not args.stage:raise p.Bloqueo('falta carpeta privada de entrega')
    stage=p.raiz(args.stage)
    if p.dentro(stage,site):raise p.Bloqueo('entrega dentro de public_html')
    paquete(stage)
    p.check_db_lectura(site)
    schema_ready=preflight_esquema(site)
    current=p.estado(site)
    if args.check:
        if not schema_ready:
            print('CHECK_INTEGRAL_ESQUEMA_PENDIENTE: NO ejecutar APPLY.')
        else:
            print('CHECK_INTEGRAL_OK: '+('ya aplicado.' if current=='READY'
                                       else 'código listo para autorización.'))
        return
    if not schema_ready:
        raise p.Bloqueo('falta la migración aditiva de evidencia; no publicar')
    if args.confirm!=p.CONFIRMAR or not args.backup_root:
        raise p.Bloqueo('falta confirmación explícita y respaldo privado')
    root=p.raiz(args.backup_root,False)
    if p.dentro(root,site):raise p.Bloqueo('respaldo dentro de public_html')
    publicar(site,stage,root)

if __name__=='__main__':
    try:main()
    except (p.Bloqueo,OSError,ValueError,subprocess.CalledProcessError) as e:
        print('BLOQUEADO: '+str(e),file=sys.stderr);sys.exit(2)
