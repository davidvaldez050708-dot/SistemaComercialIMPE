<?php
final class TelefoniaBorradorService
{
    private $db;
    public function __construct($db) {$this->db=$db;}
    public static function validarId(string $id): bool {return (bool)preg_match('/^[a-zA-Z0-9_-]{6,100}$/D',$id);}
    public static function limpiar(array $datos): array
    {
        $limites=['nombre'=>180,'persona'=>140,'telefono'=>40,'correo'=>160,'notas'=>1000,
            'contacto_nombre'=>140,'contacto_cargo'=>150,'nombre_entidad'=>180,'persona_atendio'=>140,
            'telefono_capturado'=>40,'correo_capturado'=>160,'observacion'=>1000,
            'nuevo_contacto_nombre'=>140,'nuevo_contacto_cargo'=>150,'nuevo_telefono_contacto'=>40,
            'nuevo_correo_contacto'=>160,'decision'=>30,'seguimiento_id'=>12,'estado_id'=>12,
            'tipo_entidad'=>30,'resultado'=>40,'proxima_accion'=>40,'proxima_accion_at'=>30,
            'verificacion_telefono_confirmado'=>1,'verificacion_correo_confirmado'=>1,
            'verificacion_contacto_confirmado'=>1,'descartar'=>1,'motivo_descarte'=>500];
        $out=[];
        foreach ($limites as $campo=>$max) {
            if (!isset($datos[$campo])) continue;
            if (!is_string($datos[$campo]) || !preg_match('//u',$datos[$campo]) || mb_strlen($datos[$campo],'UTF-8')>$max) {
                throw new InvalidArgumentException('Uno de los datos del borrador no es válido.');
            }
            $out[$campo]=$datos[$campo];
        }
        return $out;
    }
    public function leer(int $usuario,string $id): ?array
    {
        try {
            $stmt=$this->db->prepare('SELECT datos_json FROM telefonia_borradores WHERE usuario_id=? AND referencia=? AND expires_at>NOW()');
            if (!$stmt) {if ((int)$this->db->errno===1146) return null;throw new RuntimeException('No se pudo recuperar el borrador.');}
            $stmt->bind_param('is',$usuario,$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
            $data=$row?json_decode($row['datos_json'],true):null;
            return empty($data['closed'])?$data:null;
        } catch (mysqli_sql_exception $e) {if ($e->getCode()===1146) return null;throw $e;}
    }
    private function asegurarEstructura(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS telefonia_borradores (
            usuario_id INT NOT NULL, referencia VARCHAR(100) NOT NULL,
            datos_json MEDIUMTEXT NOT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL, PRIMARY KEY(usuario_id,referencia), KEY idx_borrador_vigencia(expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    public function guardar(int $usuario,array $ids,array $datos,int $revision): array
    {
        $datos=self::limpiar($datos);$this->asegurarEstructura();
        $cleanup=$this->db->prepare('DELETE FROM telefonia_borradores WHERE usuario_id=? AND expires_at<=NOW() LIMIT 100');
        $cleanup->bind_param('i',$usuario);$cleanup->execute();$cleanup->close();
        sort($ids,SORT_STRING); // Orden fijo de bloqueos cuando llegan alias en paralelo.
        $saved=['saved_at'=>$revision,'valores'=>$datos];
        $json=json_encode($saved,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $this->db->begin_transaction();
        try {
            foreach ($ids as $id) {
                $stmt=$this->db->prepare("INSERT IGNORE INTO telefonia_borradores (usuario_id,referencia,datos_json,expires_at)
                    VALUES (?,?,'{}',DATE_ADD(NOW(),INTERVAL 1 DAY))");
                $stmt->bind_param('is',$usuario,$id);$stmt->execute();$stmt->close();
                $stmt=$this->db->prepare('SELECT datos_json FROM telefonia_borradores WHERE usuario_id=? AND referencia=? FOR UPDATE');
                $stmt->bind_param('is',$usuario,$id);$stmt->execute();$prev=$stmt->get_result()->fetch_assoc();$stmt->close();
                $anterior=json_decode((string)($prev['datos_json'] ?? '{}'),true) ?: [];
                if (!empty($anterior['closed'])) throw new DomainException('La atención ya fue guardada; el borrador está cerrado.');
                if ((int)($anterior['saved_at'] ?? 0)>=$revision && ($prev['datos_json'] ?? '')!==$json) {
                    throw new DomainException('Existe un borrador más reciente. Recupera la captura antes de reemplazarla.');
                }
                $stmt=$this->db->prepare('UPDATE telefonia_borradores SET datos_json=?,updated_at=NOW(),
                    expires_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE usuario_id=? AND referencia=?');
                $stmt->bind_param('sis',$json,$usuario,$id);if (!$stmt->execute()) throw new RuntimeException('No se pudo guardar el borrador.');$stmt->close();
            }
            $this->db->commit();return $saved;
        } catch (Throwable $e) {$this->db->rollback();throw $e;}
    }
    public function eliminar(int $usuario,array $ids): void
    {
        $this->asegurarEstructura();
        foreach ($ids as $id) {
            // Tombstone: una petición antigua en vuelo no puede resucitar datos ya cerrados.
            $stmt=$this->db->prepare("INSERT INTO telefonia_borradores (usuario_id,referencia,datos_json,expires_at)
                VALUES (?,?,'{\"closed\":true}',DATE_ADD(NOW(),INTERVAL 1 DAY))
                ON DUPLICATE KEY UPDATE datos_json=VALUES(datos_json),expires_at=VALUES(expires_at)");
            $stmt->bind_param('is',$usuario,$id);$stmt->execute();$stmt->close();
        }
    }
}
