#!/usr/bin/env bash
# Reinicia SOLO la configuración de salida de Correos de Marketing.
# No modifica la configuración de correo de otros perfiles/módulos.
set -euo pipefail
umask 077

MODE="${1:---check}"

if [[ "$MODE" != "--check" && "$MODE" != "--apply" && "$MODE" != "--test" ]]; then
  echo "Uso: bash tools/reiniciar_correos_marketing_hostinger.sh --check|--apply|--test" >&2
  exit 2
fi

SRC="${IMPE_MARKETING_SOURCE_DIR:-$PWD}"
if [[ ! -f "$SRC/index.php" ]]; then
  SRC="${IMPE_MARKETING_SOURCE_DIR:-$HOME/crm-preparacion}"
fi

if [[ ! -f "$SRC/index.php" ]]; then
  echo "ERROR: no se encontró la raíz del proyecto." >&2
  exit 1
fi

if [[ -n "${IMPE_MARKETING_PUBLIC_DIR:-}" ]]; then
  PUBLIC_DIR="$IMPE_MARKETING_PUBLIC_DIR"
else
  PUBLIC_DIR=""
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
MARKETING_CONFIG="$PRIVATE_DIR/marketing_mail.local.php"
GLOBAL_HOSTINGER="$PRIVATE_DIR/hostinger_mail_config.php"
GLOBAL_SMTP="$PRIVATE_DIR/mail_config.local.php"

read_constant() {
  local file="$1"
  local key="$2"

  if [[ ! -f "$file" ]]; then
    return 0
  fi

  php -r '
    $f=$argv[1]; $k=$argv[2];
    require $f;
    if (defined($k)) echo (string)constant($k);
  ' "$file" "$key" 2>/dev/null || true
}

read_marketing_value() {
  local key="$1"

  if [[ ! -f "$MARKETING_CONFIG" ]]; then
    return 0
  fi

  php -r '
    $f=$argv[1]; $k=$argv[2];
    $d=require $f;
    if (is_array($d) && array_key_exists($k,$d)) {
      echo is_scalar($d[$k]) ? (string)$d[$k] : "";
    }
  ' "$MARKETING_CONFIG" "$key" 2>/dev/null || true
}

show_check() {
  local api_token api_from smtp_user smtp_from smtp_host

  api_token="$(read_marketing_value hostinger_mail_api_token)"
  api_from="$(read_marketing_value hostinger_from_address)"
  smtp_host="$(read_marketing_value smtp_host)"
  smtp_user="$(read_marketing_value smtp_username)"
  smtp_from="$(read_marketing_value smtp_from_address)"

  echo "Perfil revisado      : Marketing"
  echo "Instalación pública  : $PUBLIC_DIR"
  echo "Configuración privada: $MARKETING_CONFIG"
  echo

  [[ -f "$MARKETING_CONFIG" ]]     && echo "[OK] Configuración exclusiva de Marketing presente."     || echo "[FALTA] Configuración exclusiva de Marketing."

  [[ -n "$api_token" ]]     && echo "[OK] Hostinger Mail API configurada."     || echo "[INFO] Hostinger Mail API no configurada para Marketing."

  [[ -n "$api_from" ]]     && echo "[OK] Buzón Hostinger preferido: $api_from"     || echo "[INFO] Sin buzón Hostinger preferido; se valida el correo escrito en De."

  if [[ -n "$smtp_user" && -n "$smtp_from" && -n "$smtp_host" ]]; then
    echo "[OK] SMTP central de Marketing: $smtp_from ($smtp_host)"
  else
    echo "[INFO] SMTP central de Marketing no configurado."
  fi

  if [[ -n "$api_token" || ( -n "$smtp_user" && -n "$smtp_from" && -n "$smtp_host" ) ]]; then
    echo
    echo "OK: Marketing tiene al menos un transporte de correo configurado."
    return 0
  fi

  echo
  echo "PENDIENTE: Marketing no tiene un transporte de salida configurado."
  return 1
}

