<?php
// Flujo real consulta -> filtros -> controlador, sobre SQLite en memoria.
// No carga configuración ni conecta con ninguna BD del CRM.
if (($argv[1] ?? '') !== '--fixture') {
    foreach ([5, 6] as $uid) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --fixture ' . $uid;
        exec($cmd, $salida, $code);
        $datos = json_decode(implode("\n", $salida), true, 512, JSON_THROW_ON_ERROR);
        $salida = [];
        if ($code || empty($datos['ok']) || empty($datos['recordatorios'])) throw new RuntimeException('No entrega para operador sin permisos de reuniones');
        $futuros = array_values(array_filter($datos['recordatorios'], static fn($r) => ($r['tipo_anticipo'] ?? '') === '10M'));
        if (count($futuros) !== 1 || $futuros[0]['id'] !== $uid || $futuros[0]['accion'] !== 'Volver a llamar') {
            throw new RuntimeException('No conserva próxima acción propia entre atrasos y agenda histórica');
        }
        if (count($datos['recordatorios']) <= 12) throw new RuntimeException('El límite todavía oculta el anticipo');
    }
    echo "OK: operadores sin permisos de reuniones reciben su acción vigente, con reunión histórica y más de cien vencidas, aislados por usuario.\n";
    exit;
}
class ResultadoAvisos {
    public $num_rows;
    private $filas;
    function __construct($filas) { $this->filas = $filas; $this->num_rows = count($filas); }
    function fetch_assoc() { return array_shift($this->filas) ?: null; }
    function fetch_all($mode = null) { return $this->filas; }
}
class SentenciaAvisos {
    private $pdo, $sql, $args = [], $resultado;
    function __construct($pdo, $sql) { $this->pdo = $pdo; $this->sql = str_replace(["DATE_ADD(NOW(), INTERVAL 24 HOUR)", "'\\n'"], ["datetime(NOW(), '+24 hours')", "'\n'"], $sql); }
    function bind_param($types, &...$args) { $this->args = &$args; }
    function execute() { $s = $this->pdo->prepare($this->sql); $s->execute($this->args); $this->resultado = new ResultadoAvisos($s->fetchAll(PDO::FETCH_ASSOC)); }
    function get_result() { return $this->resultado; }
}
class ConexionAvisos {
    public $pdo;
    function __construct() {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        $this->pdo->sqliteCreateFunction('SUBSTRING_INDEX', static function ($s, $delimiter, $n) {
            $parts = explode($delimiter, $s); return implode($delimiter, $n < 0 ? array_slice($parts, $n) : array_slice($parts, 0, $n));
        });
    }
    function prepare($sql) { return new SentenciaAvisos($this->pdo, $sql); }
    function real_escape_string($s) { return $s; }
    function query($sql) {
        if (preg_match("/SHOW TABLES LIKE '([a-z_]+)'/", $sql, $m)) {
            $s = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?"); $s->execute([$m[1]]);
            return new ResultadoAvisos($s->fetchAll(PDO::FETCH_ASSOC));
        }
        return new ResultadoAvisos($this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC));
    }
}
class Database { static $db; function connect() { return self::$db; } }
class SeguimientoTerritorialScope {
    static function analista($alias) { return $alias . '.analista_id = ?'; }
    static function reunionAnalista($alias) { return $alias . '.analista_id = ?'; }
}
class AgendaReunionService { function avisosHorario($uid) { return []; } }
class AgendaComentarioService {
    function notificaciones($uid) { return ['recordatorios' => [], 'avisos' => []]; }
    function registrarEntregados(...$args) {}
}
class ReminderReunionFollowupService { function obtener(...$args) { return ['recordatorios' => [], 'avisos' => []]; } }
class ReminderObservacionService { function obtener(...$args) { return ['recordatorios' => [], 'avisos' => []]; } }
class ReminderDirectLinkService { function aplicar($items, $uid) { return $items; } }
function tienePermiso($p) { return in_array($p, $_SESSION['permisos'] ?? [], true); }
function cargarCodigo($relative) {
    $text = file_get_contents((getenv('IMPE_REMINDER_TEST_SOURCE_ROOT') ?: dirname(__DIR__)) . '/' . $relative);
    $text = preg_replace('/^require_once .*;\R/m', '', $text);
    eval('?>' . $text);
}
cargarCodigo('app/helpers/ReminderHelper.php');
cargarCodigo('app/services/ReminderAgendaFilterService.php');
cargarCodigo('app/controllers/ReminderController.php');
$db = Database::$db = new ConexionAvisos();
$db->pdo->exec("CREATE TABLE seguimientos_vinculacion(id INTEGER,estado_id INTEGER,nombre_entidad TEXT,proxima_accion_at TEXT,analista_id INTEGER,activo INTEGER,estado_seguimiento TEXT);
CREATE TABLE interacciones_vinculacion(id INTEGER,seguimiento_id INTEGER,notas TEXT,fecha_inicio TEXT);
CREATE TABLE reuniones_vinculacion(seguimiento_id INTEGER,analista_id INTEGER,estado TEXT);
CREATE TABLE seguimientos_vinculacion_post_envio(seguimiento_id INTEGER,reunion_realizada_at TEXT);");
$insert = $db->pdo->prepare('INSERT INTO seguimientos_vinculacion VALUES(?,1,?,?,?,1,?)');
foreach ([5, 6] as $uid) {
    foreach (range(1, 120) as $n) {
        $id = $uid * 1000 + $n;
        $insert->execute([$id, 'Vencida', date('Y-m-d H:i:s', time() - $n * 3600), $uid, 'ACTIVO']);
        $db->pdo->exec("INSERT INTO interacciones_vinculacion VALUES($id,$id,'Próxima acción: Volver a llamar','2026-01-01')");
    }
    $insert->execute([$uid, 'Vigente', date('Y-m-d H:i:s', time() + 500), $uid, 'ACTIVO']);
    $db->pdo->exec("INSERT INTO interacciones_vinculacion VALUES($uid,$uid,'Próxima acción: Volver a llamar','2026-01-02'); INSERT INTO reuniones_vinculacion VALUES($uid,$uid,'REALIZADA')");
}
$uid = (int)$argv[2];
$_SESSION = ['usuario_id' => $uid, 'rol_id' => 4, 'permisos' => ['seguimientos_vinculacion.operar_propios']];
$controller = (new ReflectionClass(ReminderController::class))->newInstanceWithoutConstructor();
$empty = new class { function obtener(...$args) { return ['recordatorios' => [], 'avisos' => []]; } };
$link = new class { function aplicar($items, $uid) { return $items; } };
foreach (['reminderAgendaFilterService' => new ReminderAgendaFilterService(), 'reminderReunionFollowupService' => $empty, 'reminderObservacionService' => $empty, 'reminderDirectLinkService' => $link] as $name => $service) {
    $p = new ReflectionProperty(ReminderController::class, $name); $p->setValue($controller, $service);
}
$controller->pendientes();
