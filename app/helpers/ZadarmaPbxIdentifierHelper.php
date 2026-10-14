<?php

/**
 * Validación de IDs OPACOS de Zadarma.
 *
 * En /v1/statistics/pbx/ el campo call_id puede ser un número con punto,
 * p.ej. 1439981389.2702773. json_decode() lo convertiría en float, con
 * riesgo de pérdida irreversible de precisión. Preservar sus dígitos
 * textuales antes de decodificar; no reconstruir a partir de un float.
 */
final class ZadarmaPbxIdentifierHelper
{
    public static function pbxValido(string $id): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $id) === 1;
    }

    public static function callIdValido(string $id): bool
    {
        if (strlen($id) < 6 || strlen($id) > 100) {
            return false;
        }

        // Formato habitual alfanumérico o decimal exacto documentado.
        // Nunca aceptar rutas, query strings, espacios ni separadores URL.
        return preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $id) === 1 ||
            preg_match('/^[0-9]{1,40}\.[0-9]{1,40}$/D', $id) === 1;
    }

    public static function callIdDeFila(array $fila): string
    {
        $valor = $fila['call_id'] ?? null;
        // Los floats no conservan necesariamente todos los dígitos de la API.
        if (!is_string($valor) && !is_int($valor)) {
            return '';
        }
        $id = trim((string)$valor);
        return self::callIdValido($id) ? $id : '';
    }

    public static function decodificarEstadisticas(string $json): array
    {
        // Este reemplazo preserva únicamente campos call_id numéricos de la
        // respuesta JSON, antes de que PHP los convierta en float.
        // Los call_id ya entrecomillados y el resto de campos no se alteran.
        $normalizado = preg_replace_callback(
            '/"call_id"\s*:\s*([0-9]{1,40}\.[0-9]{1,40})(?=\s*[,}])/',
            static function (array $m): string {
                return '"call_id":"' . $m[1] . '"';
            },
            $json
        );
        if (!is_string($normalizado)) {
            throw new RuntimeException('No fue posible interpretar la respuesta de Zadarma.');
        }
        $respuesta = json_decode($normalizado, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($respuesta)) {
            throw new RuntimeException('La respuesta de Zadarma no contiene un objeto JSON.');
        }
        return $respuesta;
    }
}
