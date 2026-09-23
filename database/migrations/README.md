# Migraciones de base de datos

El sistema usa los archivos `.sql` de esta carpeta como historial incremental.
No agregues cambios nuevos directamente al respaldo base sin conservar también
su migración correspondiente.

## Base existente de desarrollo

La base que ya venía usando el proyecto tenía aplicados manualmente los cambios
hasta el **21 de septiembre de 2026**. Para adoptarla al versionado sin repetir
DDL ni datos:

```powershell
C:\xampp\php\php.exe tools\migrar.php --baseline=2026_09_21
C:\xampp\php\php.exe tools\migrar.php --status
C:\xampp\php\php.exe tools\migrar.php --run
```

El baseline **no ejecuta SQL**. Solo registra como ya aplicadas las migraciones
hasta la fecha indicada. Después, `--run` ejecuta únicamente las pendientes.

## Instalación nueva

1. Crear la base e importar `database/sistema_comercial_impe.sql`.
2. Ejecutar:

```powershell
C:\xampp\php\php.exe tools\migrar.php --run
```

En una instalación nueva no debe usarse `--baseline`.

## Comandos

- `--status`: muestra migraciones aplicadas, pendientes o modificadas.
- `--baseline=AAAA-MM-DD`: adopta una base existente hasta una fecha sin ejecutar SQL.
- `--run`: aplica las migraciones pendientes en orden alfabético.

El runner crea y mantiene la tabla `sistema_migraciones`, donde guarda nombre,
checksum SHA-256, lote y fecha de aplicación. Si un archivo aplicado cambia,
`--run` se detiene para evitar que el historial deje de ser reproducible.

## Regla para cambios futuros

Cada cambio de estructura o dato base debe agregarse como un archivo nuevo:

```
AAAA_MM_DD_descripcion_corta.sql
```

Las migraciones ya aplicadas no deben editarse; se crea una nueva migración para
corregir o ampliar el esquema.
