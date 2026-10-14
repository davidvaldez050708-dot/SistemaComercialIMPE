<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/helpers/ZadarmaPbxIdentifierHelper.php';

function comprobar(bool $ok, string $mensaje): void
{
    if (!$ok) {
        fwrite(STDERR, "ERROR: " . $mensaje . "\n");
        exit(1);
    }
}

// El ejemplo oficial devuelve call_id sin comillas, como número decimal.
// Nunca pasar por float antes de solicitar /v1/pbx/record/request/.
$json = '{"status":"success","stats":[{"call_id":1439981389.2702773,"pbx_call_id":"in_ae6b03b3b0765d127ec0b739209346bbc4f0d52d","sip":100,"destination":101,"seconds":10,"is_recorded":true},{"call_id":"1458313316.343456","pbx_call_id":"out_abc123","sip":101}]}';
$resultado = ZadarmaPbxIdentifierHelper::decodificarEstadisticas($json);
comprobar(count($resultado['stats']) === 2, 'La respuesta perdió filas.');
comprobar(
    ZadarmaPbxIdentifierHelper::callIdDeFila($resultado['stats'][0]) === '1439981389.2702773',
    'El identificador decimal de Zadarma perdió precisión.'
);
comprobar(
    ZadarmaPbxIdentifierHelper::callIdDeFila($resultado['stats'][1]) === '1458313316.343456',
    'El identificador decimal entre comillas fue modificado.'
);
comprobar(
    ZadarmaPbxIdentifierHelper::pbxValido($resultado['stats'][0]['pbx_call_id']),
    'Se rechazó un PBX ID documentado.'
);
foreach ([
    'https://atacante.com/falso',
    '../../passwd',
    '14399.27?download=1',
    '14399.27&x=1',
    "14399.27\n",
    'abc/123456',
    ''
] as $invalido) {
    comprobar(!ZadarmaPbxIdentifierHelper::callIdValido($invalido),
        'Se aceptó un identificador inseguro.');
}
comprobar(
    ZadarmaPbxIdentifierHelper::callIdDeFila(['call_id' => 1439981389.2702773]) === '',
    'Se aceptó un float cuya precisión ya se pudo perder.'
);
comprobar(
    ZadarmaPbxIdentifierHelper::callIdValido('call_id_ABC_12345'),
    'Se rompieron formatos alfanuméricos compatibles.'
);
comprobar(
    ZadarmaPbxIdentifierHelper::callIdDeFila(['call_id' => 123456789]) === '123456789',
    'Se rechazó un identificador entero.'
);
echo "Zadarma decimal call_id: OK (precisión exacta, compatibilidad y rechazos).\n";