test_api() {
  local token base
  token="$(read_marketing_value hostinger_mail_api_token)"
  base="$(read_marketing_value hostinger_mail_api_base_url)"
  [[ -n "$base" ]] || base="https://api.mail.hostinger.com"

  if [[ -z "$token" ]]; then
    echo "[INFO] Hostinger Mail API: no configurada."
    return 0
  fi

  echo "Probando Hostinger Mail API..."
  RESPONSE="$(curl -sS -w $'\n%{http_code}'     -H "Accept: application/json"     -H "Authorization: Bearer $token"     "$base/api/v1/me" || true)"

  HTTP_CODE="$(printf '%s\n' "$RESPONSE" | tail -n1)"
  BODY="$(printf '%s\n' "$RESPONSE" | sed '$d')"

  if [[ "$HTTP_CODE" != "200" ]]; then
    echo "[ERROR] Hostinger Mail API respondió HTTP $HTTP_CODE."
    return 1
  fi

  echo "[OK] Hostinger Mail API autenticada."
  php -r '
    $j=json_decode(stream_get_contents(STDIN),true);
    $m=$j["data"]["mailboxes"] ?? $j["mailboxes"] ?? [];
    echo "Buzones autorizados:" . PHP_EOL;
    foreach ((array)$m as $b) {
      $a=trim((string)($b["address"] ?? ""));
      if ($a!=="") echo "  - ".$a.PHP_EOL;
    }
  ' <<< "$BODY"
}

test_smtp() {
  local host port enc user pass from
  host="$(read_marketing_value smtp_host)"
  port="$(read_marketing_value smtp_port)"
  enc="$(read_marketing_value smtp_encryption)"
  user="$(read_marketing_value smtp_username)"
  pass="$(read_marketing_value smtp_password)"
  from="$(read_marketing_value smtp_from_address)"

  if [[ -z "$host" || -z "$user" || -z "$pass" || -z "$from" ]]; then
    echo "[INFO] SMTP Marketing: no configurado."
    return 0
  fi

  [[ -n "$port" ]] || port="587"
  [[ -n "$enc" ]] || enc="tls"

  echo "Probando SMTP de Marketing ($from)..."

  php -r '
    $root=$argv[1];
    $host=$argv[2];
    $port=(int)$argv[3];
    $user=$argv[4];
    $pass=$argv[5];
    $enc=$argv[6];

    require $root . "/vendor/autoload.php";
    $m=new PHPMailer\PHPMailer\PHPMailer(true);

    try {
      $m->isSMTP();
      $m->Host=$host;
      $m->Port=$port;
      $m->SMTPAuth=true;
      $m->Username=$user;
      $m->Password=$pass;
      $m->Timeout=15;
      $m->Timelimit=15;

      if ($enc==="ssl") {
        $m->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
      } elseif ($enc==="tls") {
        $m->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
      } else {
        $m->SMTPAutoTLS=false;
        $m->SMTPSecure=false;
      }

      if (!$m->smtpConnect()) {
        fwrite(STDERR,"[ERROR] No fue posible conectar/autenticar SMTP.\n");
        exit(1);
      }

      $m->smtpClose();
      echo "[OK] SMTP de Marketing autenticado.\n";
    } catch (Throwable $e) {
      fwrite(STDERR,"[ERROR] ".$e->getMessage()."\n");
      exit(1);
    }
  ' "$PUBLIC_DIR" "$host" "$port" "$user" "$pass" "$enc"
}

if [[ "$MODE" == "--check" ]]; then
  show_check
  exit $?
fi

if [[ "$MODE" == "--test" ]]; then
  show_check || true
  echo
  api_rc=0
  smtp_rc=0
  test_api || api_rc=$?
  echo
  test_smtp || smtp_rc=$?
  echo

  if [[ "$api_rc" -eq 0 && "$smtp_rc" -eq 0 ]]; then
    echo "OK: pruebas de correo de Marketing terminadas."
    exit 0
  fi

  echo "ERROR: una de las pruebas de Marketing falló."
  exit 1
fi

mkdir -p "$PRIVATE_DIR"
chmod 0700 "$PRIVATE_DIR"

echo "Reiniciando SOLO la configuración de Correos de Marketing..."
echo

