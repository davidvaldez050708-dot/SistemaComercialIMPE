<?php
/** Decide el alcance, nunca concede permisos ni sustituye al Administrador. */
function esRolSupervisionNacional(?int $rolId = null): bool
{
    return in_array($rolId ?? (int)($_SESSION['rol_id'] ?? 0), [1, 2], true);
}

/** Gobierno del sistema reservado; el reporte de usuarios también admite Dirección. */
function permisoReservadoParaRol(int $rolId, string $codigo): bool
{
    if ($rolId === 1) return false;
    if ($codigo === 'reportes.usuarios') return $rolId !== 2;
    return in_array($codigo, ['whatsapp.gestionar_cuentas', 'telefonia.configurar'], true);
}
