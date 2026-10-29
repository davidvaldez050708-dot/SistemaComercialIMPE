<?php
require_once __DIR__ . '/TelefoniaResultadoVentasService.php';

/**
 * Identidad de las llamadas que nacen en Inicio del Asesor de Ventas.
 * La extensión por sí sola no identifica al propietario histórico: puede
 * reasignarse a otro usuario o utilizarse en el expediente de Vinculación.
 */
class TelefoniaMarcacionesVentasService
{
    private $db;

    public function __construct(?mysqli $db=null,bool $preparar=true)
    {
        $this->db = $db ?? (new Database())->connect();
        if($preparar)$this->asegurarEstructura();
    }

    public function asegurarEstructura(): void
    {
        $ok = $this->db->query(
            "CREATE TABLE IF NOT EXISTS telefonia_ventas_marcaciones (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id INT NOT NULL,
                extension VARCHAR(12) NOT NULL,
                destino VARCHAR(20) NOT NULL,
                token CHAR(32) NOT NULL,
                pbx_call_id VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                vinculada_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_ventas_marcacion_token (token),
                UNIQUE KEY uk_ventas_marcacion_pbx (pbx_call_id),
                KEY idx_ventas_marcacion_usuario (usuario_id, extension, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        if (!$ok) {
            throw new RuntimeException('No fue posible preparar el registro de marcaciones comerciales.');
        }
    }

    private function extension(int $uid): string {
        require_once __DIR__.'/TelefoniaVentasLlamadasService.php';(new TelefoniaVentasLlamadasService($this->db))->autorizar($uid);
        $s=$this->db->prepare("SELECT t.extension FROM telefonia_extensiones t JOIN usuarios u ON u.id=t.usuario_id WHERE t.usuario_id=? AND t.activo=1 AND t.proveedor='ZADARMA' AND t.permite_salientes=1 AND EXISTS(SELECT 1 FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=u.rol_id AND p.codigo='telefonia.salientes' AND p.estado=1)");
        $s->bind_param('i',$uid);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
        if(count($rows)!==1)throw new DomainException('No tienes una extensión propia para realizar llamadas.');return $rows[0]['extension'];
    }
    public function iniciar(int $usuarioId, string $telefono, string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new InvalidArgumentException('Identificador comercial no válido.');
        }
        $extension = $this->extension($usuarioId);
        $destino = TelefoniaContactosService::normalizarTelefono($telefono);
        $stmt = $this->db->prepare(
            "INSERT INTO telefonia_ventas_marcaciones (usuario_id, extension, destino, token)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) throw new RuntimeException('No se pudo registrar el inicio de llamada.');
        $stmt->bind_param('isss', $usuarioId, $extension, $destino, $token);
        $stmt->execute();
        $stmt->close();
        return $token;
    }

    private static function mismoDestino(string $guardado, string $proveedor): bool
    {
        $a = preg_replace('/\D+/', '', $guardado) ?: '';
        $b = preg_replace('/\D+/', '', $proveedor) ?: '';
        if ($a === '' || $b === '') return false;
        if (hash_equals($a, $b)) return true;
        // Zadarma puede notificar el número mexicano con o sin el prefijo 52.
        return strlen($a) >= 10 && strlen($b) >= 10 &&
            hash_equals(substr($a, -10), substr($b, -10)) &&
            (
                (strlen($a) === 12 && strpos($a, '52') === 0 &&
                    strlen($b) === 10) ||
                (strlen($b) === 12 && strpos($b, '52') === 0 &&
                    strlen($a) === 10)
            );
    }

    /**
     * Las marcaciones se guardan con CURRENT_TIMESTAMP de la sesión MySQL.
     * Los webhooks Zadarma almacenan received_at en America/Mexico_City.
     * Convertir explícitamente sin cambiar el huso de la conexión compartida.
     * Se consulta el offset real: en Hostinger SYSTEM equivale a UTC, mientras
     * las fixtures usan -06:00. No cambiar ni corregir marcas históricas.
     */
    private function zonaMySQL(): DateTimeZone
    {
        $r=$this->db->query('SELECT TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),NOW()) AS utc_offset')->fetch_assoc();
        $seconds=(int)($r['utc_offset']??0);
        if(abs($seconds)>14*3600)throw new DomainException('Zona horaria de la base de datos no válida.');
        $sign=$seconds<0?'-':'+';
        $abs=abs($seconds);
        return new DateTimeZone(sprintf('%s%02d:%02d',$sign,intdiv($abs,3600),intdiv($abs%3600,60)));
    }

    private function horaEventoDesdeMarcacion(string $mysqlDate): DateTimeImmutable
    {
        $source=$this->zonaMySQL();
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$mysqlDate,$source);
        if(!$date||$date->format('Y-m-d H:i:s')!==$mysqlDate)
            throw new DomainException('Fecha de marcación no válida.');
        return $date->setTimezone(new DateTimeZone('America/Mexico_City'));
    }

    public function fechaMarcacionLocal(string $mysqlDate): string
    {
        return $this->horaEventoDesdeMarcacion($mysqlDate)->format('Y-m-d H:i:s');
    }

    public function fechaMarcacionMySQL(string $localDate): string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$localDate,new DateTimeZone('America/Mexico_City'));
        if(!$date||$date->format('Y-m-d H:i:s')!==$localDate)
            throw new DomainException('Fecha de consulta no válida.');
        return $date->setTimezone($this->zonaMySQL())->format('Y-m-d H:i:s');
    }

    /**
     * Sólo 5 segundos antes y hasta 30 segundos después del intento.
     * Una espera mayor no demuestra que el evento pertenezca a ese intento:
     * en producción dos eventos estaban a +2 s, mientras el intento sin
     * confirmación tenía un falso candidato a +55 s. Cualquier ambigüedad
     * dentro de esta ventana queda sin asociación (forward/reverse).
     */
    public function vincular(int $usuarioId, string $token, string $pbxCallId): void
    {
        if (
            !preg_match('/^[a-f0-9]{32}$/', $token) ||
            !preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId)
        ) {
            throw new InvalidArgumentException('Identificador de marcación inválido.');
        }

        $extension = $this->extension($usuarioId);
        $stmt = $this->db->prepare(
            "SELECT destino, created_at, pbx_call_id
             FROM telefonia_ventas_marcaciones
             WHERE token = ? AND usuario_id = ? AND extension = ?
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar la marcación.');
        $stmt->bind_param('sis', $token, $usuarioId, $extension);
        $stmt->execute();
        $intent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$intent) throw new DomainException('La marcación no pertenece a este asesor.');
        $previous = trim((string)($intent['pbx_call_id'] ?? ''));
        if ($previous !== '') {
            if (!hash_equals($previous, $pbxCallId)) {
                throw new DomainException('La marcación ya está asociada a otra llamada.');
            }
            return;
        }

        // Debe existir un inicio real emitido por la PBX y coincidir con el
        // destino y la extensión del intento registrado ANTES de llamar.
        $stmt = $this->db->prepare(
            "SELECT destination
             FROM telefonia_zadarma_eventos
             WHERE pbx_call_id = ?
               AND internal = ?
               AND evento = 'NOTIFY_OUT_START'
               AND received_at BETWEEN
                   DATE_SUB(?, INTERVAL 5 SECOND) AND
                   DATE_ADD(?, INTERVAL 30 SECOND)
             ORDER BY id ASC LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar el inicio en Zadarma.');
        $createdAt = $this->fechaMarcacionLocal((string)$intent['created_at']);
        $stmt->bind_param('ssss', $pbxCallId, $extension, $createdAt, $createdAt);
        $stmt->execute();
        $evento = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$evento) {
            throw new DomainException('Esperando la confirmación de la llamada en Zadarma.');
        }
        if (!self::mismoDestino((string)$intent['destino'], (string)$evento['destination'])) {
            throw new DomainException('El destino no coincide con la marcación de Ventas.');
        }

        // No atribuir una llamada de expediente institucional al asesor.
        if($this->db->query("SHOW TABLES LIKE 'interacciones_vinculacion'")->num_rows){
        $stmt = $this->db->prepare(
            "SELECT 1 FROM interacciones_vinculacion
             WHERE proveedor_externo = 'ZADARMA'
               AND canal = 'LLAMADA_IP'
               AND id_externo = ?
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar la vinculación institucional.');
        $stmt->bind_param('s', $pbxCallId);
        $stmt->execute();
        $esInstitucional = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($esInstitucional) {
            throw new DomainException('Esta llamada pertenece a un expediente de Vinculación.');
        }
        }

        $stmt = $this->db->prepare(
            "UPDATE telefonia_ventas_marcaciones
             SET pbx_call_id = ?, vinculada_at = NOW()
             WHERE token = ? AND usuario_id = ? AND extension = ?
               AND pbx_call_id IS NULL"
        );
        if (!$stmt) throw new RuntimeException('No se pudo vincular la llamada comercial.');
        $stmt->bind_param('ssis', $pbxCallId, $token, $usuarioId, $extension);
        try {
            $ok = $stmt->execute();
            if (!$ok) {
                if ($stmt->errno === 1062) {
                    throw new DomainException('La llamada ya está vinculada a otra marcación. Se conserva su asociación original.');
                }
                throw new RuntimeException('No se pudo vincular la llamada comercial.');
            }
            $actualizadas = $stmt->affected_rows;
        } catch (mysqli_sql_exception $e) {
            // Un intento duplicado no debe interrumpir la conciliación de toda
            // la cuenta. La restricción única mantiene intacta la asociación;
            // reconciliarPendientes ya conserva las evidencias insuficientes.
            if ((int)$e->getCode() !== 1062) throw $e;
            throw new DomainException('La llamada ya está vinculada a otra marcación. Se conserva su asociación original.', 0, $e);
        } finally {
            $stmt->close();
        }
        if ($actualizadas !== 1) {
            throw new DomainException('La llamada comercial no pudo vincularse.');
        }
    }

    /**
     * Recupera enlaces de llamadas cuando el asesor cerró o recargó la
     * página antes de recibir la respuesta tardía del webhook de Zadarma.
     * Nunca atribuye llamadas de Vinculación: vincular() lo comprueba.
     */
    public function reconciliarPendientes(int $usuarioId, string $extension): void
    {
        if ($extension !== $this->extension($usuarioId)) {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT token, destino, created_at
             FROM telefonia_ventas_marcaciones
             WHERE usuario_id = ? AND extension = ?
               AND pbx_call_id IS NULL
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY created_at DESC"
        );
        if (!$stmt) throw new RuntimeException('No se pudieron verificar marcaciones recientes.');
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $rs = $stmt->get_result();
        $pendientes = [];
        while ($r = $rs->fetch_assoc()) $pendientes[] = $r;
        $stmt->close();

        if(!$pendientes)return;
        // La ventana del webhook usa reloj de México; MySQL puede usar UTC.
        $instantes=[];foreach($pendientes as $i)$instantes[]=$this->horaEventoDesdeMarcacion((string)$i['created_at'])->getTimestamp();
        $from=(new DateTimeImmutable('@'.(min($instantes)-5)))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
        $until=(new DateTimeImmutable('@'.(max($instantes)+30)))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
        $eventos=$this->db->prepare("SELECT pbx_call_id,destination,MIN(received_at) fecha FROM telefonia_zadarma_eventos WHERE internal=? AND evento='NOTIFY_OUT_START' AND received_at BETWEEN ? AND ? GROUP BY pbx_call_id,destination");
        $eventos->bind_param('sss',$extension,$from,$until);$eventos->execute();$rs=$eventos->get_result();$byNumber=[];
        while($ev=$rs->fetch_assoc()){try{$number=TelefoniaContactosService::normalizarTelefono((string)$ev['destination']);$byNumber[$number][]=$ev;}catch(InvalidArgumentException $ignored){}}
        $eventos->close();$forward=[];$reverse=[];
        foreach($pendientes as $intent){
            try{$number=TelefoniaContactosService::normalizarTelefono($intent['destino']);}catch(InvalidArgumentException $ignored){continue;}
            $at=$this->horaEventoDesdeMarcacion((string)$intent['created_at'])->getTimestamp();
            foreach($byNumber[$number]??[] as $ev){$when=(new DateTimeImmutable((string)$ev['fecha'],new DateTimeZone('America/Mexico_City')))->getTimestamp();if($when<$at-5||$when>$at+30)continue;
                $ref=(string)$ev['pbx_call_id'];$forward[$intent['token']][$ref]=true;$reverse[$ref][$intent['token']]=true;
            }
        }
        foreach($forward as $token=>$refs){if(count($refs)!==1)continue;$ref=(string)array_key_first($refs);if(count($reverse[$ref])!==1)continue;
            try{$this->vincular($usuarioId,$token,$ref);}catch(DomainException|InvalidArgumentException $e){/* Evidencia insuficiente: conservar sin vincular. */}
        }
    }

    public function pertenece(int $usuarioId, string $extension, string $pbxCallId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM telefonia_ventas_marcaciones
             WHERE usuario_id = ? AND extension = ? AND pbx_call_id = ?
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar el origen de la llamada.');
        $stmt->bind_param('iss', $usuarioId, $extension, $pbxCallId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }
}
