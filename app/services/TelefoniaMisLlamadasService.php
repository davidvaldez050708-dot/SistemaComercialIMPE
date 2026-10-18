<?php
/**
 * Bandeja personal de solo lectura.
 * Primera etapa: reusa la conciliación PBX ya existente, pero solo expone
 * tramos con atribución verificada al usuario autenticado.
 * No reemplaza Marketing, Seguimiento, Ventas ni expedientes.
 */
require_once __DIR__ . '/TelefoniaControlService.php';

final class TelefoniaMisLlamadasService
{
    public function consultar(int $usuarioId, array $filtros = []): array
    {
        if ($usuarioId <= 0) {
            throw new DomainException('Inicia sesión para consultar tus llamadas.');
        }
        $control = (new TelefoniaControlService())->consultar([
            'desde' => (string)($filtros['desde'] ?? date('Y-m-d', strtotime('-29 days'))),
            'hasta' => (string)($filtros['hasta'] ?? date('Y-m-d')),
            'usuario_id' => $usuarioId,
            'direccion' => in_array(($filtros['direccion'] ?? ''), ['Entrante','Saliente'], true)
                ? $filtros['direccion'] : ''
        ]);

        $rows = array_values(array_filter(
            $control['stats']['historial'] ?? [],
            static fn(array $item): bool =>
                (int)($item['usuario_id'] ?? 0) === $usuarioId
                && (string)($item['pbx_call_id'] ?? '') !== ''
        ));
        // Un identificador PBX puede tener varias extensiones: en esta vista,
        // cada fila es un tramo atribuido, no una nueva llamada externa.
        $total = count($rows);
        $conectadas = count(array_filter($rows, static fn(array $r): bool => !empty($r['conectada'])));
        return [
            'filas' => $rows,
            'total' => $total,
            'conectadas' => $conectadas,
            'entrantes' => count(array_filter($rows, static fn(array $r): bool => ($r['direccion'] ?? '') === 'Entrante')),
            'salientes' => count(array_filter($rows, static fn(array $r): bool => ($r['direccion'] ?? '') === 'Saliente')),
            'filtros' => $control['filtros'],
            'limitacion' => 'Esta vista inicial muestra hasta 500 tramos recientes con atribución verificada. Los eventos ambiguos y las grabaciones se consultan en sus módulos de origen.'
        ];
    }
}
