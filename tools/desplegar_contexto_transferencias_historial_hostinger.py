#!/usr/bin/env python3
"""Despliegue conservador del contexto y conciliación de transferencias.
--check nunca escribe. --apply solo con coincidencia exacta de la base o
hunks únicos, y respalda TODOS los archivos antes de modificar el primero.
"""
import argparse
import difflib
import pathlib
import shutil
import subprocess
import sys
from datetime import datetime, timezone

FILES = (
    "app/services/TelefoniaEntrantesAnalistaService.php",
    "app/controllers/TelefoniaController.php",
    "public/javascript/telefonia_enrutamiento.js",
    "public/javascript/telefonia_analista_historial_pasivo.js",
)
COMMIT = "ef351d72f8625a0eed745696a41538a134369b32"
BASE = "c9c0fe46ebbe7dfdcce27dd7be6ad430c0741077"


def git(*args):
    return subprocess.run(["git", *args], check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE).stdout.decode("utf-8")


def aplicar_hunks(actual, original, nuevo):
    if actual == nuevo:
        return actual, "ya actualizado"
    if actual == original:
        return nuevo, "base exacta"
    # Compatibilidad conservadora: solo sustituir bloques con contexto
    # idéntico y único. No intentar combinar si el contexto fue personalizado.
    diffs = list(difflib.unified_diff(
        original.splitlines(keepends=True), nuevo.splitlines(keepends=True),
        n=5,
    ))
    if len(diffs) < 3:
        raise ValueError("No hay modificaciones para fusionar")
    resultado = actual
    old, new = [], []
    hunks = []

    def guardar():
        nonlocal old, new
        if old or new:
            hunks.append(("".join(old), "".join(new)))
        old, new = [], []

    for line in diffs[2:]:
        if line.startswith("@@"):
            guardar()
        elif line.startswith(" "):
            old.append(line[1:])
            new.append(line[1:])
        elif line.startswith("-"):
            old.append(line[1:])
        elif line.startswith("+"):
            new.append(line[1:])
        elif line.startswith("\\ No newline"):
            raise ValueError("Cambio de final de línea no fusionable")
    guardar()
    if not hunks:
        raise ValueError("No se identificaron bloques de modificación")
    for before, after in hunks:
        if not before or resultado.count(before) != 1:
            raise ValueError("Bloque original ausente o repetido: posible personalización")
        resultado = resultado.replace(before, after, 1)
    return resultado, "hunks compatibles"


def aplicar_ajuste_selectivo(actual, relative):
    """
    Rescate seguro de dos personalizaciones conocidas. No ejecuta una
    fusión textual aproximada ni elimina líneas existentes.
    Ante marcadores ausentes, repetidos o previamente intervenidos, aborta.
    """
    if relative == "app/controllers/TelefoniaController.php":
        start = actual.find("    public function validarOrdenTransferencia()")
        end = actual.find("    public function guardarExtension()", start)
        if start < 0 or end < start:
            raise ValueError("No se reconoce la función autorizadora personalizada")
        scope = actual[start:end]
        guard = "                    if (!$contextoGuardado) {\n"
        catch = "                } catch (Throwable $error) {\n"
        required = (
            "if (($data['tipo'] ?? '') === 'INSTITUCION'",
            "$contextoGuardado = (new TelefoniaTransferenciaContextoService())->registrar(",
            "private function responderJson(array $datos",
        )
        if (not all(item in actual for item in required)
                or scope.count(guard) != 1
                or scope.count(catch) != 1
                or "No fue posible conservar la institución confirmada." in scope):
            raise ValueError("Contexto personalizado no verificable; no se modifica")
        fail = (
            "                        error_log('[telefonia_contexto_transferencia] "
            "Identificación institucional no vinculada.');\n"
            "                        $this->responderJson([\n"
            "                            'ok'=>false,\n"
            "                            'mensaje'=>'No fue posible conservar la institución confirmada. "
            "No se transfirió la llamada; vuelve a intentarlo.'\n"
            "                        ], 409);\n"
        )
        # La respuesta JSON sale del proceso; el bloque de log existente
        # permanece, sin riesgo de borrar otras personalizaciones.
        scope = scope.replace(guard, guard + fail, 1)
        scope = scope.replace(
            catch,
            catch +
            "                    error_log('[telefonia_contexto_no_disponible] ' . "
            "get_class($error));\n" +
            "                    $this->responderJson([\n" +
            "                        'ok'=>false,\n" +
            "                        'mensaje'=>'No fue posible conservar la institución confirmada. "
            "No se transfirió la llamada; vuelve a intentarlo.'\n" +
            "                    ], 409);\n",
            1,
        )
        return actual[:start] + scope + actual[end:], "ajuste selectivo verificado"

    if relative == "public/javascript/telefonia_analista_historial_pasivo.js":
        # Dos variantes conocidas: la base GitHub y la implementación local
        # de Hostinger. Conservamos la función, el formato y el intervalo
        # de cada variante; solo ampliamos el horizonte de reintentos.
        variants = (
            (
                "const fetchReceptionContext = async",
                "if (attempt < 8 && active?.id === id)",
                "if (attempt < 16 && active?.id === id)",
                "void fetchReceptionContext(id, phone, attempt + 1)",
                "}, 800);",
            ),
            (
                "const fetchContext = async",
                "if (attempt < 9 && active?.id === id)",
                "if (attempt < 17 && active?.id === id)",
                "void fetchContext(id, phone, attempt + 1)",
                "750);",
            ),
        )
        for function_name, old, new, call, interval in variants:
            if (actual.count(function_name) != 1 or actual.count(old) != 1):
                continue
            start = actual.find(function_name)
            end = actual.find("const postEvent = function", start)
            if end <= start:
                raise ValueError("Observador sin límites verificables; no se modifica")
            scope = actual[start:end]
            if (scope.count("const retry = () => {") != 1 or
                    scope.count(old) != 1 or
                    scope.count(call) != 1 or
                    scope.count(interval) != 1 or
                    "action=contextoTransferencia" not in scope or
                    new in actual):
                raise ValueError("Reintento SDK personalizado no verificable; no se modifica")
            return actual[:start] + scope.replace(old, new, 1) + actual[end:], "ajuste selectivo verificado"
        raise ValueError("Reintento SDK personalizado no verificable; no se modifica")

    raise ValueError("El archivo no admite un ajuste selectivo")


