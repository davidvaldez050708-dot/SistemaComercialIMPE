"""Compara y publica únicamente los cambios de ejecución del ajuste MySQL."""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile


BASE = "a90b6ade"
AMBITO = ("config/db_connection.php", "index.php", "app", "public", "prueba_telefonia")
CONTROLADOR_TELEFONIA = "app/controllers/TelefoniaController.php"
SERVICIO_DISPONIBILIDAD = "app/services/TelefoniaDisponibilidadService.php"
JS_DISPONIBILIDAD = "public/javascript/telefonia_disponibilidad.js"
JS_TRANSFERENCIA = "public/javascript/telefonia_transferencia_runtime.js"


def git(*args, **kwargs):
    return subprocess.check_output(("git",) + args, **kwargs)


def digest(datos):
    return hashlib.sha256(datos).hexdigest()


def archivo(sitio, nombre):
    ruta = sitio / nombre
    if not ruta.is_file() or ruta.resolve() != ruta:
        raise SystemExit("Archivo ausente o enlazado: " + nombre)
    return ruta


def verificar(sitio, hashes):
    for nombre, esperado in hashes.items():
        if digest(archivo(sitio, nombre).read_bytes()) != esperado:
            raise SystemExit("El archivo cambió fuera del publicador: " + nombre)


def aplicar(sitio, parche, *opciones):
    comando = ("git", "-c", "core.autocrlf=false", "apply", "--whitespace=nowarn")
    subprocess.run(comando + opciones + ("-",), cwd=sitio, input=parche, check=True)


