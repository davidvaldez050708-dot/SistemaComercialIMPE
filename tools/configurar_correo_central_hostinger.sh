#!/usr/bin/env bash
# Configura y valida la cuenta CENTRAL de salida de correo en Hostinger.
# Los usuarios finales no configuran SMTP.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" && "$MODE" != "--test" ]]; then
  echo "Uso: bash tools/configurar_correo_central_hostinger.sh --check|--apply|--test" >&2
  exit 2
fi

ROOT="${IMPE_CORREO_CENTRAL_ROOT:-$PWD}"
if [[ ! -f "$ROOT/index.php" ]]; then
  ROOT="${IMPE_CORREO_CENTRAL_ROOT:-$HOME/crm-preparacion}"
fi

if [[ ! -f "$ROOT/index.php" ]]; then
  echo "ERROR: no se encontró la raíz del proyecto." >&2
  exit 1
fi

PUBLIC_DIR="${IMPE_CORREO_CENTRAL_PUBLIC_DIR:-}"
if [[ -z "$PUBLIC_DIR" ]]; then
  for candidate in     "$HOME/domains/fundacionrededumex.org/public_html"     "$HOME/domains/mediumturquoise-starling-552985.hostingersite.com/public_html"     "$HOME/public_html"
  do
    if [[ -f "$candidate/index.php" ]]; then
      PUBLIC_DIR="$candidate"
      break
    fi
  done
fi

if [[ -z "$PUBLIC_DIR" || ! -f "$PUBLIC_DIR/index.php" ]]; then
  echo "ERROR: no se encontró la instalación pública." >&2
  exit 1
fi

PRIVATE_DIR="$(dirname "$PUBLIC_DIR")/impe-private"
CONFIG_FILE="$PRIVATE_DIR/mail_config.local.php"

read_config_value() {
  local key="$1"
  if [[ ! -f "$CONFIG_FILE" ]]; then
    return 0
  fi
  php -r '
    $f=$argv[1]; $k=$argv[2];
    require $f;
    if (defined($k)) echo constant($k);
  ' "$CONFIG_FILE" "$key" 2>/dev/null || true
}

check_config() {
  local host user from pass
  host="$(read_config_value MAIL_HOST)"
  user="$(read_config_value MAIL_USERNAME)"
  from="$(read_config_value MAIL_FROM_ADDRESS)"
  pass="$(read_config_value MAIL_PASSWORD)"

  echo "Configuración privada: $CONFIG_FILE"
  [[ -f "$CONFIG_FILE" ]] && echo "[OK] Archivo privado presente." || echo "[FALTA] Archivo privado no existe."
  [[ -n "$host" ]] && echo "[OK] MAIL_HOST=$host" || echo "[FALTA] MAIL_HOST"
  [[ -n "$user" ]] && echo "[OK] MAIL_USERNAME=$user" || echo "[FALTA] MAIL_USERNAME"
  [[ -n "$from" ]] && echo "[OK] MAIL_FROM_ADDRESS=$from" || echo "[FALTA] MAIL_FROM_ADDRESS"
  [[ -n "$pass" ]] && echo "[OK] MAIL_PASSWORD configurada." || echo "[FALTA] MAIL_PASSWORD"

  if [[ -f "$CONFIG_FILE" && -n "$host" && -n "$user" && -n "$from" && -n "$pass" ]]; then
    return 0
  fi
  return 1
}

