"""Verifica la ruta de edición real, método POST, CSRF y sesión sin usar la BD."""
import json
import subprocess
import tempfile
from pathlib import Path

HERE = Path(__file__).resolve().parent
with tempfile.TemporaryDirectory(prefix='agenda-editar-controller-') as folder:
    root = Path(folder)
    (root / 'app/controllers').mkdir(parents=True)
    (root / 'app/services').mkdir()
    (root / 'app/controllers/AgendaComentarioController.php').write_bytes(
        (HERE / 'files/app/controllers/AgendaComentarioController.php').read_bytes())
    (root / 'app/services/AgendaComentarioService.php').write_text('''<?php
class AgendaComentarioService {
 static function esUsuarioActual($u) { return $u>0; }
 static function puedeAcceder($u) { return $u>0 && !empty($_SESSION['agenda']); }
 function editar($usuario,$id,$datos) { return ['ok'=>true,'usuario'=>$usuario,'id'=>$id,'datos'=>$datos]; }
}
''', encoding='utf-8')
    (root / 'request.php').write_text('''<?php
$case=json_decode($argv[1],true);
$_SESSION=$case['session'];$_SERVER=$case['server'];$_POST=$case['post'];
register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());});
require __DIR__.'/app/controllers/AgendaComentarioController.php';
(new AgendaComentarioController())->editar();
''', encoding='utf-8')

    def request(session=None, method='POST', token='seguro', comment_id='12'):
        case = dict(session=session if session is not None else dict(usuario_id=1, agenda=True, csrf_token='seguro'),
                    server=dict(REQUEST_METHOD=method, HTTP_X_CSRF_TOKEN=token),
                    post=dict(id=comment_id, comentario='Texto modificado', destinatarios_ids=['2', '3']))
        result = subprocess.run(['php', str(root / 'request.php'), json.dumps(case)], capture_output=True)
        assert result.returncode == 0, result.stderr
        return json.loads(result.stdout), int(result.stderr)

    data, status = request()
    assert status == 200 and data['id'] == 12 and data['usuario'] == 1
    assert data['datos']['destinatarios_ids'] == ['2', '3']
    assert request(method='GET')[1] == 405
    assert request(token='incorrecto')[1] == 419
    assert request(token='')[1] == 419
    assert request(session={})[1] == 403
    assert request(session=dict(usuario_id=2, agenda=False, csrf_token='seguro'))[1] == 403
    assert request(comment_id='12x')[0]['id'] == 0
print('OK: ruta editar, POST, CSRF, sesión e ID validados sin conexión a BD.')