def verificar_esquema(sitio):
    php = r"""
        try {
            require getcwd() . '/config/db_connection.php';
            $db = (new Database())->connect(false);
            foreach ([
                'SELECT usuario_id, extension, activo FROM telefonia_extensiones LIMIT 0',
                'SELECT usuario_id, extension, ocupado, updated_at FROM telefonia_presencia LIMIT 0',
                'SELECT estado, expires_at, devolucion FROM telefonia_disponibilidad LIMIT 0',
                'SELECT referencia, estado, sdk_consulta FROM telefonia_transferencia_operaciones LIMIT 0',
                'SELECT event_hash, internal, evento, received_at FROM telefonia_zadarma_eventos LIMIT 0',
            ] as $consulta) {
                if (!$db->query($consulta)) exit(1);
            }
            if (!$db->query("SHOW INDEX FROM telefonia_zadarma_eventos
                WHERE Key_name='idx_zadarma_incoming_lookup'")->num_rows) exit(1);
        } catch (Throwable $e) { exit(1); }
    """
    resultado = subprocess.run(("php", "-d", "display_errors=0", "-r", php), cwd=sitio,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if resultado.returncode:
        raise SystemExit("Esquema de telefonía no verificable. Revisa las migraciones documentadas. Sitio intacto.")
    print("Esquema de telefonía verificado con consultas de solo lectura.")


def variante_transferencia(sitio, nombre, instalado, base, entrega):
    if nombre != CONTROLADOR_TELEFONIA:
        return base, entrega

    # La versión de Hostinger recupera por referencia cuando no hay un ID.
    original = b"'solicitud'=>(int)($_POST['id']??0)>0?$svc->obtener($user,(int)$_POST['id']):null,"
    alternativo = original.replace(
        b":null,", b":$svc->delOrigen($user,(string)($_POST['referencia']??'')),",
    )
    retorno = b"return $svc->delOrigen($user, (string)($_POST['referencia'] ?? ''));"
    if instalado.count(alternativo) != 1 and instalado.count(retorno) != 1:
        return base, entrega

    argumento = b"($_POST['consultar_devoluciones'] ?? '1') === '1'"
    if (base.count(original) != 1 or entrega.count(argumento) != 1
            or b"public function delOrigen(" not in archivo(sitio, SERVICIO_DISPONIBILIDAD).read_bytes()):
        raise SystemExit("No se pudo verificar la variante de transferencia por referencia. Sitio intacto.")
    return base.replace(original, alternativo), entrega.replace(
        argumento,
        argumento + b",\n                        static function () use ($svc, $user) {\n"
        b"                            " + retorno + b"\n"
        b"                        }",
    )


def sustituir_variante(contenido, original, variante, nombre):
    if contenido.count(original) != 1:
        raise SystemExit("No se pudo verificar la variante de " + nombre + ". Sitio intacto.")
    return contenido.replace(original, variante, 1)


def variantes_javascript(nombre, instalado, base, entrega):
    if nombre == JS_DISPONIBILIDAD:
        original = (
            b"   const data=await request({accion:'POLL',id:watched?.id||0,disponible:busy()?'0':'1'});\n"
            b"   if(watched){\n"
        )
        personalizado = (
            b"   const snapshot=watched,active=phone.getActiveCall();\n"
            b"   const data=await request({accion:'POLL',id:snapshot?.id||0,referencia:active?.id||'',disponible:busy()?'0':'1'});\n"
            b"   // No pisar una solicitud creada mientras el polling estaba en vuelo.\n"
            b"   if(watched===snapshot){\n"
        )
        corregido = (
            b"   const consulta=watched;\n"
            b"   const consultar=Date.now()>=consultarDevoluciones;\n"
            b"   const data=await request({accion:'POLL',id:consulta?.id||0,disponible:busy()?'0':'1',consultar_devoluciones:consultar?'1':'0'});\n"
            b"   fallosPoll=0;\n"
            b"   if(consultar)consultarDevoluciones=Date.now()+15000;\n"
            b"   if(watched&&watched===consulta){\n"
        )
        integrado = (
            b"   const snapshot=watched,active=phone.getActiveCall();\n"
            b"   const consultar=Date.now()>=consultarDevoluciones;\n"
            b"   const data=await request({accion:'POLL',id:snapshot?.id||0,referencia:active?.id||'',disponible:busy()?'0':'1',consultar_devoluciones:consultar?'1':'0'});\n"
            b"   fallosPoll=0;\n"
            b"   if(consultar)consultarDevoluciones=Date.now()+15000;\n"
            b"   // No pisar una solicitud creada mientras el polling estaba en vuelo.\n"
            b"   if(watched===snapshot){\n"
        )
        if instalado.count(personalizado) == 1 or instalado.count(integrado) == 1:
            base = sustituir_variante(base, original, personalizado, nombre)
            entrega = sustituir_variante(entrega, corregido, integrado, nombre)

    if nombre == JS_TRANSFERENCIA:
        bloqueo = (
            b"  // TERMINADA puede devolver el mismo registro durante varios sondeos.\n"
            b"  // No volver a asociarlo al di\xc3\xa1logo SIP ya finalizado, aun si reset() lo\n"
            b"  // solt\xc3\xb3. El token pertenece a una llamada; el siguiente INVITE usa otro.\n"
            b"  let lastClosedReceiverToken='';\n"
        )
        sondeo = b"  let nextPoll=0, failures=0;\n"
        reinicio = b"  const reset=()=>"
        parametros = b"url.searchParams.set('sdk',api.read().callToken);}"
        con_solicitud = (
            b"url.searchParams.set('sdk',api.read().callToken);"
            b"if(api.read().context?.availabilityCandidate)"
            b"url.searchParams.set('solicitud_id',api.read().context.availabilityCandidate);}"
        )
        if instalado.count(bloqueo) == 1:
            base = sustituir_variante(base, reinicio, bloqueo + reinicio, nombre)
            entrega = sustituir_variante(entrega, sondeo, bloqueo + sondeo, nombre)
        if instalado.count(con_solicitud) == 1:
            base = sustituir_variante(base, parametros, con_solicitud, nombre)
            entrega = sustituir_variante(entrega, parametros, con_solicitud, nombre)

    return base, entrega


def preparar(sitio, repo, temporal, nombres):
    anteriores, posteriores = {}, {}
    conflictos = []
    for nombre in nombres:
        vivo = archivo(sitio, nombre).read_bytes()
        base = git("show", BASE + ":" + nombre, cwd=repo)
        entrega = git("show", "HEAD:" + nombre, cwd=repo)
        antes = temporal / "antes" / nombre
        despues = temporal / "despues" / nombre
        antes.parent.mkdir(parents=True, exist_ok=True)
        despues.parent.mkdir(parents=True, exist_ok=True)
        antes.write_bytes(vivo)
        finales = vivo.replace(b"\r\n", b"\n")
        base = base.replace(b"\r\n", b"\n")
        entrega = entrega.replace(b"\r\n", b"\n")
        base, entrega = variante_transferencia(sitio, nombre, finales, base, entrega)
        base, entrega = variantes_javascript(nombre, finales, base, entrega)
        if finales == entrega:
            estado = "ya actualizado"
        elif finales == base:
            finales, estado = entrega, "coincide con GitHub"
        else:
            local = temporal / "instalado"
            original = temporal / "base"
            cambio = temporal / "entrega"
            for ruta, contenido in ((local, finales), (original, base), (cambio, entrega)):
                ruta.write_bytes(contenido)
            resultado = subprocess.run(
                ("git", "merge-file", "-p", str(local), str(original), str(cambio)), stdout=subprocess.PIPE,
            )
            if resultado.returncode:
                conflictos.append(nombre)
                continue
            finales, estado = resultado.stdout, "personalizado; integración sin conflicto"
        if b"\r\n" in vivo:
            finales = finales.replace(b"\n", b"\r\n")
        despues.write_bytes(finales)
        if nombre.endswith(".php"):
            subprocess.run(("php", "-l", str(despues)), check=True)
        print(estado + " | " + nombre + " | sha256 instalado " + digest(vivo), flush=True)
        if vivo != finales:
            anteriores[nombre], posteriores[nombre] = digest(vivo), digest(finales)
    if conflictos:
        raise SystemExit("Personalizaciones en conflicto:\n- " + "\n- ".join(conflictos) + "\nSitio intacto.")
    resultado = subprocess.run(
        ("git", "-c", "core.autocrlf=false", "diff", "--no-index", "--binary", "--", "antes", "despues"),
        cwd=temporal, stdout=subprocess.PIPE,
    )
    if resultado.returncode not in (0, 1):
        raise SystemExit("No se pudo generar el parche.")
    parche = resultado.stdout.replace(b"a/antes/", b"a/").replace(b"b/despues/", b"b/")
    return parche, anteriores, posteriores


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True, type=Path)
    parser.add_argument("--backup-root", type=Path)
    modo = parser.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    modo.add_argument("--restore", type=Path)
    args = parser.parse_args()
    sitio = args.site.resolve(strict=True)
    if args.restore:
        respaldo = args.restore.resolve(strict=True)
        manifiesto = json.loads((respaldo / "manifiesto.json").read_text(encoding="utf-8"))
        parche = (respaldo / "cambios.patch").read_bytes()
        if manifiesto["sitio"] != str(sitio) or manifiesto["parche"] != digest(parche):
            raise SystemExit("El respaldo no corresponde al sitio o su parche fue modificado.")
        verificar(sitio, manifiesto["despues"])
        aplicar(sitio, parche, "--reverse", "--check")
        aplicar(sitio, parche, "--reverse")
        verificar(sitio, manifiesto["antes"])
        print("Archivos anteriores restaurados.")
        return
    if args.apply:
        if args.backup_root is None:
            parser.error("--apply requiere --backup-root")
        raiz = args.backup_root.resolve()
        if raiz == sitio or sitio in raiz.parents:
            parser.error("El respaldo debe guardarse fuera del sitio")

    repo = Path(__file__).resolve().parent.parent
    nombres = git("diff", "--name-only", BASE, "HEAD", "--", *AMBITO, cwd=repo).decode("utf-8").splitlines()
    if not nombres:
        raise SystemExit("No se encontraron archivos de la entrega.")
    with tempfile.TemporaryDirectory() as temporal:
        parche, antes, despues = preparar(sitio, repo, Path(temporal), nombres)
    if not parche:
        print("El sitio ya está actualizado.")
        return
    aplicar(sitio, parche, "--check")
    verificar_esquema(sitio)
    if args.check:
        print("Comprobación completa. Sitio intacto.")
        return

    raiz.mkdir(parents=True, exist_ok=True, mode=0o700)
    respaldo = Path(tempfile.mkdtemp(prefix="mysql-hostinger-", dir=raiz))
    verificar(sitio, antes)
    with tarfile.open(respaldo / "archivos-antes.tar.gz", "w:gz") as copia:
        for nombre in antes:
            copia.add(archivo(sitio, nombre), arcname=nombre)
    (respaldo / "cambios.patch").write_bytes(parche)
    manifiesto = {"sitio": str(sitio), "parche": digest(parche), "antes": antes, "despues": despues}
    (respaldo / "manifiesto.json").write_text(json.dumps(manifiesto), encoding="utf-8")
    print("Respaldo: " + str(respaldo), flush=True)
    verificar(sitio, antes)
    aplicar(sitio, parche)
    verificar(sitio, despues)
    print("Publicación completada.")


if __name__ == "__main__":
    main()
