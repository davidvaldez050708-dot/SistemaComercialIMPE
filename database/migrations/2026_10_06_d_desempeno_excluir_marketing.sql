-- Desempeño se limita a Analistas, Cuenta Clave y supervisión administrativa.
-- Marketing conserva sus métricas y reportes propios de convocatorias, pero no
-- participa en el ranking de desempeño operativo.

DELETE rp
FROM rol_permisos rp
INNER JOIN roles r
    ON r.id = rp.rol_id
INNER JOIN permisos p
    ON p.id = rp.permiso_id
WHERE r.nombre = 'Marketing'
  AND p.codigo IN (
      'desempeno.ver',
      'desempeno.ver_propio',
      'desempeno.ver_equipo',
      'desempeno.ver_global',
      'desempeno.exportar'
  );
