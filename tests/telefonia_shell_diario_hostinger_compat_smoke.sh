#!/usr/bin/env bash
# Emula EXACTAMENTE el shell publicado en Hostinger (SHA informado a039...)
# y verifica la fusión del diario WebRTC con la navegación estable.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
DEPLOY="tools/desplegar_estabilidad_shell_analista_hostinger.sh"
SHELL_PHP="app/views/telefonia/shell.php"
NEW="public/javascript/telefonia_shell_navegacion.js"
FILES=(
  "$SHELL_PHP"
  "public/javascript/telefonia_shell.js"
  "app/controllers/TelefoniaController.php"
  "public/css/telefonia_shell.css"
)
BASES=(
  "310fa2f2e4917583e8177456fed5ed909913dd6f"
  "6dc7b7fad51e5685bd63e1f13ac4f9a5cee7bb8f"
  "59f35c3ae14ebd20456d6b19f88baba6712a355e"
  "639992293946becae46c390daf0cb3184d392bfc"
)
TEMP="$(mktemp -d)"
trap 'rm -rf "$TEMP"' EXIT
LIVE="$TEMP/public_html"

for i in "${!FILES[@]}"; do
  file="${FILES[$i]}"
  mkdir -p "$LIVE/$(dirname "$file")"
  git show "${BASES[$i]}:$file" > "$LIVE/$file"
done

python3 - "$LIVE/$SHELL_PHP" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
emergency = """/* Visor de emergencia para timbrados SIP internos sin NOTIFY_INTERNAL.
   Sólo el Analista lo abre al pulsar el auricular; no altera Marketing. */
.impe-phone-call.is-analyst-native .impe-phone-call-help {
  display:block;height:auto;min-height:72px;padding:12px 15px;
  font-size:11px;line-height:1.55;
}
.impe-phone-call.is-analyst-native .impe-phone-call-host {height:calc(100% - 137px);}
.impe-phone-call.is-analyst-native.is-visible {height:min(560px,calc(100dvh - 24px));}
@media (max-width:575.98px) {
  .impe-phone-call.is-analyst-native.is-visible {height:min(560px,calc(100dvh - 12px));}
}

"""
assert s.count(emergency)==1
s=s.replace(emergency,"",1)

buttons = """                <?php if ($analistaEntrantesShell): ?>
                <button type="button" class="persistent-phone-action"
                    data-incoming-retry-save hidden>
                    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Reintentar registro
                </button>
                <button type="button" class="persistent-phone-action is-primary"
                    data-incoming-register-finished hidden>
                    <i class="bi bi-journal-check" aria-hidden="true"></i> Registrar resultado
                </button>
                <?php endif; ?>
"""
assert s.count(buttons)==1
s=s.replace(buttons,"",1)
journal = """    <!-- IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL -->
    <?php if ($analistaEntrantesShell): ?>
    <?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
    <script>
        window.IMPE_ANALYST_WEBRTC_JOURNAL = <?= json_encode([
            'enabled' => true,
            'endpoint' => BASE_URL . 'index.php?controller=seguimientoVinculacion&action=eventoEntranteWebrtc',
            'csrf' => (string)$_SESSION['csrf_token']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= BASE_URL ?>public/javascript/telefonia_analista_historial_pasivo.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/telefonia_analista_historial_pasivo.js') ?>"></script>
    <?php endif; ?>
"""
assert s.count("</body>")==1
s=s.replace("</body>",journal+"</body>",1)
p.write_text(s)
PY
SHA="$(git hash-object "$LIVE/$SHELL_PHP")"
[[ "$SHA" == "a039e4d61e4493a03fc739940a8b8e4f824e56e1" ]] || {
  echo "ERROR: fixture distinta del shell informado por Hostinger ($SHA)." >&2
  exit 1
}
export IMPE_SHELL_ESTABLE_SOURCE="$ROOT"
export IMPE_SHELL_ESTABLE_PUBLIC="$LIVE"
export IMPE_SHELL_ESTABLE_BACKUPS="$TEMP/backups"

bash "$DEPLOY" --check | tee "$TEMP/check" | grep -Fq "PRODUCCIÓN INTACTA"
grep -Fq "shell Hostinger conocido; diario WebRTC conservado" "$TEMP/check"
[[ ! -e "$LIVE/$NEW" ]]
[[ "$(git hash-object "$LIVE/$SHELL_PHP")" == "$SHA" ]]
bash "$DEPLOY" --apply | grep -Fq "shell telefónico estable publicado"
cmp "$SHELL_PHP" "$LIVE/$SHELL_PHP"
cmp "$NEW" "$LIVE/$NEW"
for token in \
  "IMPE_ANALYST_WEBRTC_PASSIVE_JOURNAL" \
  "telefonia_analista_historial_pasivo.js" \
  "data-incoming-register-finished" \
  "data-incoming-retry-save" \
  "data-shell-navigation-mask" \
  "impeShellNavigation" \
  "impeAnalystCallResultStyles"; do
  grep -Fq "$token" "$LIVE/$SHELL_PHP"
done
bash "$DEPLOY" --check | grep -Fq "ya publicada"
[[ -n "$(find "$TEMP/backups" -type f)" ]]
cmp -s "$TEMP/backups/$SHELL_PHP" <(git show "${BASES[0]}:$SHELL_PHP") && {
  echo "ERROR: respaldo debería conservar la variante real del usuario." >&2; exit 1;
}
[[ "$(git hash-object "$TEMP/backups/$SHELL_PHP")" == "$SHA" ]]

# Nunca aceptar otra variante sin comprobarla primero con el usuario.
echo "Compatibilidad diario WebRTC Hostinger: OK (SHA conocido, intacto en check, audio, botones, navegación, respaldo e idempotencia)."
