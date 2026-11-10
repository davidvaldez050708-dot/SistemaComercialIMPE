#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Reconstruye, SIN tocar producción, las cinco personalizaciones V4 que
el usuario verificó por SHA1 Git blob en Hostinger el 10 de noviembre de 2026.
Las guarda exclusivamente en memoria para las pruebas del publicador.
"""
import hashlib
import subprocess
from pathlib import Path

BASE="5304a38208cd72c9b8971f530eda008d41de37e3"
OBSERVED={
    "app/services/TelefoniaResultadoVentasCatalogo.php":"19b295219d1d709b38440b292565c19da84a34da",
    "public/javascript/telefonia_ventas_resultados.js":"ce80806d0af17d3c660781e9f10148d6d16a1b56",
    "app/services/TelefoniaControlService.php":"849b971b360df0afe001ccf543def214ba224f63",
    "app/views/telefonia/index.php":"1c080d3daf37d602b186959a5bd5f206d6898505",
    "app/services/TelefoniaResultadoVentasService.php":"83c072703cc7023bd6f0b313cef4ee4e2f0e7d22",
    "app/views/telefonia/resultado_ventas_form.php":"483441e232c69d5ee62019994162820838a9b717"
}
ROOT=Path(__file__).resolve().parents[1]

def sha_blob(raw):
    return hashlib.sha1(("blob %d\0"%len(raw)).encode("ascii")+raw).hexdigest()

def replacement(src, old, new):
    occurrences=src.count(old)
    if occurrences!=1:
        raise AssertionError("Marcador de conciliación no único ({}): {!r}".format(occurrences,old[:85]))
    return src.replace(old,new,1)

def production(rel):
    p=subprocess.run(["git","show",BASE+":"+rel],cwd=str(ROOT),
                     stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if p.returncode:
        raise RuntimeError("No se localizó referencia V4 para "+rel)
    s=p.stdout.decode("utf-8")
    if rel=="app/services/TelefoniaResultadoVentasCatalogo.php":
        pass  # La versión 4 del catálogo sí coincide literalmente con Hostinger.
    elif rel=="public/javascript/telefonia_ventas_resultados.js":
        old="   form.elements.pbx_call_id.value=ref;form.elements.extension.value=ownExt;\n"
        new=old+(
            "    // IMPE_EMBUDO_ASIGNACION_RESULTADO_V1: contexto opt-in del prospecto.\n"
            "    const asignacionEmbudo = String(root.dataset.salesAssignmentId || '');\n"
            "    form.elements.prospecto_asignacion_id.value =\n"
            "      /^[1-9][0-9]*$/.test(asignacionEmbudo) ? asignacionEmbudo : '';\n"
            "\n"
        )
        s=replacement(s,old,new)
    elif rel=="app/services/TelefoniaControlService.php":
        s=replacement(s,
"""        (new ZadarmaWebhookEventStoreService())->asegurarEstructura();
        (new TelefoniaExtensionService())->asegurarEstructura();
        (new TelefoniaMarcacionesVentasService())->asegurarEstructura();
        (new TelefoniaResultadoVentasService())->asegurarEstructura();""",
"""        (new TelefoniaExtensionService($this->db))->asegurarEstructura();
        new TelefoniaMarcacionesVentasService($this->db);
        new TelefoniaResultadoVentasService($this->db);""")
    elif rel=="app/views/telefonia/index.php":
        edits=[
            (
                '<div class="telephony-admin telephony-control-center" data-telephony-control>',
                '<link rel="stylesheet" href="<?= $esc(BASE_URL . \'public/css/telefonia_control_filtros_v2.css?v=20261027\') ?>">\n'
                '<div class="telephony-admin telephony-control-center" data-telephony-control>\n'
                '    <?php if (!empty($puedeAdministrarExtensiones)): ?>'
            ),
            (
                '    </nav>\n    <section class="dashboard-panel telephony-control-filter-panel">',
                '    </nav>\n    <?php endif; ?>\n    <section class="dashboard-panel telephony-control-filter-panel">'
            ),
            (
                '''            <a href="<?= $esc($baseTel . 'extensiones') ?>" class="btn btn-system-light telephony-control-config-link">
                <i class="bi bi-sliders" aria-hidden="true"></i> Administrar extensiones
            </a>''',
                '''            <?php if (!empty($puedeAdministrarExtensiones)): ?>
            <a href="<?= $esc($baseTel . 'extensiones') ?>" class="btn btn-system-light telephony-control-config-link">
                <i class="bi bi-sliders" aria-hidden="true"></i> Administrar extensiones
            </a>
            <?php endif; ?>'''
            ),
            (
                '<form action="<?= $esc(BASE_URL . \'index.php\') ?>" method="get" class="telephony-control-filters">',
                '<form action="<?= $esc(BASE_URL . \'index.php\') ?>" method="get"\n'
                '            class="telephony-control-filters" data-control-auto-filters>'
            ),
            (
                '''            <div class="telephony-control-filter-actions">
                <button type="submit" class="btn btn-system-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Aplicar</button>
                <a class="btn btn-system-light" href="<?= $esc($baseTel . 'index') ?>">Restablecer</a>
            </div>
        </form>''',
                '''            <div class="telephony-control-filter-actions">
                <a class="filter-clear-link telephony-control-clear <?= (
                    !empty($_GET['desde']) || !empty($_GET['hasta']) ||
                    !empty($_GET['rol']) || !empty($_GET['usuario_id']) ||
                    !empty($_GET['direccion']) || !empty($_GET['orden'])
                ) ? '' : 'd-none' ?>"
                    href="<?= $esc($baseTel . 'index') ?>" data-control-clear>
                    <i class="bi bi-x-circle" aria-hidden="true"></i> Limpiar filtros
                </a>
                <span class="telephony-control-filter-loading" data-control-filter-loading
                    role="status" aria-live="polite" hidden>
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    Actualizando resultados…
                </span>
            </div>
            <p class="telephony-control-filter-message" role="alert"
                data-control-filter-message hidden></p>
        </form>'''
            ),
            ("'help'=>'Identificadores distintos de llamadas en la PBX'",
             "'help'=>'Comunicaciones distintas registradas por el servicio telefónico'"),
            ("['label'=>'Conectadas (PBX)',","['label'=>'Conexión confirmada',"),
            ("<th>Estado PBX</th>","<th>Estado de la llamada</th>"),
            ("<td><?= $call['conectada'] ? 'Conectada' : 'Sin conexión confirmada' ?></td>",
             "<td><?= $esc((string)($call['estado_pbx'] ?? ($call['conectada'] ? 'Conectada' : 'Sin conexión confirmada'))) ?></td>"),
            (
                """                                    : ($call['proceso'] === 'Recepción'
                                        ? ($call['conectada'] ? 'Atendida' : 'Sin respuesta confirmada')
                                        : 'Sin clasificar'));""",
                """                                    : ($call['proceso'] === 'Ventas'
                                        ? (string)($call['resultado_estado'] ?? 'Sin clasificar')
                                        : ($call['proceso'] === 'Recepción'
                                            ? ($call['conectada'] ? 'Atendida' : 'Sin respuesta confirmada')
                                            : 'Sin clasificar')));"""
            )
        ]
        for old,new in edits:
            s=replacement(s,old,new)
    elif rel=="app/services/TelefoniaResultadoVentasService.php":
        marker="        return array_merge($detalle??[],['pbx_call_id'=>$pbxCallId,'extension'=>$extension,'resultado'=>$resultado,"
        insert="""        // IMPE_EMBUDO_PBX_POSTCOMMIT_V1
        // Telefonía ya registró el resultado definitivo. Si la vinculación
        // comercial falla, no se revierte ni se altera ese registro PBX.
        $idComercial = $datos['prospecto_asignacion_id'] ?? '';
        if (is_string($idComercial) &&
            preg_match('/^[1-9][0-9]*$/D', $idComercial)) {
            try {
                require_once __DIR__.'/EmbudoComercialResultadoBridge.php';
                $this->db->begin_transaction();
                (new EmbudoComercialResultadoBridge($this->db))->aplicar(
                    $usuarioId, (int)$idComercial, $call, $resultado, $next ?? null
                );
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollback();
                error_log('[embudo_resultado_pbx] '.get_class($e).' codigo='.(int)$e->getCode());
            }
        }

"""
        s=replacement(s,marker,insert+marker)
    elif rel=="app/views/telefonia/resultado_ventas_form.php":
        s=replacement(s,
            '<input type="hidden" name="pbx_call_id"><input type="hidden" name="extension">',
            '<input type="hidden" name="pbx_call_id"><input type="hidden" name="extension"><input type="hidden" name="prospecto_asignacion_id">')
    else:
        raise ValueError("Ruta no autorizada: "+rel)
    raw=s.encode("utf-8")
    actual=sha_blob(raw)
    if actual!=OBSERVED[rel]:
        raise AssertionError("Reconstrucción no idéntica a Hostinger: "+rel+" "+actual)
    return raw

if __name__=="__main__":
    for rel in OBSERVED:
        raw=production(rel)
        print("RECONSTRUIDO EXACTO:",rel,sha_blob(raw))
    print("CHECK OK: las seis versiones actuales son equivalentes byte por byte a los hashes de Hostinger.")
