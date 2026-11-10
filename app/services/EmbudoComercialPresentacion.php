<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/ProspectoFechaHoraHelper.php';

/**
 * Fechas y etiquetas exclusivamente de Gestión comercial.
 * Los DATETIME de movimientos/agenda están almacenados en UTC.
 * Los DATE de revisión y jornada son fechas locales, sin convertir zona.
 */
final class EmbudoComercialPresentacion
{
    private const MESES=[
        1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',
        7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'
    ];

    public static function fechaLocal(?string $fecha): string
    {
        if (!is_string($fecha) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$fecha)) {
            return 'Sin fecha';
        }
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha,
            new DateTimeZone('America/Mexico_City'));
        if (!$d || $d->format('Y-m-d')!==$fecha) return 'Fecha no válida';
        return (int)$d->format('j').' '.self::MESES[(int)$d->format('n')].
            ' '.$d->format('Y');
    }

    public static function fechaUtc(?string $fecha): string
    {
        if (!$fecha) return 'Sin programar';
        $local=ProspectoFechaHoraHelper::aMexico($fecha);
        if (!is_string($local) ||
            !preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):\d{2}$/D',
                $local,$match)) return 'Fecha no disponible';
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$local,
            new DateTimeZone('America/Mexico_City'));
        if (!$d || $d->format('Y-m-d H:i:s')!==$local) {
            return 'Fecha no disponible';
        }
        $hora=(int)$d->format('g');
        $min=$d->format('i');
        $periodo=$d->format('a')==='am'?'a. m.':'p. m.';
        return self::fechaLocal($d->format('Y-m-d')).', '.$hora.':'.$min.' '.$periodo;
    }
}
