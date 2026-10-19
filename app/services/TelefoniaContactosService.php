<?php

require_once __DIR__ . '/../../config/db_connection.php';

/**
 * Agenda telefónica personal de prospectos (personas físicas):
 * no crea instituciones ni expedientes comerciales.
 * Todas las operaciones se limitan al usuario autenticado.
 */
class TelefoniaContactosService
{
    private $connection;

    public function __construct(?mysqli $db=null)
    {
        $this->connection = $db ?? (new Database())->connect();
        $this->asegurarEstructura();
    }

    public static function tokenFormulario(): string
    {
        if (
            !isset($_SESSION['telefonia_contactos_csrf']) ||
            !is_string($_SESSION['telefonia_contactos_csrf']) ||
            !preg_match('/^[0-9a-f]{64}$/', $_SESSION['telefonia_contactos_csrf'])
        ) {
            $_SESSION['telefonia_contactos_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['telefonia_contactos_csrf'];
    }

    public static function validarToken(string $token): bool
    {
        return $token !== '' &&
            hash_equals(self::tokenFormulario(), $token);
    }

    private function asegurarEstructura(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS telefonia_contactos_personales (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    usuario_id INT NOT NULL,
                    nombre VARCHAR(90) NOT NULL,
                    telefono VARCHAR(18) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uk_telefono_contacto_usuario (usuario_id, telefono),
                    KEY idx_telefono_contactos_usuario (usuario_id, nombre)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        if (!$this->connection->query($sql)) {
            throw new RuntimeException('No fue posible preparar los teléfonos guardados.');
        }
    }

    public static function normalizarTelefono(string $entrada): string
    {
        $entrada = trim($entrada);
        if (
            $entrada === '' ||
            !preg_match('/^\\+?[0-9 ()\\.\\-]+$/', $entrada)
        ) {
            throw new InvalidArgumentException('Escribe un teléfono con dígitos y, opcionalmente, prefijo internacional.');
        }

        $digitos = preg_replace('/\\D+/', '', $entrada) ?? '';
        if (strlen($digitos) === 10) {
            $digitos = '52' . $digitos;
        }

        if (!preg_match('/^[1-9][0-9]{10,14}$/', $digitos)) {
            throw new InvalidArgumentException('Usa diez dígitos de México o un teléfono internacional válido.');
        }

        return '+' . $digitos;
    }

    private function rows(string $sql,array $args=[]): array {
        $s=$this->connection->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
        $s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();return $rows;
    }
    public static function fechaFutura(string $value): ?string {
        $value=str_replace('T',' ',trim($value));if($value==='')return null;
        $format=strlen($value)===16?'Y-m-d H:i':'Y-m-d H:i:s';$date=DateTimeImmutable::createFromFormat('!'.$format,$value);
        if(!$date||$date->format($format)!==$value||$date->getTimestamp()<=time())throw new InvalidArgumentException('Selecciona una fecha futura válida.');
        return $date->format('Y-m-d H:i:s');
    }
    private function ampliada(): bool {
        return $this->connection->query("SHOW COLUMNS FROM telefonia_contactos_personales LIKE 'correo'")->num_rows>0;
    }
    public function listar(int $usuarioId): array {
        if($usuarioId<=0)return [];
        $fields=$this->ampliada()?'correo,observacion,proxima_accion_at':"'' correo,'' observacion,NULL proxima_accion_at";
        return $this->rows("SELECT id,nombre,telefono,$fields FROM telefonia_contactos_personales WHERE usuario_id=? ORDER BY nombre,id DESC LIMIT 150",[$usuarioId]);
    }
    public function guardar(int $usuarioId,string $nombre,string $telefono,array $datos=[]): array {
        if($usuarioId<=0)throw new InvalidArgumentException('Sesión no válida.');
        $nombre=trim(preg_replace('/\\s+/u',' ',$nombre)??$nombre);
        if(mb_strlen($nombre)<2||mb_strlen($nombre)>90)throw new InvalidArgumentException('Escribe el nombre del prospecto (entre 2 y 90 caracteres).');
        $telefono=self::normalizarTelefono($telefono);$id=(int)($datos['contacto_id']??0);
        $correo=trim((string)($datos['correo']??''));$note=trim((string)($datos['observacion']??''));
        if(mb_strlen($correo)>180||($correo!==''&&!filter_var($correo,FILTER_VALIDATE_EMAIL)))throw new InvalidArgumentException('Escribe un correo válido.');
        if(mb_strlen($note)>500)throw new InvalidArgumentException('Limita las observaciones de agenda a 500 caracteres.');
        $next=self::fechaFutura((string)($datos['proxima_accion_at']??''));
        $expanded=$this->ampliada();
        if(!$expanded&&($correo!==''||$note!==''||$next!==null))throw new DomainException('Falta aplicar la migración de agenda comercial.');
        if($id>0&&!$this->rows('SELECT id FROM telefonia_contactos_personales WHERE usuario_id=? AND id=?',[$usuarioId,$id]))throw new DomainException('El contacto no pertenece a tu agenda.');
        $same=$this->rows('SELECT * FROM telefonia_contactos_personales WHERE usuario_id=? AND telefono=?',[$usuarioId,$telefono]);
        if($same&&(int)$same[0]['id']!==$id){
            // Reintento idéntico es idempotente; nunca renombrar otro prospecto por coincidir el número.
            if($id===0&&$same[0]['nombre']===$nombre&&(!$expanded||($same[0]['correo']===$correo&&$same[0]['observacion']===$note&&$same[0]['proxima_accion_at']===$next)))return $same[0];
            throw new DomainException('Ese número ya está en tu agenda. Edita el contacto existente.');
        }
        if($id===0&&(int)$this->rows('SELECT COUNT(*) n FROM telefonia_contactos_personales WHERE usuario_id=?',[$usuarioId])[0]['n']>=150)throw new DomainException('Llegaste al máximo de 150 contactos.');
        $fields=$expanded?',correo=?,observacion=?,proxima_accion_at=?':'';
        $args=[$nombre,$telefono];if($expanded)$args=array_merge($args,[$correo,$note,$next]);
        if($id>0){$sql="UPDATE telefonia_contactos_personales SET nombre=?,telefono=?$fields WHERE usuario_id=? AND id=?";$args=array_merge($args,[$usuarioId,$id]);}
        else{$sql="INSERT INTO telefonia_contactos_personales SET nombre=?,telefono=?$fields,usuario_id=?";$args[]=$usuarioId;}
        $s=$this->connection->prepare($sql);$s->bind_param(str_repeat('s',count($args)),...$args);
        try{$s->execute();if(!$id)$id=(int)$this->connection->insert_id;}
        catch(mysqli_sql_exception $e){if($e->getCode()===1062)throw new DomainException('Ese teléfono ya pertenece a otro contacto de tu agenda.');throw $e;}finally{$s->close();}
        return $this->rows('SELECT * FROM telefonia_contactos_personales WHERE usuario_id=? AND id=?',[$usuarioId,$id])[0];
    }
    public function eliminar(int $usuarioId,int $contactoId): bool {
        if($usuarioId<=0||$contactoId<=0)throw new InvalidArgumentException('Contacto no válido.');
        $s=$this->connection->prepare('DELETE FROM telefonia_contactos_personales WHERE usuario_id=? AND id=?');$s->bind_param('ii',$usuarioId,$contactoId);$s->execute();$ok=$s->affected_rows>0;$s->close();return $ok;
    }
}
