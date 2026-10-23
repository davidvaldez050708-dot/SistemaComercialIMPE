<?php
/** Identidad única; el código retirado sólo se admite como alias de entrada. */
final class ProgramaEducativo
{
    public const BACHILLERATO = 'bachillerato-general';
    public const NOMBRE = 'Bachillerato General';
    public const ALIAS = 'bachillerato-286';

    public static function codigo(string $codigo): string
    {
        return $codigo === self::ALIAS ? self::BACHILLERATO : $codigo;
    }

    public static function nombre(string $nombre): string
    {
        return preg_match('/^bachillerato(?:\s+|-)?286$/iu', trim($nombre)) ? self::NOMBRE : $nombre;
    }

    public static function texto(string $texto): string
    {
        return preg_replace('/\bbachillerato(?:\s+|-)?286\b/iu', self::NOMBRE, $texto);
    }

    public static function clasificacion(string $valor): string
    {
        return $valor === 'bachillerato|'.self::ALIAS ? 'bachillerato|'.self::BACHILLERATO : $valor;
    }

    public static function programas(array $filas): array
    {
        $result = [];
        foreach ($filas as $fila) {
            $fila['codigo'] = self::codigo((string)$fila['codigo']);
            if ($fila['codigo'] === self::BACHILLERATO) $fila['nombre'] = self::NOMBRE;
            $result[$fila['codigo']] = $fila;
        }
        return array_values($result);
    }

    public static function entrada(array $entrada): array
    {
        foreach (['subtipo','subtipo_convocatoria','programa_codigo'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) $entrada[$campo] = self::codigo($entrada[$campo]);
        }
        if (isset($entrada['clasificacion']) && is_string($entrada['clasificacion'])) $entrada['clasificacion'] = self::clasificacion($entrada['clasificacion']);
        if (isset($entrada['perfil_interes']) && is_string($entrada['perfil_interes'])) $entrada['perfil_interes'] = self::nombre($entrada['perfil_interes']);
        if (isset($entrada['titulo']) && is_string($entrada['titulo'])) $entrada['titulo'] = self::texto($entrada['titulo']);
        return $entrada;
    }

    public static function lote(array $entradas, array $imagenes): array
    {
        $result = [];
        foreach ($entradas as $codigo => $entrada) {
            $canonical = self::codigo((string)$codigo);
            if (array_key_exists($canonical,$result)) throw new DomainException('El mismo programa se envió más de una vez.',422);
            $result[$canonical] = is_array($entrada) ? self::entrada($entrada) : $entrada;
        }
        foreach ($imagenes as &$valores) {
            if (!is_array($valores) || !array_key_exists(self::ALIAS,$valores)) continue;
            if (array_key_exists(self::BACHILLERATO,$valores)) throw new DomainException('El mismo programa tiene dos imágenes.',422);
            $valores[self::BACHILLERATO] = $valores[self::ALIAS];unset($valores[self::ALIAS]);
        }
        return [$result,$imagenes];
    }
}