def main():
    cli = argparse.ArgumentParser()
    cli.add_argument("--check", action="store_true")
    cli.add_argument("--apply", action="store_true")
    cli.add_argument("--live", required=True)
    args = cli.parse_args()
    if args.check == args.apply:
        cli.error("Selecciona exclusivamente --check o --apply")
    repo = pathlib.Path(__file__).resolve().parents[1]
    live = pathlib.Path(args.live).expanduser().resolve()
    if not live.is_dir() or not (live / "app").is_dir():
        cli.error("La ruta real del CRM no es válida.")
    ancestor = subprocess.run(
        ["git", "-C", str(repo), "merge-base", "--is-ancestor", COMMIT, "HEAD"]
    )
    if ancestor.returncode != 0:
        cli.error("El checkout no contiene el commit aprobado: " + COMMIT)
    outputs = []
    problems = []
    for relative in FILES:
        dest = live / relative
        target = git("-C", str(repo), "show", COMMIT + ":" + relative)
        try:
            base = git("-C", str(repo), "show", BASE + ":" + relative)
        except subprocess.CalledProcessError:
            base = None
        if base is None:
            if dest.exists() and dest.read_text(encoding="utf-8") != target:
                problems.append(relative + ": existe un archivo nuevo distinto")
                continue
            outputs.append((relative, dest, target, "nuevo" if not dest.exists() else "ya actualizado"))
            continue
        if not dest.is_file():
            problems.append(relative + ": archivo de producción ausente")
            continue
        try:
            result, mode = aplicar_hunks(dest.read_text(encoding="utf-8"), base, target)
            outputs.append((relative, dest, result, mode))
        except (UnicodeDecodeError, ValueError) as err:
            if relative not in (
                "app/controllers/TelefoniaController.php",
                "public/javascript/telefonia_analista_historial_pasivo.js",
            ):
                problems.append(relative + ": " + str(err))
                continue
            try:
                # Sólo rescatar cuando el diff con contexto amplio falló.
                # Se sustituyen marcadores inconfundibles y no se fuerza nada.
                actual = dest.read_text(encoding="utf-8")
                result, mode = aplicar_ajuste_selectivo(actual, relative)
                outputs.append((relative, dest, result, mode))
            except (UnicodeDecodeError, ValueError) as rescue_error:
                problems.append(relative + ": " + str(rescue_error))
    for relative, _, _, mode in outputs:
        print("COMPATIBLE:", relative, "(" + mode + ")")
    for error in problems:
        print("CONFLICTO:", error)
    if problems:
        print("ABORTADO: ningún archivo modificado.")
        return 2
    if args.check:
        print("CHECK OK: " + str(len(outputs)) + " archivos; producción intacta.")
        return 0
    pending = [(rel, dest, content) for rel, dest, content, _ in outputs
               if not dest.is_file() or dest.read_text(encoding="utf-8") != content]
    if not pending:
        print("YA INSTALADO: no se requirieron cambios.")
        return 0
    backup = pathlib.Path.home() / "crm-deploy-backups" / ("transferencia-contexto-historial-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S"))
    backup.mkdir(parents=True, exist_ok=False)
    for relative, dest, _ in pending:
        if dest.exists():
            path = backup / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(dest, path)
    # Se preparan primero los nuevos archivos en .tmp; reemplazos atómicos.
    try:
        for relative, dest, content in pending:
            dest.parent.mkdir(parents=True, exist_ok=True)
            tmp = dest.with_name(dest.name + ".contexto-historial.tmp")
            if tmp.exists():
                raise RuntimeError("Archivo temporal ya existente: " + str(tmp))
            tmp.write_text(content, encoding="utf-8")
            if dest.exists():
                shutil.copymode(dest, tmp)
            if relative.endswith(".php"):
                verifier = subprocess.run(
                    ["php", "-l", str(tmp)], capture_output=True, text=True
                )
                if verifier.returncode != 0:
                    raise RuntimeError("PHP inválido antes de instalar: " +
                        relative + " " + verifier.stderr)
        for relative, dest, _ in pending:
            dest.with_name(dest.name + ".contexto-historial.tmp").replace(dest)
            print("INSTALADO:", relative)
    except Exception:
        for relative, dest, _ in pending:
            tmp = dest.with_name(dest.name + ".contexto-historial.tmp")
            if tmp.exists():
                tmp.unlink()
            original = backup / relative
            if original.is_file():
                shutil.copy2(original, dest)
        raise
    print("APLICACIÓN TERMINADA; respaldo en:", backup)
    return 0


if __name__ == "__main__":
    sys.exit(main())
