<?php
declare(strict_types=1);

/** Fechas de Usuarios: acceso DATETIME en UTC y TIMESTAMP por su instante real. */
final class UsuarioFechaHoraHelper
{
    public static function aMexico($fecha)
    {
        if ($fecha === null || $fecha === '') return $fecha;
        if (!is_string($fecha) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?$/D', $fecha)) return $fecha;
        $formato = strlen($fecha) > 19 ? '!Y-m-d H:i:s.u' : '!Y-m-d H:i:s';
        $utc = DateTimeImmutable::createFromFormat($formato, $fecha, new DateTimeZone('UTC'));
        if (!$utc || substr($fecha, 0, 4) === '0000' || $utc->format('Y-m-d H:i:s') !== substr($fecha, 0, 19)) return $fecha;
        return $utc->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
    }

    public static function normalizar($usuario)
    {
        if (!is_array($usuario)) return $usuario;
        if (array_key_exists('ultimo_acceso', $usuario)) {
            $utc = array_key_exists('ultimo_acceso_utc', $usuario)
                ? $usuario['ultimo_acceso_utc'] : $usuario['ultimo_acceso'];
            $usuario['ultimo_acceso_utc'] = $utc;
            $usuario['ultimo_acceso'] = self::aMexico($utc);
        }
        // UNIX_TIMESTAMP de una columna TIMESTAMP conserva el instante incluso
        // si cambia la zona de la conexión. No inferir la zona de otros DATETIME.
        foreach (['created_at','updated_at'] as $campo) {
            $clave = '__impe_'.$campo.'_epoch';
            if (isset($usuario[$clave]) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', (string)$usuario[$clave])) {
                $usuario[$campo] = (new DateTimeImmutable('@'.(string)(int)$usuario[$clave]))
                    ->setTimezone(new DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s');
            }
            unset($usuario[$clave]);
        }
        return $usuario;
    }
}
