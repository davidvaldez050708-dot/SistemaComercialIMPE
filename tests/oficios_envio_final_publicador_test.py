"""Pruebas sin envío SMTP ni BD de publicación segura del oficio final."""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'tools/publicar_oficio_envio_final_hostinger.py'
TARGET = ROOT / 'app/services/OficioEnvioService.php'
BEFORE = 'ce6f1a7673a76e4894dca62289838a015dcc2e2c'

source = subprocess.run(
    ['git', 'show', BEFORE + ':app/services/OficioEnvioService.php'],
    cwd=str(ROOT), stdout=subprocess.PIPE, stderr=subprocess.PIPE
)
assert source.returncode == 0, source.stderr.decode('utf-8', 'replace')
original = source.stdout

with tempfile.TemporaryDirectory(prefix='impe-oficio-envio-final-') as temp:
    root = Path(temp)
    site = root / 'public_html'
    path = site / 'app/services/OficioEnvioService.php'
    backup = root / 'backups'
    base = root / 'base.php'
    base.write_bytes(original)
    site.mkdir()
    (site / 'index.php').write_text('<?php // test',encoding='utf-8')
    path.parent.mkdir(parents=True)
    path.write_bytes(original)

    def run(*mode, ok=True):
        proc = subprocess.run(
            [sys.executable, str(SCRIPT), '--site', str(site),
             '--base', str(base), '--target', str(TARGET),
             '--backup-root', str(backup)] + list(mode),
            stdout=subprocess.PIPE, stderr=subprocess.PIPE
        )
        out = (proc.stdout + proc.stderr).decode('utf-8','replace')
        assert (proc.returncode == 0) == ok, out
        return out

    assert 'CHECK OK' in run('--check')
    assert not backup.exists(), 'CHECK must not write backup'
    assert path.read_bytes() == original
    assert 'APLICADO' in run('--apply')
    assert path.read_bytes() == TARGET.read_bytes()
    assert 'YA INSTALADO' in run('--apply')
    folders = [p for p in backup.iterdir() if p.is_dir()]
    assert len(folders) == 1
    first_backup = folders[0]
    assert 'RESTAURADO' in run('--restore',str(first_backup))
    assert path.read_bytes() == original

    # La producción puede conservar personalizaciones ajenas a la reparación.
    custom = original.replace(
        b'    private const LOCK_FOLIO_GLOBAL =',
        b'    // Personalizacion local fuera del flujo de envio final.\n'
        b'    private const LOCK_FOLIO_GLOBAL =',1
    )
    path.write_bytes(custom)
    assert 'COMPATIBLE PERSONALIZADO' in run('--check')
    assert path.read_bytes() == custom
    assert 'APLICADO' in run('--apply')
    applied = path.read_bytes()
    assert b'Personalizacion local' in applied
    assert b'esOficioTerminadoAdjuntado' in applied
    assert b'pdfTerminadoDisponible' in applied
    assert 'YA INSTALADO' in run('--check')
    folders = [p for p in backup.iterdir() if p.is_dir()]
    assert len(folders) == 2
    latest = [p for p in folders if p != first_backup]
    assert len(latest) == 1
    assert 'RESTAURADO' in run('--restore',str(latest[0]))
    assert path.read_bytes() == custom

    # No reemplazar a ciegas la secuencia de envío personalizada.
    incompatible = original.replace(
        b'        $ajuste = $this->sincronizarFolioConFechaActual(',
        b'        $ajuste = $this->nuevoSincronizadorLocal(',
        1
    )
    path.write_bytes(incompatible)
    assert 'CONFLICTO' in run('--check',ok=False)
    assert 'CONFLICTO' in run('--apply',ok=False)
    assert path.read_bytes() == incompatible

print('PASS oficio final: PDF adjunto, CHECK, APPLY, idempotencia, restore y conflicto')
