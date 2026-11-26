<?php
declare(strict_types=1);
require_once __DIR__.'/TelefoniaVentasLlamadasService.php';

/** Anexos inmutables. No actualiza resultados, llamadas, agenda ni evidencia. */
final class TelefoniaObservacionesVentasService
{
    public function __construct(private mysqli $db) {}

    private function rows(string $sql,array $args=[]): array
    {
        $s=$this->db->prepare($sql);
        try {
            if($args)$s->bind_param(str_repeat('s',count($args)),...$args);
            $s->execute();return $s->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {$s->close();}
    }

    public function disponible(): bool
    {
        return $this->rows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_ventas_observaciones'")!==[];
    }

    private function resultado(int $uid,string $ref,string $ext,bool $lock=false): array
    {
        if($uid<=0||!preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref)||!preg_match('/^[0-9]{3,6}$/D',$ext))
            throw new InvalidArgumentException('La llamada no es válida.');
        $r=$this->rows('SELECT r.id FROM telefonia_ventas_resultados r
            JOIN telefonia_ventas_llamadas c ON c.usuario_id=r.usuario_id
                AND c.extension=BINARY r.extension AND c.pbx_call_id=BINARY r.pbx_call_id
            WHERE r.usuario_id=? AND r.extension=BINARY ? AND r.pbx_call_id=BINARY ? LIMIT 2'.($lock?' FOR UPDATE':''),[$uid,$ext,$ref]);
        if(count($r)!==1)throw new DomainException('No se encontró un resultado guardado de esta llamada.');
        return $r[0];
    }

    private static function presentar(array $r): array
    {
        $date=(new DateTimeImmutable($r['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Mexico_City'));
        return ['id'=>(int)$r['id'],'autor'=>$r['autor_nombre'],'nota'=>$r['nota'],
            'fecha'=>$date->format('d/m/Y H:i'),'fecha_iso'=>$date->format(DateTimeInterface::ATOM)];
    }

    private function listado(int $resultado,int $uid,string $ref,string $ext): array
    {
        return array_map([self::class,'presentar'],$this->rows('SELECT id,autor_nombre,nota,created_at FROM telefonia_ventas_observaciones WHERE resultado_id=? AND autor_usuario_id=? AND extension=BINARY ? AND pbx_call_id=BINARY ? ORDER BY id',[$resultado,$uid,$ext,$ref]));
    }

    public function propias(int $uid,string $ref,string $ext): array
    {
        (new TelefoniaVentasLlamadasService($this->db))->autorizar($uid);
        $r=$this->resultado($uid,$ref,$ext);$ready=$this->disponible();
        return ['disponible'=>$ready,'observaciones'=>$ready?$this->listado((int)$r['id'],$uid,$ref,$ext):[]];
    }

    public function supervisadas(int $supervisor,string $key): array
    {
        require_once __DIR__.'/SupervisionLlamadasRepository.php';
        // Se vuelve a comprobar el permiso y el equipo vigente en cada consulta.
        $call=(new SupervisionLlamadasRepository($this->db))->obtener($supervisor,$key);
        if(!str_starts_with($key,'venta:'))return ['disponible'=>true,'observaciones'=>[]];
        $ready=$this->disponible();
        if(!$ready)return ['disponible'=>false,'observaciones'=>[]];
        try {$r=$this->resultado((int)$call['usuario_id'],$call['pbx_call_id'],$call['extension']);}
        catch(DomainException $e){return ['disponible'=>true,'observaciones'=>[]];}
        return ['disponible'=>true,'observaciones'=>$this->listado((int)$r['id'],(int)$call['usuario_id'],$call['pbx_call_id'],$call['extension'])];
    }

    public function agregar(int $uid,string $ref,string $ext,string $nota,string $token): array
    {
        (new TelefoniaVentasLlamadasService($this->db))->autorizar($uid);
        $nota=trim($nota);
        if(!mb_check_encoding($nota,'UTF-8')||mb_strlen($nota)<1||mb_strlen($nota)>1000||str_contains($nota,"\0"))
            throw new InvalidArgumentException('Escribe una observación de entre 1 y 1000 caracteres.');
        if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new InvalidArgumentException('El envío no es válido. Vuelve a abrir la llamada.');
        if(!$this->disponible())throw new DomainException('Falta instalar las observaciones adicionales. Solicita su instalación al administrador.');
        $this->db->begin_transaction();
        try {
            // Bloquear el resultado evita perder el vínculo durante el guardado.
            $r=$this->resultado($uid,$ref,$ext,true);$id=(int)$r['id'];
            $previous=$this->rows('SELECT * FROM telefonia_ventas_observaciones WHERE autor_usuario_id=? AND token=?',[$uid,$token]);
            if($previous){
                if((int)$previous[0]['resultado_id']!==$id||$previous[0]['extension']!==$ext||$previous[0]['pbx_call_id']!==$ref||$previous[0]['nota']!==$nota)
                    throw new DomainException('Este envío ya se utilizó para otra observación.');
                $saved=$previous[0];
            } else {
                $user=$this->rows('SELECT CONCAT_WS(\' \',nombre,apellidos) nombre FROM usuarios WHERE id=? AND estado=1 AND rol_id=3',[$uid]);
                if(!$user)throw new DomainException('No tienes acceso a la atención comercial.');
                $name=mb_substr($user[0]['nombre'],0,255);
                $s=$this->db->prepare('INSERT INTO telefonia_ventas_observaciones(resultado_id,autor_usuario_id,extension,pbx_call_id,autor_nombre,nota,token,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())');
                try {$s->bind_param('iisssss',$id,$uid,$ext,$ref,$name,$nota,$token);$s->execute();$newId=$this->db->insert_id;}
                finally {$s->close();}
                $saved=$this->rows('SELECT * FROM telefonia_ventas_observaciones WHERE id=?',[$newId])[0];
            }
            $this->db->commit();return self::presentar($saved);
        } catch(Throwable $e){$this->db->rollback();throw $e;}
    }
}
