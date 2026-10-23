<?php
require_once __DIR__.'/../app/helpers/ConvocatoriaVentanaMensual.php';

function revisar($condicion, $mensaje): void
{
    if (!$condicion) {
        fwrite(STDERR, "ERROR: ".$mensaje."\n");
        exit(1);
    }
    echo "OK: ".$mensaje."\n";
}
function ventana(string $fecha): array
{
    return ConvocatoriaVentanaMensual::meses(new DateTimeImmutable($fecha));
}
function claves(array $meses): array
{
    return array_column($meses,'valor');
}

revisar(claves(ventana('2026-09-15')) === ['2026-09'], 'septiembre inicia el ciclo');
revisar(claves(ventana('2026-10-23')) === ['2026-09','2026-10'], 'octubre ofrece septiembre y octubre');
revisar(claves(ventana('2026-11-01')) === ['2026-09','2026-10','2026-11'], 'noviembre se añade sin eliminar meses');
revisar(claves(ventana('2026-12-31')) === ['2026-09','2026-10','2026-11','2026-12'], 'diciembre completa cuatro meses');
revisar(claves(ventana('2027-01-01')) === ['2026-10','2026-11','2026-12','2027-01'], 'enero quita septiembre del selector');
revisar(claves(ventana('2027-02-01')) === ['2026-11','2026-12','2027-01','2027-02'], 'febrero quita octubre del selector');
revisar(claves(ventana('2027-04-01')) === ['2027-01','2027-02','2027-03','2027-04'], 'rotación después de cruzar año');
revisar(ConvocatoriaVentanaMensual::seleccionar('2026-09',ventana('2027-01-05')) === '', 'mes expirado no continúa seleccionado');
revisar(ConvocatoriaVentanaMensual::seleccionar('2027-01',ventana('2027-01-05')) === '2027-01', 'mes visible sí se puede seleccionar');
revisar(ConvocatoriaVentanaMensual::seleccionar('2028-99',ventana('2027-01-05')) === '', 'mes inválido rechazado');
revisar(ConvocatoriaVentanaMensual::seleccionar('archivo',ventana('2027-01-05')) === 'archivo', 'archivo independiente de la ventana');
$rows = [
    ['id'=>1,'fecha_inicio'=>'2026-09-04','estado'=>0],
    ['id'=>2,'fecha_inicio'=>'2026-09-07','estado'=>1],
    ['id'=>3,'fecha_inicio'=>'2026-10-09','estado'=>0],
    ['id'=>4,'fecha_inicio'=>'2027-01-02','estado'=>0],
    ['id'=>5,'fecha_inicio'=>null,'estado'=>0],
    ['id'=>6,'fecha_inicio'=>'2026-12-03','estado'=>1],
    ['id'=>7,'fecha_inicio'=>'2027-02-01','estado'=>0]
];
$enero = ventana('2027-01-05');
$ids = static function(array $rows): array {return array_column($rows,'id');};
revisar($ids(ConvocatoriaVentanaMensual::filtrar($rows,'',$enero)) === [2,3,4,6],
    'por defecto se conservan activas anteriores y desactivadas de meses recientes');
revisar($ids(ConvocatoriaVentanaMensual::filtrar($rows,'2027-01',$enero)) === [4], 'filtro enero incluye sólo enero');
revisar($ids(ConvocatoriaVentanaMensual::filtrar($rows,'2026-10',$enero)) === [3], 'filtro octubre encuentra inactivas');
revisar($ids(ConvocatoriaVentanaMensual::filtrar($rows,'archivo',$enero)) === [1,2,5,7],
    'archivo conserva septiembre, registros sin fecha y meses externos');
revisar(count($rows)===7,'filtrar nunca borra registros');
