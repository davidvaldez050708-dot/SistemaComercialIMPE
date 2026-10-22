<?php
// Integración del servicio con doble de BD en memoria. No usa MySQL ni envía correos.
require dirname(__DIR__) . '/app/services/CorreoMarketingService.php';
function expectMarketing($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
class MarketingRows {
    public $num_rows; private $rows;
    public function __construct($rows) { $this->rows = $rows; $this->num_rows = count($rows); }
    public function fetch_assoc() { return array_shift($this->rows); }
}
class MarketingDb {
    public $rows = [], $insert_id = 0, $sentWrites = 0;
    public function query($sql) { expectMarketing(str_starts_with($sql, 'SHOW TABLES'), 'consulta dirigida'); return new MarketingRows([[]]); }
    public function prepare($sql) { return new MarketingStmt($this, $sql); }
}
class MarketingStmt {
    private $db, $sql, $args = [], $rows = [];
    public function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
    public function bind_param($types, &...$args) { $this->args = &$args; }
    public function execute() {
        $a = $this->args; $s = $this->sql;
        if (str_contains($s, 'correos_marketing_adjuntos')) { $this->rows = []; return true; }
        if (str_starts_with($s, 'INSERT INTO')) {
            $id = ++$this->db->insert_id;
            $this->db->rows[$id] = array_combine(['usuario_id','destinatario','destinatario_nombre','asunto','cuerpo','tipo','estado','adjuntos_count','adjuntos_nombres'], $a) + ['id' => $id];
        } elseif (str_starts_with($s, 'SELECT')) {
            $row = $this->db->rows[$a[0]] ?? null;
            $this->rows = $row && $row['usuario_id'] === $a[1] ? [$row] : [];
        } elseif (str_contains($s, 'SET destinatario')) {
            $id = $a[8]; $row = &$this->db->rows[$id];
            expectMarketing($row['usuario_id'] === $a[9] && $row['estado'] === 'BORRADOR', 'edición sólo borrador propio');
            foreach (['destinatario','destinatario_nombre','asunto','cuerpo','tipo','estado','adjuntos_count','adjuntos_nombres'] as $i => $field) { $row[$field] = $a[$i]; }
        } elseif (str_contains($s, 'fecha_envio = NOW()')) {
            $id = $a[count($a)-1]; $this->db->rows[$id]['estado'] = $a[0]; $this->db->rows[$id]['proveedor'] = $a[1]; $this->db->sentWrites++;
            if(str_contains($s,'cuerpo = CONCAT(cuerpo, ?)')) { $this->db->rows[$id]['cuerpo'].=$a[3]; }
        } elseif (str_contains($s, "SET estado = 'BORRADOR'")) {
            expectMarketing($this->db->rows[$a[0]]['usuario_id'] === $a[1], 'restauración borrador propio');
            $this->db->rows[$a[0]]['estado'] = 'BORRADOR';
        } elseif (str_contains($s, 'error_envio = ?')) {
            $this->db->rows[$a[2]]['estado'] = $a[0]; $this->db->rows[$a[2]]['error_envio'] = $a[1];
        } else { throw new RuntimeException('SQL no previsto'); }
        return true;
    }
    public function get_result() { return new MarketingRows($this->rows); }
}
class MarketingSender extends CorreoSalidaInstitucionalService {
    public $calls = 0, $reject = false, $last;
    public function __construct() {}
    public function validarRemitente(string $from, int $user): array { return ['ok'=>true]; }
    public function prepararSalida(string $from, int $user): array { return ['ok'=>true]; }
    public function enviar($data) {
        $this->calls++; $this->last = $data;
        return $this->reject ? ['ok' => false, 'estado_entrega' => 'RECHAZADO', 'mensaje' => 'Rechazo de prueba']
            : ['ok' => true, 'estado_entrega' => 'ACEPTADO', 'proveedor' => 'SMTP', 'entrega_confirmada' => false];
    }
}
$dir = sys_get_temp_dir() . '/impe-marketing-' . bin2hex(random_bytes(6)); mkdir($dir, 0700, true);
putenv('IMPE_MAIL_STATE_DIR=' . $dir);
try {
    $db = new MarketingDb(); $sender = new MarketingSender();
    $service = new CorreoMarketingService($db, $sender, static function ($id) { return ['correo' => 'prueba@gmail.com', 'nombre' => 'Prueba']; });
    $data = ['destinatario' => 'prueba@educacion.test', 'asunto' => 'Contenido ficticio', 'cuerpo' => "Texto íntegro\nSegunda línea", 'convocatoria_id' => 5];
    $saved = $service->guardarBorrador(7, $data);
    expectMarketing($saved['ok'] && $saved['correo_id'] === 1, 'guardar borrador');
    $draft = $service->obtener(7, 1);
    expectMarketing($draft['estado_codigo'] === 'borrador' && $draft['cuerpo'] === $data['cuerpo'] && $draft['asunto'] === $data['asunto'], 'recuperar contenido de borrador');
    expectMarketing($service->obtener(8, 1) === null, 'borrador ajeno inaccesible');
    $data['correo_id'] = 1;
    $sender->reject = true;
    expectMarketing(!$service->enviar(7, $data)['ok'] && $db->rows[1]['estado'] === 'BORRADOR' && $db->sentWrites === 0, 'rechazo conserva borrador');
    $sender->reject = false;
    $accepted = $service->enviar(7, $data);
    expectMarketing($accepted['ok'] && !$accepted['entrega_confirmada'] && $db->rows[1]['estado'] === 'ENVIADO', 'historial sólo tras aceptación');
    expectMarketing($sender->last['cuerpo'] === $data['cuerpo'] && $sender->last['usuario_id'] === 7, 'contenido e identidad');
    $repeat = $service->enviar(7, $data);
    expectMarketing($repeat === $accepted && $sender->calls === 2 && $db->sentWrites === 1 && count($db->rows) === 1, 'misma solicitud sin duplicar envío ni registro');
    $data['cuerpo'] = 'Texto cambiado';
    expectMarketing(!$service->enviar(7, $data)['ok'] && $sender->calls === 2, 'no reutilizar solicitud cambiada');
    echo "OK: borrador e historial en memoria, propiedad, contenido, rechazo y reintento idempotente. Sin BD ni envíos reales.\n";
} finally {
    foreach (glob($dir . '/*') as $p) { unlink($p); } rmdir($dir);
}
