"""Ejercita cURL real contra un servidor local ficticio, sin INEGI ni base de datos."""
from collections import Counter
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json, os, subprocess, threading, time

counts = Counter()
lock = threading.Lock()

class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_GET(self):
        with lock:
            counts[self.path] += 1
            count = counts[self.path]
        code, body, retry_after = 200, [{"Id": "1", "Nombre": "Ficticio"}], None
        if self.path == '/retry' and count == 1:
            code, body = 503, {}
        elif self.path == '/auth':
            code, body = 403, {}
        elif self.path == '/token':
            body = {"message": "Token no autorizado"}
        elif self.path == '/json' and count == 1:
            body = '<html>No disponible</html>'
        elif self.path == '/empty':
            body = {"message": "No se encontraron registros"}
        elif self.path == '/end':
            body = '"No hay resultados."'
        elif self.path == '/limit':
            code, body, retry_after = 429, {}, '10'
        elif self.path.startswith('/slow'):
            time.sleep(0.8)
        raw = body.encode() if isinstance(body, str) else json.dumps(body).encode()
        try:
            self.send_response(code)
            self.send_header('Content-Type', 'text/plain; charset=utf-8' if self.path == '/end' else 'application/json')
            self.send_header('Content-Length', str(len(raw)))
            if retry_after:
                self.send_header('Retry-After', retry_after)
            self.end_headers()
            self.wfile.write(raw)
        except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError):
            pass

php = r'''
require 'app/services/DenueCandidatosHttpClient.php';
$base = getenv('DENUE_FIXTURE');
$dir = sys_get_temp_dir().'/denue-http-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
$ahora = 1000;
$reloj = function() use (&$ahora) { return $ahora; };
try {
    $client = new DenueCandidatosHttpClient(2000,3500,new DenueCircuitBreaker($dir.'/retry.json',$reloj));
    $req = fn($p) => ['url'=>$base.$p, 'perfil'=>['ruta'=>$p]];
    $first = $client->consultar([$req('/retry')],1);
    $blocked = (new DenueCandidatosHttpClient(2000,3500,new DenueCircuitBreaker($dir.'/retry.json',$reloj)))->consultar([$req('/retry')],1);
    $ahora += 120;
    $recovered = $client->consultar([$req('/retry'),$req('/empty'),$req('/end')],4);
    $rows = [];
    foreach (['/auth','/token','/json','/limit'] as $i=>$p) {
        $c = new DenueCandidatosHttpClient(2000,3500,new DenueCircuitBreaker($dir.'/case'.$i.'.json',$reloj));
        $rows[] = $c->consultar([$req($p)],1)[0];
    }
    $slow = array_map(fn($i) => $req('/slow'.$i), range(1,18));
    $started = microtime(true);
    $slowRows = (new DenueCandidatosHttpClient(200,600,new DenueCircuitBreaker($dir.'/slow.json',$reloj)))->consultar($slow);
    $tls = DenueTlsPolicy::opciones('https://www.inegi.org.mx/app/api/denue/v1/consulta/');
    $other = DenueTlsPolicy::opciones('https://otro.invalid/');
    echo json_encode(['first'=>$first,'blocked'=>$blocked,'recovered'=>$recovered,
     'rows'=>$rows,'slow'=>$slowRows,'elapsed'=>microtime(true)-$started,
     'tls_peer'=>$tls[CURLOPT_SSL_VERIFYPEER], 'tls_host'=>$tls[CURLOPT_SSL_VERIFYHOST],
     'tls_bundle'=>is_readable($tls[CURLOPT_CAINFO]),'other_bundle'=>isset($other[CURLOPT_CAINFO]),
     'ssl_error'=>DenueCandidatosHttpClient::interpretar('',0,60),
     'invalid_json'=>DenueCandidatosHttpClient::interpretar('null',200),
     'empty_object'=>DenueCandidatosHttpClient::interpretar('{}',200),
     'empty_body'=>DenueCandidatosHttpClient::interpretar('',200),
     'unknown_string'=>DenueCandidatosHttpClient::interpretar('"Error desconocido"',200)]);
} finally {
    foreach (glob($dir.'/*') as $f) unlink($f);
    rmdir($dir);
}
'''

server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
server.daemon_threads = True
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    env = os.environ.copy()
    env['DENUE_FIXTURE'] = f'http://127.0.0.1:{server.server_port}'
    run = subprocess.run([os.environ.get('PHP_BIN', 'php'), '-r', php],
                         cwd=Path(__file__).resolve().parents[1], env=env,
                         capture_output=True, text=True, timeout=15)
    assert run.returncode == 0, run.stderr
    data = json.loads(run.stdout)
    rows = {x['perfil']['ruta']: x for x in data['rows']}
    assert not data['first'][0]['ok'] and data['first'][0]['codigo'] == 'HTTP_503'
    assert data['blocked'][0]['codigo'] == 'CIRCUITO', 'No emitir una nueva petición durante la pausa'
    assert data['recovered'][0]['ok'] and counts['/retry'] == 2, 'Sonda única tras pausa, sin reintento inmediato'
    assert data['recovered'][1]['ok'] and data['recovered'][1]['datos'] == [], 'Vacío válido'
    assert data['recovered'][2]['ok'] and data['recovered'][2]['datos'] == [], 'Fin real de DENUE'
    assert not rows['/json']['ok'] and counts['/json'] == 1, 'No reintentar HTML inesperado'
    assert not rows['/auth']['ok'] and counts['/auth'] == 1, 'No reintentar 403'
    assert not rows['/token']['ok'] and counts['/token'] == 1, 'HTTP 200 puede contener rechazo'
    assert not rows['/limit']['ok'] and counts['/limit'] == 1, 'Respetar Retry-After'
    assert sum(v for k,v in counts.items() if k.startswith('/slow')) <= 4, 'Cancelar el resto del lote al fallar'
    assert len(data['slow']) == 18 and all(not x['ok'] for x in data['slow']), 'Responder cada trabajo aun vencido'
    assert data['elapsed'] < 1.5, 'Acotar la suma de timeouts, no multiplicarla por 18'
    assert data['tls_peer'] is True and data['tls_host'] == 2 and data['tls_bundle'], 'Verificar HTTPS y cadena'
    assert not data['other_bundle'], 'No alterar confianza de otros servicios'
    assert data['ssl_error']['codigo'] == 'CERTIFICADO' and not data['ssl_error']['reintentable']
    assert not data['invalid_json']['ok']
    assert not data['empty_object']['ok'], 'Objeto vacío no es un catálogo confirmado'
    assert not data['empty_body']['ok'], 'Cuerpo vacío no confirma fin de resultados'
    assert not data['unknown_string']['ok'], 'Cadena desconocida no confirma fin de resultados'
    print('DENUE HTTP: circuito, sonda, cancelación de lote, JSON, autorización, vacío, TLS OK')
finally:
    server.shutdown()
    server.server_close()
