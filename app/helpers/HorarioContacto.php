<?php
/** Preferencia de contacto; nunca representa una cita ni convierte zonas horarias. */
final class HorarioContacto
{
    public const DIAS=['lun'=>'Lun','mar'=>'Mar','mie'=>'Mié','jue'=>'Jue','vie'=>'Vie','sab'=>'Sáb','dom'=>'Dom'];
    public static function desdeEntrada(array $entrada): ?string
    {
        if (!array_key_exists('contacto_disponibilidad',$entrada)) {
            foreach (['contacto_dias','contacto_desde','contacto_hasta'] as $key) if (!empty($entrada[$key])) throw new DomainException('Selecciona tu disponibilidad de contacto.');
            return null; // Formularios que ya estaban abiertos antes de esta actualización.
        }
        $modo=$entrada['contacto_disponibilidad'];
        if ($modo==='sin_preferencia') return 'Sin preferencia';
        if ($modo!=='personalizado') throw new DomainException('Selecciona tu disponibilidad de contacto.');
        $dias=$entrada['contacto_dias']??null;
        if (!is_array($dias)||!$dias||count($dias)>7) throw new DomainException('Selecciona al menos un día para contactarte.');
        foreach ($dias as $dia) if (!is_string($dia)||!isset(self::DIAS[$dia])) throw new DomainException('Selecciona días de contacto válidos.');
        $desde=$entrada['contacto_desde']??null;$hasta=$entrada['contacto_hasta']??null;
        foreach ([$desde,$hasta] as $hora) if (!is_string($hora)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$hora)) throw new DomainException('Indica una hora de inicio y una hora de fin válidas.');
        if ($hasta<=$desde) throw new DomainException('La hora de fin debe ser posterior a la hora de inicio.');
        $etiquetas=[];foreach (self::DIAS as $codigo=>$nombre) if (in_array($codigo,$dias,true)) $etiquetas[]=$nombre;
        return implode(', ',$etiquetas).' · '.$desde.'–'.$hasta.' (hora local)';
    }
    public static function paraGuardar($valor): ?string
    {
        if ($valor===null) return null;
        if (!is_string($valor)||!mb_check_encoding($valor,'UTF-8')||mb_strlen($valor)>255||str_contains($valor,"\0")) throw new DomainException('Horario de contacto no válido.');
        $valor=trim($valor);return $valor===''?null:$valor;
    }
}
