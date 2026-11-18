"""Ejecuta la integración sobre una copia desechable con las políticas/vistas reales de Hostinger."""
from pathlib import Path
import importlib.util
import io
import os
import subprocess
import tempfile
import zipfile

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('wa_host_fixture',ROOT/'tests/fixtures/whatsapp_hostinger_20261118.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture);bases=fixture.reconstruir()
adapted=ROOT/'tools/whatsapp_contextual_hostinger/after'
# La reversión textual prueba que no se eliminó ninguna personalización de las vistas.
rel='app/views/aliados/estado.php'
after=(adapted/rel).read_text(encoding='utf-8')
extra='\n                                                                            data-whatsapp-contexto="aliado" data-whatsapp-id="<?= (int)$aliado[\'seguimiento_id\'] ?>"'
assert after.replace(extra,'').removesuffix("\n<?php require __DIR__.'/../whatsapp/acceso_contextual.php'; ?>\n").encode()==bases[rel]
rel='app/views/embudo_comercial/operativo_v3.php'
after=(adapted/rel).read_text(encoding='utf-8')
before=after.removesuffix("\n<?php require __DIR__.'/../whatsapp/acceso_contextual.php'; ?>\n")
before=before.replace("$reservarWhatsapp=tienePermiso('whatsapp.ver') && !$supervisor && !$legacy && $st!=='NO_CONTACTAR' &&", "$reservarWhatsapp=!$supervisor && !$legacy && $st!=='NO_CONTACTAR' && ($puedeTelefono || $puedeMover) &&")
before=before.replace('data-whatsapp-contexto="prospecto" data-whatsapp-id="<?= (int)$p[\'asignacion_id\'] ?>"\n                    title="Abrir conversación de WhatsApp" aria-label="Abrir conversación de WhatsApp"','data-v3-whatsapp-reservado disabled\n                    title="WhatsApp · Próximamente" aria-label="WhatsApp · Próximamente"')
assert before.encode()==bases[rel]
assert 'notificacion_destino.php' not in after

with tempfile.TemporaryDirectory(prefix='impe-wa-host-runtime-') as temp:
    project=Path(temp)
    data=subprocess.check_output(['git','archive','--format=zip','1092d38265189d5e55e14e288cdf278f44958ed3'],cwd=ROOT)
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        for name in archive.namelist():
            target=(project/name).resolve()
            assert target==project or project in target.parents
        archive.extractall(project)
    for rel in ['app/helpers/PermissionHelper.php','app/helpers/AdminRolePolicy.php','app/helpers/SupervisionRolePolicy.php','index.php']:
        (project/rel).write_bytes(bases[rel])
    for rel in ['app/views/embudo_comercial/operativo_v3.php','app/views/aliados/estado.php']:
        (project/rel).write_bytes((adapted/rel).read_bytes())
    for rel in ['tests/whatsapp_contextual_db.php','tests/whatsapp_contextual_http.py']:
        (project/rel).write_bytes((ROOT/rel).read_bytes())
    env=os.environ.copy()
    for key in ['IMPE_WHATSAPP_PUBLICACION_TEST','IMPE_WA_HOST_COMPAT_TEST','IMPE_WA_PERMISSION_FIXTURE']:
        env.pop(key,None)
    env.update(IMPE_WHATSAPP_CONTEXT_TEST='YES',IMPE_WHATSAPP_HTTP_TEST='YES',IMPE_WA_RENDER_DIR=str(project/'.render/wa'),IMPE_SUPERVISION_TEST='YES',IMPE_SUPERVISION_RENDER_DIR=str(project/'.render/supervision'))
    for command in [
        ['php','tests/whatsapp_contextual_db.php'],
        ['php','tests/supervision_comercial_v3_db.php'],
        ['node','tests/whatsapp_contextual_browser.cjs'],
        ['node','tests/acciones_tarjetas_v3_browser.cjs'],
    ]:
        subprocess.run(command,cwd=project,env=env,check=True)
print('CHECK_OK ejecución Hostinger: políticas exactas, chat/HTTP, cartera/territorio, móvil/escritorio y regresión de cinco etapas; vistas preservadas salvo el acceso WhatsApp.')
