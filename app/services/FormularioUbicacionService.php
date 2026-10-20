<?php
/** La modalidad procede exclusivamente del aliado resuelto en el servidor. */
class FormularioUbicacionService
{
    public static function validar(array $datos, bool $internacional, ?mysqli $db = null): array
    {
        if ($internacional) {
            if (!in_array($datos['estado_id'] ?? null, [null, '', 0, '0'], true) || !in_array($datos['municipio_id'] ?? null, [null, '', 0, '0'], true)) {
                throw new DomainException('No envíes identificadores mexicanos en un formulario internacional.');
            }
            foreach (['pais_nombre', 'estado_provincia', 'ciudad'] as $campo) {
                $valor = $datos[$campo] ?? '';
                if (!is_string($valor) || mb_strlen(trim($valor)) < 2 || mb_strlen($valor) > 120 || preg_match('/[\x00-\x1f<>]/u', $valor)) {
                    throw new DomainException('Ingresa país, estado o provincia y ciudad válidos (2 a 120 caracteres).');
                }
                $datos[$campo] = trim($valor);
            }
            $datos['estado_id'] = null;
            $datos['municipio_id'] = null;
        } else {
            foreach (['estado_id', 'municipio_id'] as $campo) {
                if (!is_scalar($datos[$campo] ?? null) || !ctype_digit((string)$datos[$campo]) || (int)$datos[$campo] <= 0) {
                    throw new DomainException('Selecciona estado y municipio mexicanos validos.');
                }
            }
            if ($db) {
                $s = $db->prepare('SELECT m.id FROM municipios m INNER JOIN estados e ON e.id=m.estado_id WHERE m.id=? AND e.id=? AND m.estado=1 AND e.estado=1');
                $s->bind_param('ii', $datos['municipio_id'], $datos['estado_id']);
                $s->execute();
                if (!$s->get_result()->fetch_assoc()) { throw new DomainException('Selecciona estado y municipio mexicanos válidos.'); }
            }
            $datos['pais_nombre'] = null;
            $datos['estado_provincia'] = null;
            $datos['ciudad'] = null;
        }
        return $datos;
    }
}