test_smtp() {
  local host port user pass enc from
  host="$(read_config_value MAIL_HOST)"
  port="$(read_config_value MAIL_PORT)"
  user="$(read_config_value MAIL_USERNAME)"
  pass="$(read_config_value MAIL_PASSWORD)"
  enc="$(read_config_value MAIL_ENCRYPTION)"
  from="$(read_config_value MAIL_FROM_ADDRESS)"

  [[ -n "$port" ]] || port="587"
  [[ -n "$enc" ]] || enc="tls"

  if [[ -z "$host" || -z "$user" || -z "$pass" || -z "$from" ]]; then
    echo "ERROR: configuración central incompleta." >&2
    return 1
  fi

  php -r '
    $root=$argv[1];
    $host=$argv[2];
    $port=(int)$argv[3];
    $user=$argv[4];
    $pass=$argv[5];
    $enc=$argv[6];

    require $root . "/vendor/autoload.php";
    $m = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
      $m->isSMTP();
      $m->Host=$host;
      $m->Port=$port;
      $m->SMTPAuth=true;
      $m->Username=$user;
      $m->Password=$pass;
      $m->Timeout=15;
      $m->Timelimit=15;
      if ($enc === "ssl") {
        $m->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
      } elseif ($enc === "tls") {
        $m->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      } else {
        $m->SMTPAutoTLS=false;
        $m->SMTPSecure=false;
      }
      if (!$m->smtpConnect()) {
        fwrite(STDERR, "[ERROR] No fue posible conectar/autenticar con SMTP.\n");
        exit(1);
      }
      $m->smtpClose();
      echo "[OK] Conexión y autenticación SMTP correctas.\n";
    } catch (Throwable $e) {
      fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
      exit(1);
    }
  ' "$PUBLIC_DIR" "$host" "$port" "$user" "$pass" "$enc"
}

if [[ "$MODE" == "--check" ]]; then
  if check_config; then
    echo
    echo "OK: la cuenta central está configurada."
    exit 0
  fi
  echo
  echo "PENDIENTE: falta configurar la cuenta central de salida."
  exit 1
fi

if [[ "$MODE" == "--test" ]]; then
  check_config || true
  echo
  test_smtp
  exit $?
fi

mkdir -p "$PRIVATE_DIR"
chmod 0700 "$PRIVATE_DIR"

HOST="${IMPE_MAIL_HOST:-smtp.hostinger.com}"
PORT="${IMPE_MAIL_PORT:-587}"
ENC="${IMPE_MAIL_ENCRYPTION:-tls}"
FROM_NAME="${IMPE_MAIL_FROM_NAME:-Fundación Red Educativa México}"

if [[ -n "${IMPE_MAIL_USERNAME:-}" ]]; then
  USERNAME="$IMPE_MAIL_USERNAME"
else
  read -r -p "Correo central de salida (ej. no-reply@tudominio.org): " USERNAME
fi

if [[ -n "${IMPE_MAIL_FROM_ADDRESS:-}" ]]; then
  FROM_ADDRESS="$IMPE_MAIL_FROM_ADDRESS"
else
  FROM_ADDRESS="$USERNAME"
fi

if [[ -n "${IMPE_MAIL_PASSWORD:-}" ]]; then
  PASSWORD="$IMPE_MAIL_PASSWORD"
else
  read -r -s -p "Contraseña de la cuenta central: " PASSWORD
  echo
fi

if [[ -z "$USERNAME" || -z "$PASSWORD" ]]; then
  echo "ERROR: correo y contraseña son obligatorios." >&2
  exit 1
fi

escape_php() {
  php -r 'echo addslashes($argv[1]);' "$1"
}

H_E="$(escape_php "$HOST")"
U_E="$(escape_php "$USERNAME")"
P_E="$(escape_php "$PASSWORD")"
E_E="$(escape_php "$ENC")"
F_E="$(escape_php "$FROM_ADDRESS")"
N_E="$(escape_php "$FROM_NAME")"

cat > "$CONFIG_FILE" <<PHP
<?php
define('MAIL_HOST', '$H_E');
define('MAIL_PORT', $PORT);
define('MAIL_USERNAME', '$U_E');
define('MAIL_PASSWORD', '$P_E');
define('MAIL_ENCRYPTION', '$E_E');
define('MAIL_FROM_ADDRESS', '$F_E');
define('MAIL_FROM_NAME', '$N_E');
PHP

chmod 0600 "$CONFIG_FILE"
echo "[OK] Configuración central guardada fuera de public_html."
echo
test_smtp
echo
echo "OK: cuenta central lista para los envíos del sistema."
