#!/usr/bin/env bash
set -euo pipefail

USO="Uso: bash tools/desplegar_prospectos_hostinger.sh --check|--apply"
if [[ "${1:-}" != "--check" && "${1:-}" != "--apply" ]]; then
  echo "$USO" >&2
  exit 2
fi
MODO="$1"
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ"

if [[ ! -d .git ]]; then
  echo "ERROR: Este directorio no es un repositorio Git: $RAIZ" >&2
  exit 1
fi
if [[ "$(git branch --show-current)" != "main" ]]; then
  echo "ERROR: Debes estar en la rama main." >&2
  exit 1
fi
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERROR: Existen modificaciones locales sin confirmar. Resuélvelas antes del despliegue." >&2
  exit 1
fi
echo "Proyecto: $RAIZ"
echo "Modo: $MODO"
echo "Consultando origin/main..."
git fetch origin main

ARCHIVOS=(
  index.php
  app/controllers/ProspectoController.php
  app/models/ProspectoModel.php
  app/views/prospectos/index.php
  app/views/layout/sidebar.php
)

for archivo in "${ARCHIVOS[@]}"; do
  if ! git cat-file -e "origin/main:$archivo" 2>/dev/null; then
    echo "ERROR: No se encuentra en origin/main: $archivo" >&2
    exit 1
  fi
done
echo "OK: Archivos del módulo disponibles en origin/main."

if [[ "$MODO" == "--check" ]]; then
  echo "Versión local: $(git rev-parse --short HEAD)"
  echo "Versión remota: $(git rev-parse --short origin/main)"
  if git merge-base --is-ancestor HEAD origin/main; then
    echo "OK: Se puede intentar actualización de avance rápido."
  else
    echo "ATENCIÓN: La rama local diverge de origin/main; --apply no podrá actualizar con --ff-only." >&2
    exit 1
  fi
  echo "Comprobación terminada. No se modificaron archivos de la aplicación."
  exit 0
fi

echo "Actualizando main mediante avance rápido..."
git pull --ff-only origin main
if ! command -v php >/dev/null 2>&1; then
  echo "ADVERTENCIA: No se encontró PHP CLI para comprobar sintaxis." >&2
else
  for archivo in "${ARCHIVOS[@]}"; do
    php -l "$archivo"
  done
fi
echo "OK: Módulo Prospectos actualizado en $(git rev-parse --short HEAD)."
echo "Abre el sistema con rol Marketing y selecciona Prospectos."