# Reutiliza configuración privada existente cuando ya estaba funcionando.
HOSTINGER_TOKEN="$(read_constant "$GLOBAL_HOSTINGER" HOSTINGER_MAIL_API_TOKEN)"
HOSTINGER_BASE="$(read_constant "$GLOBAL_HOSTINGER" HOSTINGER_MAIL_API_BASE_URL)"
HOSTINGER_FROM="$(read_constant "$GLOBAL_HOSTINGER" HOSTINGER_MAIL_FROM_ADDRESS)"

SMTP_HOST="$(read_constant "$GLOBAL_SMTP" MAIL_HOST)"
SMTP_PORT="$(read_constant "$GLOBAL_SMTP" MAIL_PORT)"
SMTP_USER="$(read_constant "$GLOBAL_SMTP" MAIL_USERNAME)"
SMTP_PASS="$(read_constant "$GLOBAL_SMTP" MAIL_PASSWORD)"
SMTP_ENC="$(read_constant "$GLOBAL_SMTP" MAIL_ENCRYPTION)"
SMTP_FROM="$(read_constant "$GLOBAL_SMTP" MAIL_FROM_ADDRESS)"
SMTP_NAME="$(read_constant "$GLOBAL_SMTP" MAIL_FROM_NAME)"

[[ -n "$HOSTINGER_BASE" ]] || HOSTINGER_BASE="https://api.mail.hostinger.com"
[[ -n "$SMTP_PORT" ]] || SMTP_PORT="587"
[[ -n "$SMTP_ENC" ]] || SMTP_ENC="tls"
[[ -n "$SMTP_NAME" ]] || SMTP_NAME="Sistema Comercial"

if [[ -z "$HOSTINGER_TOKEN" ]]; then
  read -r -s -p "Token de Hostinger Mail API para Marketing (Enter para omitir): " HOSTINGER_TOKEN
  echo
fi

if [[ -z "$SMTP_USER" ]]; then
  read -r -p "Correo SMTP central de Marketing (Enter para omitir): " SMTP_USER
  if [[ -n "$SMTP_USER" ]]; then
    SMTP_FROM="$SMTP_USER"

    if [[ "$SMTP_USER" == *@gmail.com || "$SMTP_USER" == *@googlemail.com ]]; then
      SMTP_HOST="smtp.gmail.com"
      SMTP_PORT="587"
      SMTP_ENC="tls"
    elif [[ "$SMTP_USER" == *@outlook.com || "$SMTP_USER" == *@hotmail.com || "$SMTP_USER" == *@live.com ]]; then
      SMTP_HOST="smtp-mail.outlook.com"
      SMTP_PORT="587"
      SMTP_ENC="tls"
    else
      [[ -n "$SMTP_HOST" ]] || SMTP_HOST="smtp.hostinger.com"
    fi

    read -r -s -p "Contraseña/contraseña de aplicación de $SMTP_USER: " SMTP_PASS
    echo
  fi
fi

escape_php() {
  php -r 'echo addslashes($argv[1]);' "$1"
}

T="$(escape_php "$HOSTINGER_TOKEN")"
B="$(escape_php "$HOSTINGER_BASE")"
HF="$(escape_php "$HOSTINGER_FROM")"
SH="$(escape_php "$SMTP_HOST")"
SE="$(escape_php "$SMTP_ENC")"
SU="$(escape_php "$SMTP_USER")"
SP="$(escape_php "$SMTP_PASS")"
SF="$(escape_php "$SMTP_FROM")"
SN="$(escape_php "$SMTP_NAME")"

cat > "$MARKETING_CONFIG" <<PHP
<?php

// Configuración privada EXCLUSIVA del perfil Marketing.
// No publicar ni subir a Git.
return [
    'hostinger_mail_api_token' => '$T',
    'hostinger_mail_api_base_url' => '$B',
    'hostinger_from_address' => '$HF',

    'smtp_host' => '$SH',
    'smtp_port' => $SMTP_PORT,
    'smtp_encryption' => '$SE',
    'smtp_username' => '$SU',
    'smtp_password' => '$SP',
    'smtp_from_address' => '$SF',
    'smtp_from_name' => '$SN',
];
PHP

chmod 0600 "$MARKETING_CONFIG"

echo
echo "[OK] Configuración exclusiva de Marketing recreada."
echo
show_check || true
echo
echo "Ejecuta ahora:"
echo "  bash tools/reiniciar_correos_marketing_hostinger.sh --test"
