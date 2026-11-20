"""Genera sólo deltas sobre las versiones verificadas por SHA en Hostinger.

Las copias privadas se usan localmente; no se incluyen en Git ni en el paquete.
"""
from pathlib import Path
import difflib
import hashlib

def replace_once(text, before, after):
    assert text.count(before) == 1, before[:150]
    return text.replace(before, after, 1)

def hunks(before, after, context=3):
    a, b = before.splitlines(keepends=True), after.splitlines(keepends=True)
    return [{'before': ''.join(a[g[0][1]:g[-1][2]]), 'after': ''.join(b[g[0][3]:g[-1][4]])}
            for g in difflib.SequenceMatcher(None, a, b, autojunk=False).get_grouped_opcodes(context)]

def extend(root, changes):
    dep = root / 'tools/estabilidad_403_503/dependency_snapshot_20261110'
    controller = 'app/controllers/ReminderController.php'
    jsfile = 'public/javascript/recordatorios.js'
    originals = {
        controller: root / 'tools/estabilidad_403_503/additional_candidates' / controller,
        jsfile: root / 'tools/estabilidad_403_503/additional_production_20261119' / jsfile,
        'app/models/AliadoModel.php': root / 'output/correccion_reporte_aliados_manuales/hostinger/app/models/AliadoModel.php',
    }
    for name in ['ReminderAgendaFilterService.php', 'ReminderReunionFollowupService.php', 'AliadoSeguimientoReminderService.php']:
        originals['app/services/' + name] = dep / 'app/services' / name
    sources, candidates = {}, {}
    for file, path in originals.items():
        if not path.exists(): continue
        sources[file] = path.read_text(encoding='utf-8')
    helper = 'app/helpers/ReminderHelper.php'
    text = (dep / helper).read_text(encoding='utf-8')
    marker = "                'fecha' => (string)($recordatorio['proxima_accion_at'] ?? ''),\n"
    text = replace_once(text, marker, marker + "                'alerta_at' => !empty($recordatorio['proxima_accion_at'])\n                    ? strtotime($recordatorio['proxima_accion_at']) : null,\n")
    assert hashlib.sha256(text.encode()).hexdigest() == 'e7fcf5a409dcafb952b06e946a0925635b315c47bf7af00b19b122bf56fb4684'
    sources[helper] = text
    for file, before in sources.items():
        after = before
        if file == controller:
            after = replace_once(after, "        $esAnalista = tienePermiso('reuniones.solicitar');\n",
                "        $esAnalista = tienePermiso('reuniones.solicitar');\n        $puedeOperarSeguimientos = tienePermiso('seguimientos_vinculacion.operar_propios');\n")
            after = replace_once(after, 'if (!$esAdministrador && $puedeCentroReuniones && $esAnalista)',
                'if (!$esAdministrador && ($puedeOperarSeguimientos || ($puedeCentroReuniones && $esAnalista)))')
            after = replace_once(after, 'if ($puedeCentroReuniones && $esAnalista)',
                'if ($puedeOperarSeguimientos || ($puedeCentroReuniones && $esAnalista))')
            local = (root / controller).read_text(encoding='utf-8')
            for h in changes[file][0]:
                if '$recordatoriosObservaciones,' in h['before']:
                    after = replace_once(after, h['before'], h['after'])
            after = replace_once(after, 'array_slice($otros, 0, 12)', '$this->seleccionarRecordatoriosCampana($otros)')
            after = replace_once(after, '        $ordenados = $this->ordenarRecordatorios($recordatorios);',
                "        $accionesPendientes = array_values(array_filter($recordatorios,\n            static fn($item) => array_key_exists('tipo_anticipo', $item)));\n        $ordenados = $this->ordenarRecordatorios($recordatorios);")
            after = replace_once(after, "            'recordatorios' => $recordatorios,", "            'acciones_pendientes' => $accionesPendientes,\n            'recordatorios' => $recordatorios,")
            method = local[local.index('    private function seleccionarRecordatoriosCampana('):local.index('    private function ordenarRecordatorios(')]
            after = replace_once(after, '    private function ordenarRecordatorios(', method + '    private function ordenarRecordatorios(')
        elif file == jsfile:
            local = (root / jsfile).read_text(encoding='utf-8')
            after = replace_once(after, '        const fechaRecordatorio = function (recordatorio) {\n',
                '        const fechaRecordatorio = function (recordatorio) {\n            const timestamp = Number(recordatorio.alerta_at || 0);\n            if (Number.isFinite(timestamp) && timestamp > 0) return new Date(timestamp * 1000);\n')
            audio = local[local.index('        let audioAcciones ='):local.index('        const estadoVisibleRecordatorio =')]
            after = replace_once(after, '        const estadoVisibleRecordatorio =', audio + '        const estadoVisibleRecordatorio =')
            combine = local[local.index('        const unirAccionesPendientes ='):local.index('        const fechaRecordatorio =')]
            after = replace_once(after, '        const fechaRecordatorio =', combine + '        const fechaRecordatorio =')
            after = replace_once(after, 'const recibidos=Array.isArray(datos.recordatorios)?datos.recordatorios:[];',
                'const recibidos=unirAccionesPendientes(datos.recordatorios,datos.acciones_pendientes);')
            upcoming = local[local.index('        const mostrarProximaAccion ='):local.index('        const mostrarSiguienteToastVencido =')]
            after = replace_once(after, '        const mostrarSiguienteToastVencido =', upcoming + '        const mostrarSiguienteToastVencido =')
            after = replace_once(after, '        const mostrarSiguienteComentarioNuevo = function () {\n',
                '        const mostrarSiguienteComentarioNuevo = function () {\n            if (mostrarProximaAccion()) return true;\n')
            after = replace_once(after, 'colaAvisos.some(item => item.clave === clave);',
                'colaAvisos.some(item => item.clave === clave && !esTipoAnticipo(item.aviso.tipo));')
            after = replace_once(after, '            });\n            const enCola = colaAvisos.find(item => item.clave === clave);',
                '            });\n            const enCola = colaAvisos.find(item => item.clave === clave && !esTipoAnticipo(item.aviso.tipo));')
        elif file == helper:
            order = changes[file][0][0]
            after = replace_once(after, order['before'], order['after'])
            insert = "                'tipo_anticipo' => resolverTipoAvisoRecordatorio(\n                    (strtotime((string)($recordatorio['proxima_accion_at'] ?? '')) ?: 0) - time()\n                ),\n"
            after = replace_once(after, marker + "                'alerta_at' =>", marker + insert + "                'alerta_at' =>")
        elif file.endswith('ReminderReunionFollowupService.php'):
            after = replace_once(after, 'ORDER BY s.proxima_accion_at ASC',
                'ORDER BY CASE WHEN s.proxima_accion_at >= NOW() THEN 0 ELSE 1 END,\n                        s.proxima_accion_at ASC')
            tipo = '                $tipo = $this->resolverTipoAviso(\n                    $momento->getTimestamp() - $ahora->getTimestamp()\n                );\n'
            after = replace_once(after, tipo, '')
            after = replace_once(after, '                $recordatorios[] = [', tipo + '\n                $recordatorios[] = [')
            after = replace_once(after, "                    'fecha' => $fecha,\n                    'etiqueta' =>", "                    'fecha' => $fecha,\n                    'tipo_anticipo' => $tipo,\n                    'etiqueta' =>")
        else:
            for h in changes[file][0]:
                if after.count(h['before']) == 1:
                    after = after.replace(h['before'], h['after'], 1)
                else:
                    assert after.count(h['after']) == 1, file
        extra = hunks(before, after, 12 if file.endswith('AliadoModel.php') else 3)
        if extra not in changes[file]: changes[file].append(extra)
        candidates[file] = after
    # En Hostinger la campana ya está habilitada para cualquier sesión.
    topbar = "$mostrarCentroAvisos = !empty($_SESSION['usuario_id']);\n"
    changes['app/views/layout/topbar.php'].append([{'before': topbar, 'after': topbar}])
    dest = root / 'output/recordatorios_hostinger_20261120/hostinger-conciliado'
    for file, content in candidates.items():
        target = dest / file
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding='utf-8', newline='\n')
    return sources, candidates
