"""Prueba preflight, aplicación reversible, idempotencia y rechazo de conflictos."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PUBLISHER = ROOT / 'tools/publicar_fix_oficio_cdmx_fecha.py'


def ejecutar(site, backup, *args, expected_ok=True):
    proc = subprocess.run(
        [sys.executable, str(PUBLISHER), '--site', str(site),
         '--backup-root', str(backup), *args],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE
    )
    msg = (proc.stdout + proc.stderr).decode('utf-8', 'replace')
    assert (proc.returncode == 0) is expected_ok, msg
    return msg


with tempfile.TemporaryDirectory(prefix='impe-oficio-cdmx-') as d:
    root = Path(d)
    site = root / 'public_html'
    backups = root / 'private_backups'
    (site / 'app/services').mkdir(parents=True)
    (site / 'storage/templates').mkdir(parents=True)
    (site / 'index.php').write_text('<?php // prueba', encoding='utf-8')

    preview = site / 'app/services/OficioPreviewService.php'
    preview.write_text("""<?php
class OficioPreviewService {
    private function formatearFechaOficio($fecha) {
        try {
            $fechaObjeto = trim((string)$fecha) !== ''
                ? new DateTime((string)$fecha)
                : new DateTime();
        } catch (Exception $error) {
            $fechaObjeto = new DateTime();
        }
        return $fechaObjeto->format('Y-m-d');
    }
    private function lugarFecha($fecha) {
        return 'Ciudad de México, a ' . $fecha;
    }
}
""", encoding='utf-8')

    converter = site / 'app/services/OficioDocxPdfService.php'
    converter.write_text("""<?php
class OficioDocxPdfService {
    private function generarPdf($rutaDocx, $vista) {
        return $this->rellenarDocx($rutaDocx, $this->construirReemplazos($vista));
    }
    private function construirReemplazos(array $vista)
    {
        $reemplazos = [
            '{{FOLIO}}' => trim((string)($vista['folio'] ?? '')),
        ];
        return $reemplazos;
    }
}
""", encoding='utf-8')

    template = site / 'storage/templates/oficio_general_redmex.docx'
    with zipfile.ZipFile(template, 'w') as archivo:
        archivo.writestr(
            'word/document.xml',
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            '<w:body><w:p><w:r><w:t>Cuernavaca, Morelos, a {{FECHA}}</w:t>'
            '</w:r></w:p></w:body></w:document>'
        )

    def snapshot():
        return {str(p.relative_to(site)): p.read_bytes()
                for p in site.rglob('*') if p.is_file()}

    originals = snapshot()
    assert 'CHECK OK' in ejecutar(site, backups, '--check')
    assert not backups.exists(), 'check creó carpetas de respaldo'
    assert snapshot() == originals
    assert 'APLICADO' in ejecutar(site, backups, '--apply')
    applied = snapshot()
    assert applied['app/services/OficioPreviewService.php'] != originals['app/services/OficioPreviewService.php']
    assert applied['app/services/OficioDocxPdfService.php'] != originals['app/services/OficioDocxPdfService.php']
    assert applied['storage/templates/oficio_general_redmex.docx'] == originals['storage/templates/oficio_general_redmex.docx']
    assert 'YA INSTALADO' in ejecutar(site, backups, '--check')
    assert 'YA INSTALADO' in ejecutar(site, backups, '--apply')

    folders = [item for item in backups.iterdir() if item.is_dir()]
    assert len(folders) == 1
    data = json.loads((folders[0] / 'respaldo.json').read_text(encoding='utf-8'))
    assert {item['path'] for item in data['files']} == {
        'app/services/OficioPreviewService.php', 'app/services/OficioDocxPdfService.php'
    }

    converter.write_bytes(converter.read_bytes() + b'\n// posterior')
    assert 'CONFLICTO' in ejecutar(site, backups, '--restore', str(folders[0]), expected_ok=False)
    converter.write_bytes(applied['app/services/OficioDocxPdfService.php'])
    assert 'RESTAURADO' in ejecutar(site, backups, '--restore', str(folders[0]))
    assert snapshot() == originals

    preview.write_bytes(preview.read_bytes().replace(b'new DateTime((string)$fecha)', b'new DateTime("broken")'))
    assert 'CONFLICTO' in ejecutar(site, backups, '--check', expected_ok=False)
    assert 'CONFLICTO' in ejecutar(site, backups, '--apply', expected_ok=False)

print('PASS publicador CDMX: check, apply, idempotencia, conflicto y restore')
