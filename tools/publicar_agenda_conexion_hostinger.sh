#!/usr/bin/env bash
# Sólo recuperación de lectura/errores en dos JS de Agenda. Sin SQL ni envíos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-conexion.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
<?php
// Publicación selectiva. Sólo archivos listados; ninguna consulta SQL ni envío.
if (PHP_SAPI !== 'cli') { exit(1); }
$version = 'agenda-conexion-comentarios-v1';
$files = json_decode(base64_decode('W3sicGF0aCI6InB1YmxpYy9qYXZhc2NyaXB0L2FnZW5kYV9jb21lbnRhcmlvcy5qcyIsImZyYWdtZW50cyI6W3siYmVmb3JlIjoiICAgICAgICAgICAgaWYgKHZhbHVlICE9PSB1bmRlZmluZWQpIGVsLnRleHRDb250ZW50ID0gdmFsdWU7XG4gICAgICAgICAgICByZXR1cm4gZWw7XG4gICAgICAgIH07XG4gICAgICAgIGNvbnN0IGluZm9ybSA9IChtZXNzYWdlLCBmYWlsdXJlID0gZmFsc2UpID0+IHtcbiAgICAgICAgICAgIHN0YXR1cy50ZXh0Q29udGVudCA9IG1lc3NhZ2U7XG4gICAgICAgICAgICBzdGF0dXMuaGlkZGVuID0gIW1lc3NhZ2U7XG4gICAgICAgICAgICBzdGF0dXMuY2xhc3NMaXN0LnRvZ2dsZSgnaXMtZXJyb3InLCBmYWlsdXJlKTtcbiIsImFmdGVyIjoiICAgICAgICAgICAgaWYgKHZhbHVlICE9PSB1bmRlZmluZWQpIGVsLnRleHRDb250ZW50ID0gdmFsdWU7XG4gICAgICAgICAgICByZXR1cm4gZWw7XG4gICAgICAgIH07XG4gICAgICAgIGxldCBsb2FkU3RhdHVzID0gZmFsc2U7XG4gICAgICAgIGNvbnN0IGluZm9ybSA9IChtZXNzYWdlLCBmYWlsdXJlID0gZmFsc2UsIGZyb21Mb2FkID0gZmFsc2UpID0+IHtcbiAgICAgICAgICAgIGxvYWRTdGF0dXMgPSBmcm9tTG9hZDtcbiAgICAgICAgICAgIHN0YXR1cy50ZXh0Q29udGVudCA9IG1lc3NhZ2U7XG4gICAgICAgICAgICBzdGF0dXMuaGlkZGVuID0gIW1lc3NhZ2U7XG4gICAgICAgICAgICBzdGF0dXMuY2xhc3NMaXN0LnRvZ2dsZSgnaXMtZXJyb3InLCBmYWlsdXJlKTtcbiJ9LHsiYmVmb3JlIjoiICAgICAgICB9XG4gICAgICAgIGFzeW5jIGZ1bmN0aW9uIHJlcXVlc3QoYWN0aW9uLCBkYXRhID0gbnVsbCkge1xuICAgICAgICAgICAgY29uc3QgdXJsID0gZW5kcG9pbnQoYWN0aW9uKTtcbiAgICAgICAgICAgIGNvbnN0IG9wdGlvbnMgPSB7IGNhY2hlOiduby1zdG9yZScsIGNyZWRlbnRpYWxzOidzYW1lLW9yaWdpbicsIGhlYWRlcnM6eyAnWC1SZXF1ZXN0ZWQtV2l0aCc6J1hNTEh0dHBSZXF1ZXN0JyB9IH07XG4gICAgICAgICAgICBpZiAoZGF0YSkge1xuICAgICAgICAgICAgICAgIG9wdGlvbnMubWV0aG9kID0gJ1BPU1QnO1xuICAgICAgICAgICAgICAgIG9wdGlvbnMuaGVhZGVyc1snWC1DU1JGLVRva2VuJ10gPSBTdHJpbmcod2luZG93LklNUEVfQ1NSRl9UT0tFTiB8fCAnJyk7XG4iLCJhZnRlciI6IiAgICAgICAgfVxuICAgICAgICBhc3luYyBmdW5jdGlvbiByZXF1ZXN0KGFjdGlvbiwgZGF0YSA9IG51bGwpIHtcbiAgICAgICAgICAgIGNvbnN0IHVybCA9IGVuZHBvaW50KGFjdGlvbik7XG4gICAgICAgICAgICBjb25zdCBvcHRpb25zID0geyBjYWNoZTonbm8tc3RvcmUnLCBjcmVkZW50aWFsczonc2FtZS1vcmlnaW4nLCBoZWFkZXJzOnsgJ1gtUmVxdWVzdGVkLVdpdGgnOidmZXRjaCcgfSB9O1xuICAgICAgICAgICAgaWYgKGRhdGEpIHtcbiAgICAgICAgICAgICAgICBvcHRpb25zLm1ldGhvZCA9ICdQT1NUJztcbiAgICAgICAgICAgICAgICBvcHRpb25zLmhlYWRlcnNbJ1gtQ1NSRi1Ub2tlbiddID0gU3RyaW5nKHdpbmRvdy5JTVBFX0NTUkZfVE9LRU4gfHwgJycpO1xuIn0seyJiZWZvcmUiOiIgICAgICAgICAgICAgICAgdXJsLnNlYXJjaFBhcmFtcy5zZXQoJ2Rlc2RlJywgc3RhcnQgfHwgJycpO1xuICAgICAgICAgICAgICAgIHVybC5zZWFyY2hQYXJhbXMuc2V0KCdoYXN0YScsIGVuZC5nZXRGdWxsWWVhcigpKyctJytTdHJpbmcoZW5kLmdldE1vbnRoKCkrMSkucGFkU3RhcnQoMiwnMCcpKyctJytTdHJpbmcoZW5kLmdldERhdGUoKSkucGFkU3RhcnQoMiwnMCcpKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGNvbnN0IHJlc3BvbnNlID0gYXdhaXQgZmV0Y2godXJsLCBvcHRpb25zKTtcbiAgICAgICAgICAgIGxldCByZXN1bHQ7XG4gICAgICAgICAgICB0cnkgeyByZXN1bHQgPSBhd2FpdCByZXNwb25zZS5qc29uKCk7IH0gY2F0Y2ggKF8pIHsgdGhyb3cgbmV3IEVycm9yKCdObyBmdWUgcG9zaWJsZSBjYXJnYXIgbG9zIGNvbWVudGFyaW9zLiBJbnRlbnRhIG51ZXZhbWVudGUuJyk7IH1cbiAgICAgICAgICAgIGlmICghcmVzcG9uc2Uub2sgfHwgIXJlc3VsdC5vaykgdGhyb3cgbmV3IEVycm9yKHJlc3VsdC5tZW5zYWplIHx8ICdObyBmdWUgcG9zaWJsZSBwcm9jZXNhciBlbCBjb21lbnRhcmlvLicpO1xuICAgICAgICAgICAgcmV0dXJuIHJlc3VsdDtcbiAgICAgICAgfVxuICAgICAgICBjb25zdCByZWZyZXNoQmVsbCA9IG93bklkID0+IHdpbmRvdy5kaXNwYXRjaEV2ZW50KG5ldyBDdXN0b21FdmVudCgnaW1wZTphZ2VuZGEtY29tbWVudHMtcmVmcmVzaCcse1xuICAgICAgICAgICAgZGV0YWlsOntjb21lbnRhcmlvUHJvcGlvSWQ6TnVtYmVyKG93bklkKXx8MH1cbiIsImFmdGVyIjoiICAgICAgICAgICAgICAgIHVybC5zZWFyY2hQYXJhbXMuc2V0KCdkZXNkZScsIHN0YXJ0IHx8ICcnKTtcbiAgICAgICAgICAgICAgICB1cmwuc2VhcmNoUGFyYW1zLnNldCgnaGFzdGEnLCBlbmQuZ2V0RnVsbFllYXIoKSsnLScrU3RyaW5nKGVuZC5nZXRNb250aCgpKzEpLnBhZFN0YXJ0KDIsJzAnKSsnLScrU3RyaW5nKGVuZC5nZXREYXRlKCkpLnBhZFN0YXJ0KDIsJzAnKSk7XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBjb25zdCBmYWlsdXJlID0gKG1lc3NhZ2UsIHJldHJ5YWJsZSA9IGZhbHNlLCBzdGF0dXMgPSAwKSA9PiBPYmplY3QuYXNzaWduKG5ldyBFcnJvcihtZXNzYWdlKSwge3JldHJ5YWJsZSxzdGF0dXN9KTtcbiAgICAgICAgICAgIGNvbnN0IG9mZmxpbmVNZXNzYWdlID0gJ05vIGhheSBjb25leGnDs24gZW4gZXN0ZSBtb21lbnRvLiBMb3MgY29tZW50YXJpb3Mgc2UgY2FyZ2Fyw6FuIGFsIHJlY3VwZXJhcmxhLic7XG4gICAgICAgICAgICBjb25zdCB1bmNlcnRhaW5NZXNzYWdlID0gJ05vIHNlIHB1ZG8gY29tcHJvYmFyIGxhIG9wZXJhY2nDs24uIENvbnNlcnZhIGVsIHRleHRvIHkgcmV2aXNhIGxhIGFnZW5kYSBhbnRlcyBkZSB2b2x2ZXIgYSBpbnRlbnRhcmxvLic7XG4gICAgICAgICAgICBjb25zdCB0cmFuc2llbnRNZXNzYWdlID0gJ05vIHNlIHB1ZGllcm9uIGNhcmdhciBsb3MgY29tZW50YXJpb3MuIFNlIHZvbHZlcsOhIGEgaW50ZW50YXIgYXV0b23DoXRpY2FtZW50ZS4nO1xuICAgICAgICAgICAgY29uc3QgYXR0ZW1wdHMgPSBkYXRhID8gMSA6IDM7XG4gICAgICAgICAgICBmb3IgKGxldCBhdHRlbXB0PTA7IGF0dGVtcHQ8YXR0ZW1wdHM7IGF0dGVtcHQrKykge1xuICAgICAgICAgICAgICAgIGlmIChuYXZpZ2F0b3Iub25MaW5lID09PSBmYWxzZSkgdGhyb3cgZmFpbHVyZShkYXRhID8gJ05vIGhheSBjb25leGnDs24uIENvbnNlcnZhIGVsIHRleHRvIGUgaW50w6ludGFsbyBjdWFuZG8gc2UgcmVzdGFibGV6Y2EuJyA6IG9mZmxpbmVNZXNzYWdlLHRydWUpO1xuICAgICAgICAgICAgICAgIGNvbnN0IGNvbnRyb2xsZXIgPSBuZXcgQWJvcnRDb250cm9sbGVyKCk7XG4gICAgICAgICAgICAgICAgY29uc3QgdGltZW91dCA9IHdpbmRvdy5zZXRUaW1lb3V0KCgpPT5jb250cm9sbGVyLmFib3J0KCksZGF0YSA/IDI1MDAwIDogMTUwMDApO1xuICAgICAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgICAgIGxldCByZXNwb25zZTtcbiAgICAgICAgICAgICAgICAgICAgdHJ5IHsgcmVzcG9uc2UgPSBhd2FpdCBmZXRjaCh1cmwsey4uLm9wdGlvbnMsc2lnbmFsOmNvbnRyb2xsZXIuc2lnbmFsfSk7IH1cbiAgICAgICAgICAgICAgICAgICAgY2F0Y2ggKF8pIHsgdGhyb3cgZmFpbHVyZShkYXRhID8gdW5jZXJ0YWluTWVzc2FnZSA6IChuYXZpZ2F0b3Iub25MaW5lPT09ZmFsc2UgPyBvZmZsaW5lTWVzc2FnZSA6IHRyYW5zaWVudE1lc3NhZ2UpLHRydWUpOyB9XG4gICAgICAgICAgICAgICAgICAgIGxldCByZXN1bHQ7XG4gICAgICAgICAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgICAgICAgICByZXN1bHQgPSBhd2FpdCByZXNwb25zZS5qc29uKCk7XG4gICAgICAgICAgICAgICAgICAgICAgICBpZiAoIXJlc3VsdCB8fCB0eXBlb2YgcmVzdWx0ICE9PSAnb2JqZWN0JyB8fCBBcnJheS5pc0FycmF5KHJlc3VsdCkpIHRocm93IG5ldyBFcnJvcigpO1xuICAgICAgICAgICAgICAgICAgICB9IGNhdGNoIChfKSB7XG4gICAgICAgICAgICAgICAgICAgICAgICBjb25zdCBleHBpcmVkID0gcmVzcG9uc2Uuc3RhdHVzPT09NDAxIHx8IChyZXNwb25zZS5yZWRpcmVjdGVkICYmIC9bPyZdY29udHJvbGxlcj1sb2dpbig/OiZ8JCkvLnRlc3QocmVzcG9uc2UudXJsKSk7XG4gICAgICAgICAgICAgICAgICAgICAgICBpZiAoZXhwaXJlZCkgdGhyb3cgZmFpbHVyZSgnTGEgc2VzacOzbiBleHBpcsOzLiBJbmljaWEgc2VzacOzbiBudWV2YW1lbnRlIHBhcmEgY29uc3VsdGFyIHR1cyBjb21lbnRhcmlvcy4nLGZhbHNlLDQwMSk7XG4gICAgICAgICAgICAgICAgICAgICAgICBpZiAocmVzcG9uc2Uuc3RhdHVzPT09NDAzKSB0aHJvdyBmYWlsdXJlKCdObyBzZSBwdWRvIGFjY2VkZXIgYSBsb3MgY29tZW50YXJpb3MuIFJlY2FyZ2EgbGEgcMOhZ2luYSBwYXJhIGNvbXByb2JhciB0dSBhY2Nlc28uJyxmYWxzZSw0MDMpO1xuICAgICAgICAgICAgICAgICAgICAgICAgdGhyb3cgZmFpbHVyZShkYXRhID8gdW5jZXJ0YWluTWVzc2FnZSA6IHRyYW5zaWVudE1lc3NhZ2UsdHJ1ZSxyZXNwb25zZS5zdGF0dXMpO1xuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgICAgIGlmICghcmVzcG9uc2Uub2sgfHwgcmVzdWx0Lm9rIT09dHJ1ZSkge1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3QgcmV0cnlhYmxlID0gWzQwOCw0MjksNTAwLDUwMiw1MDMsNTA0XS5pbmNsdWRlcyhyZXNwb25zZS5zdGF0dXMpO1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3QgbWVzc2FnZSA9IHR5cGVvZiByZXN1bHQubWVuc2FqZT09PSdzdHJpbmcnID8gcmVzdWx0Lm1lbnNhamUgOiAoZGF0YSA/IHVuY2VydGFpbk1lc3NhZ2UgOiB0cmFuc2llbnRNZXNzYWdlKTtcbiAgICAgICAgICAgICAgICAgICAgICAgIGNvbnN0IGVycm9yID0gZmFpbHVyZShtZXNzYWdlLHJldHJ5YWJsZSxyZXNwb25zZS5zdGF0dXMpO1xuICAgICAgICAgICAgICAgICAgICAgICAgY29uc3QgcmV0cnlBZnRlciA9IHJlc3BvbnNlLmhlYWRlcnMuZ2V0KCdSZXRyeS1BZnRlcicpO1xuICAgICAgICAgICAgICAgICAgICAgICAgLy8gVW5hIGxpbWl0YWNpw7NuIGV4cGzDrWNpdGEgZGVsIHNlcnZpZG9yIGVzcGVyYSBhbCBjaWNsb1xuICAgICAgICAgICAgICAgICAgICAgICAgLy8gaGFiaXR1YWw7IG5vIGFjdW11bGFyIHJlaW50ZW50b3MgaW5tZWRpYXRvcy5cbiAgICAgICAgICAgICAgICAgICAgICAgIGlmIChyZXNwb25zZS5zdGF0dXM9PT00MjkgJiYgcmV0cnlBZnRlcikgZXJyb3IucmV0cnlhYmxlPWZhbHNlO1xuICAgICAgICAgICAgICAgICAgICAgICAgdGhyb3cgZXJyb3I7XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICAgICAgaWYgKCFkYXRhICYmICghQXJyYXkuaXNBcnJheShyZXN1bHQuY29tZW50YXJpb3MpIHx8ICFyZXN1bHQuY29tZW50YXJpb3MuZXZlcnkoYz0+XG4gICAgICAgICAgICAgICAgICAgICAgICBjICYmIE51bWJlcihjLmlkKT4wICYmIHR5cGVvZiBjLmZlY2hhPT09J3N0cmluZycgJiYgdHlwZW9mIGMuYXV0b3I9PT0nc3RyaW5nJyAmJlxuICAgICAgICAgICAgICAgICAgICAgICAgdHlwZW9mIGMuY29tZW50YXJpbz09PSdzdHJpbmcnICYmIHR5cGVvZiBjLmNyZWFkb19hdD09PSdzdHJpbmcnXG4gICAgICAgICAgICAgICAgICAgICkpKSB0aHJvdyBmYWlsdXJlKHRyYW5zaWVudE1lc3NhZ2UsdHJ1ZSxyZXNwb25zZS5zdGF0dXMpO1xuICAgICAgICAgICAgICAgICAgICByZXR1cm4gcmVzdWx0O1xuICAgICAgICAgICAgICAgIH0gY2F0Y2ggKGVycm9yKSB7XG4gICAgICAgICAgICAgICAgICAgIGlmIChkYXRhIHx8ICFlcnJvci5yZXRyeWFibGUgfHwgYXR0ZW1wdD09PWF0dGVtcHRzLTEgfHwgbmF2aWdhdG9yLm9uTGluZT09PWZhbHNlKSB0aHJvdyBlcnJvcjtcbiAgICAgICAgICAgICAgICB9IGZpbmFsbHkgeyB3aW5kb3cuY2xlYXJUaW1lb3V0KHRpbWVvdXQpOyB9XG4gICAgICAgICAgICAgICAgYXdhaXQgbmV3IFByb21pc2UocmVzb2x2ZT0+d2luZG93LnNldFRpbWVvdXQocmVzb2x2ZSw4MDAqKGF0dGVtcHQrMSkpKTtcbiAgICAgICAgICAgIH1cbiAgICAgICAgfVxuICAgICAgICBjb25zdCByZWZyZXNoQmVsbCA9IG93bklkID0+IHdpbmRvdy5kaXNwYXRjaEV2ZW50KG5ldyBDdXN0b21FdmVudCgnaW1wZTphZ2VuZGEtY29tbWVudHMtcmVmcmVzaCcse1xuICAgICAgICAgICAgZGV0YWlsOntjb21lbnRhcmlvUHJvcGlvSWQ6TnVtYmVyKG93bklkKXx8MH1cbiJ9LHsiYmVmb3JlIjoiICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICBjb25zdCByZXN1bHQ9YXdhaXQgcmVxdWVzdCgnbGlzdGFyJyk7XG4gICAgICAgICAgICAgICAgaWYodmVyc2lvbiE9PWNoYW5nZVZlcnNpb24pe3JlZnJlc2hBZ2Fpbj10cnVlO3JldHVybjt9XG4gICAgICAgICAgICAgICAgY29tbWVudHM9QXJyYXkuaXNBcnJheShyZXN1bHQuY29tZW50YXJpb3MpP3Jlc3VsdC5jb21lbnRhcmlvczpbXTtcbiAgICAgICAgICAgICAgICBwcml2YWN5QXZhaWxhYmxlPXJlc3VsdC5wcml2YWRvc19kaXNwb25pYmxlcz09PXRydWU7XG4gICAgICAgICAgICAgICAgdXBkYXRlT3B0aW9ucygpO1xuICAgICAgICAgICAgICAgIGF2YWlsYWJsZT10cnVlO1xuICAgICAgICAgICAgICAgIHJvb3QucXVlcnlTZWxlY3RvckFsbCgnW2RhdGEtYWdlbmRhLWNvbW1lbnQtYWRkXScpLmZvckVhY2goYj0+Yi5kaXNhYmxlZD1mYWxzZSk7XG4gICAgICAgICAgICAgICAgdXBkYXRlTWFya2VycygpO3JlbmRlclBhbmVsKCk7XG4gICAgICAgICAgICAgICAgaWYgKHJlc3VsdC5oYXlfbWFzKSBpbmZvcm0oJ1NlIG11ZXN0cmFuIGxvcyBwcmltZXJvcyA1MDAgY29tZW50YXJpb3MgZGUgZXN0ZSBpbnRlcnZhbG8uJyk7XG4gICAgICAgICAgICAgICAgaWYgKCFpbml0aWFsRG9uZSkge1xuICAgICAgICAgICAgICAgICAgICBpbml0aWFsRG9uZT10cnVlO1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBjb21tZW50PWNvbW1lbnRzLmZpbmQoYz0+Yy5pZD09PWluaXRpYWxJZCk7XG4iLCJhZnRlciI6IiAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgY29uc3QgcmVzdWx0PWF3YWl0IHJlcXVlc3QoJ2xpc3RhcicpO1xuICAgICAgICAgICAgICAgIGlmKHZlcnNpb24hPT1jaGFuZ2VWZXJzaW9uKXtyZWZyZXNoQWdhaW49dHJ1ZTtyZXR1cm47fVxuICAgICAgICAgICAgICAgIGlmKGxvYWRTdGF0dXMpaW5mb3JtKCcnKTtcbiAgICAgICAgICAgICAgICBjb21tZW50cz1yZXN1bHQuY29tZW50YXJpb3M7XG4gICAgICAgICAgICAgICAgcHJpdmFjeUF2YWlsYWJsZT1yZXN1bHQucHJpdmFkb3NfZGlzcG9uaWJsZXM9PT10cnVlO1xuICAgICAgICAgICAgICAgIHVwZGF0ZU9wdGlvbnMoKTtcbiAgICAgICAgICAgICAgICBhdmFpbGFibGU9dHJ1ZTtcbiAgICAgICAgICAgICAgICByb290LnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLWFnZW5kYS1jb21tZW50LWFkZF0nKS5mb3JFYWNoKGI9PmIuZGlzYWJsZWQ9ZmFsc2UpO1xuICAgICAgICAgICAgICAgIHVwZGF0ZU1hcmtlcnMoKTtyZW5kZXJQYW5lbCgpO1xuICAgICAgICAgICAgICAgIGlmIChyZXN1bHQuaGF5X21hcykgaW5mb3JtKCdTZSBtdWVzdHJhbiBsb3MgcHJpbWVyb3MgNTAwIGNvbWVudGFyaW9zIGRlIGVzdGUgaW50ZXJ2YWxvLicsZmFsc2UsdHJ1ZSk7XG4gICAgICAgICAgICAgICAgaWYgKCFpbml0aWFsRG9uZSkge1xuICAgICAgICAgICAgICAgICAgICBpbml0aWFsRG9uZT10cnVlO1xuICAgICAgICAgICAgICAgICAgICBjb25zdCBjb21tZW50PWNvbW1lbnRzLmZpbmQoYz0+Yy5pZD09PWluaXRpYWxJZCk7XG4ifSx7ImJlZm9yZSI6IiAgICAgICAgICAgICAgICAgICAgZWxzZSBpZiAoaW5pdGlhbElkPjApIHsgb3BlbkRheShpbml0aWFsRGF5KTtpbmZvcm0oJ0VsIGNvbWVudGFyaW8geWEgbm8gZXN0w6EgZGlzcG9uaWJsZS4nLHRydWUpOyB9XG4gICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgfSBjYXRjaCAoZSkge1xuICAgICAgICAgICAgICAgIGlmICghYXZhaWxhYmxlKSByb290LnF1ZXJ5U2VsZWN0b3JBbGwoJ1tkYXRhLWFnZW5kYS1jb21tZW50LWFkZF0nKS5mb3JFYWNoKGI9PmIuZGlzYWJsZWQ9dHJ1ZSk7XG4gICAgICAgICAgICAgICAgaW5mb3JtKGUubWVzc2FnZSx0cnVlKTtcbiAgICAgICAgICAgIH0gZmluYWxseSB7IGxvYWRpbmc9ZmFsc2U7aWYocmVmcmVzaEFnYWluKXtyZWZyZXNoQWdhaW49ZmFsc2U7dm9pZCBsb2FkKCk7fSB9XG4gICAgICAgIH1cbiAgICAgICAgZnVuY3Rpb24gY291bnRlcigpIHtcbiIsImFmdGVyIjoiICAgICAgICAgICAgICAgICAgICBlbHNlIGlmIChpbml0aWFsSWQ+MCkgeyBvcGVuRGF5KGluaXRpYWxEYXkpO2luZm9ybSgnRWwgY29tZW50YXJpbyB5YSBubyBlc3TDoSBkaXNwb25pYmxlLicsdHJ1ZSk7IH1cbiAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICB9IGNhdGNoIChlKSB7XG4gICAgICAgICAgICAgICAgaWYgKFs0MDEsNDAzXS5pbmNsdWRlcyhlLnN0YXR1cykpIHtcbiAgICAgICAgICAgICAgICAgICAgY29tbWVudHM9W107YXZhaWxhYmxlPWZhbHNlO3ByaXZhY3lBdmFpbGFibGU9ZmFsc2U7dXBkYXRlT3B0aW9ucygpO3VwZGF0ZU1hcmtlcnMoKTtyZW5kZXJQYW5lbCgpO1xuICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICBpZiAoIWF2YWlsYWJsZSkgcm9vdC5xdWVyeVNlbGVjdG9yQWxsKCdbZGF0YS1hZ2VuZGEtY29tbWVudC1hZGRdJykuZm9yRWFjaChiPT5iLmRpc2FibGVkPXRydWUpO1xuICAgICAgICAgICAgICAgIGluZm9ybShhdmFpbGFibGUgJiYgZS5yZXRyeWFibGVcbiAgICAgICAgICAgICAgICAgICAgPyAnTm8gc2UgcHVkaWVyb24gYWN0dWFsaXphciBsb3MgY29tZW50YXJpb3MuIFNlIGNvbnNlcnZhbiBsb3Mgw7psdGltb3MgY2FyZ2Fkb3MgeSBzZSB2b2x2ZXLDoSBhIGludGVudGFyIGF1dG9tw6F0aWNhbWVudGUuJ1xuICAgICAgICAgICAgICAgICAgICA6IGUubWVzc2FnZSx0cnVlLHRydWUpO1xuICAgICAgICAgICAgfSBmaW5hbGx5IHsgbG9hZGluZz1mYWxzZTtpZihyZWZyZXNoQWdhaW4pe3JlZnJlc2hBZ2Fpbj1mYWxzZTt2b2lkIGxvYWQoKTt9IH1cbiAgICAgICAgfVxuICAgICAgICBmdW5jdGlvbiBjb3VudGVyKCkge1xuIn0seyJiZWZvcmUiOiIgICAgICAgIH0pO1xuICAgICAgICB2b2lkIGxvYWQoKTtcbiAgICAgICAgd2luZG93LnNldEludGVydmFsKCgpPT52b2lkIGxvYWQoKSw2MDAwMCk7XG4gICAgICAgIGRvY3VtZW50LmFkZEV2ZW50TGlzdGVuZXIoJ3Zpc2liaWxpdHljaGFuZ2UnLCgpPT57aWYoIWRvY3VtZW50LmhpZGRlbil2b2lkIGxvYWQoKTt9KTtcbiAgICB9XG4gICAgaWYoZG9jdW1lbnQucmVhZHlTdGF0ZT09PSdsb2FkaW5nJylkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCdET01Db250ZW50TG9hZGVkJyxpbmljaWFyLHtvbmNlOnRydWV9KTtlbHNlIGluaWNpYXIoKTtcbiIsImFmdGVyIjoiICAgICAgICB9KTtcbiAgICAgICAgdm9pZCBsb2FkKCk7XG4gICAgICAgIHdpbmRvdy5zZXRJbnRlcnZhbCgoKT0+dm9pZCBsb2FkKCksNjAwMDApO1xuICAgICAgICB3aW5kb3cuYWRkRXZlbnRMaXN0ZW5lcignb25saW5lJywoKT0+dm9pZCBsb2FkKCkpO1xuICAgICAgICBkb2N1bWVudC5hZGRFdmVudExpc3RlbmVyKCd2aXNpYmlsaXR5Y2hhbmdlJywoKT0+e2lmKCFkb2N1bWVudC5oaWRkZW4pdm9pZCBsb2FkKCk7fSk7XG4gICAgfVxuICAgIGlmKGRvY3VtZW50LnJlYWR5U3RhdGU9PT0nbG9hZGluZycpZG9jdW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignRE9NQ29udGVudExvYWRlZCcsaW5pY2lhcix7b25jZTp0cnVlfSk7ZWxzZSBpbmljaWFyKCk7XG4ifV19LHsicGF0aCI6InB1YmxpYy9qYXZhc2NyaXB0L2FnZW5kYV9yZXVuaW9uLmpzIiwiZnJhZ21lbnRzIjpbeyJiZWZvcmUiOiIgICAgICAgICAgICAgICAgICAgICAgICBoZWFkZXJzOiB7ICdYLVJlcXVlc3RlZC1XaXRoJzogJ2ZldGNoJyB9XG4gICAgICAgICAgICAgICAgICAgIH1cbiAgICAgICAgICAgICAgICApO1xuICAgICAgICAgICAgICAgIGNvbnN0IGpzb24gPSBhd2FpdCByZXNwdWVzdGEuanNvbigpO1xuXG4gICAgICAgICAgICAgICAgaWYgKCFyZXNwdWVzdGEub2sgfHwgIWpzb24ub2spIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGpzb24ubWVuc2FqZSB8fCAnTm8gZnVlIHBvc2libGUgZ3VhcmRhciBsb3MgY2FtYmlvcy4nKTtcbiIsImFmdGVyIjoiICAgICAgICAgICAgICAgICAgICAgICAgaGVhZGVyczogeyAnWC1SZXF1ZXN0ZWQtV2l0aCc6ICdmZXRjaCcgfVxuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgKTtcbiAgICAgICAgICAgICAgICBjb25zdCB0ZXh0byA9IGF3YWl0IHJlc3B1ZXN0YS50ZXh0KCk7XG4gICAgICAgICAgICAgICAgbGV0IGpzb247XG4gICAgICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICAgICAganNvbiA9IEpTT04ucGFyc2UodGV4dG8pO1xuICAgICAgICAgICAgICAgICAgICBpZiAoIWpzb24gfHwgdHlwZW9mIGpzb24gIT09ICdvYmplY3QnIHx8IEFycmF5LmlzQXJyYXkoanNvbikpIHRocm93IG5ldyBFcnJvcigpO1xuICAgICAgICAgICAgICAgIH0gY2F0Y2ggKF8pIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKHJlc3B1ZXN0YS5zdGF0dXMgPT09IDQwMSB8fCByZXNwdWVzdGEucmVkaXJlY3RlZFxuICAgICAgICAgICAgICAgICAgICAgICAgPyAnTGEgc2VzacOzbiBleHBpcsOzLiBJbmljaWEgc2VzacOzbiBudWV2YW1lbnRlIHkgY29tcHJ1ZWJhIGVsIGVzdGFkbyBkZSBsYSByZXVuacOzbi4nXG4gICAgICAgICAgICAgICAgICAgICAgICA6ICdObyBmdWUgcG9zaWJsZSBjb25maXJtYXIgbGEgcmVzcHVlc3RhIGRlbCBzZXJ2aWRvci4gQWN0dWFsaXphIGxhIGFnZW5kYSBwYXJhIGNvbXByb2JhciBlbCBlc3RhZG8gZGUgbGEgcmV1bmnDs24gYW50ZXMgZGUgdm9sdmVyIGEgaW50ZW50YXJsby4nKTtcbiAgICAgICAgICAgICAgICB9XG5cbiAgICAgICAgICAgICAgICBpZiAoIXJlc3B1ZXN0YS5vayB8fCAhanNvbi5vaykge1xuICAgICAgICAgICAgICAgICAgICB0aHJvdyBuZXcgRXJyb3IoanNvbi5tZW5zYWplIHx8ICdObyBmdWUgcG9zaWJsZSBndWFyZGFyIGxvcyBjYW1iaW9zLicpO1xuIn0seyJiZWZvcmUiOiIgICAgICAgICAgICAgICAgY29uc3QgY2FqYSA9IGRvY3VtZW50LmNyZWF0ZUVsZW1lbnQoJ2RpdicpO1xuICAgICAgICAgICAgICAgIGNhamEuY2xhc3NOYW1lID0gJ2FnZW5kYS1mb3JtLWVycm9yJztcbiAgICAgICAgICAgICAgICBjYWphLnRleHRDb250ZW50ID1cbiAgICAgICAgICAgICAgICAgICAgZXJyb3IubWVzc2FnZSB8fCAnTm8gZnVlIHBvc2libGUgZ3VhcmRhciBsb3MgY2FtYmlvcy4nO1xuICAgICAgICAgICAgICAgIGZvcm0ucXVlcnlTZWxlY3RvcignLmFnZW5kYS1mb3JtLWVycm9yJyk/LnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgIGZvcm0ucHJlcGVuZChjYWphKTtcbiAgICAgICAgICAgIH0gZmluYWxseSB7XG4iLCJhZnRlciI6IiAgICAgICAgICAgICAgICBjb25zdCBjYWphID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZGl2Jyk7XG4gICAgICAgICAgICAgICAgY2FqYS5jbGFzc05hbWUgPSAnYWdlbmRhLWZvcm0tZXJyb3InO1xuICAgICAgICAgICAgICAgIGNhamEudGV4dENvbnRlbnQgPVxuICAgICAgICAgICAgICAgICAgICBlcnJvciBpbnN0YW5jZW9mIFR5cGVFcnJvciB8fCBlcnJvci5uYW1lPT09J0Fib3J0RXJyb3InIHx8IC9mYWlsZWQgdG8gZmV0Y2h8bmV0d29ya2Vycm9yfGxvYWQgZmFpbGVkL2kudGVzdChlcnJvci5tZXNzYWdlIHx8ICcnKVxuICAgICAgICAgICAgICAgICAgICAgICAgPyAnTm8gc2UgcHVkbyBjb21wcm9iYXIgbGEgb3BlcmFjacOzbiBwb3IgdW4gcHJvYmxlbWEgZGUgY29uZXhpw7NuLiBBY3R1YWxpemEgbGEgYWdlbmRhIHBhcmEgcmV2aXNhciBlbCBlc3RhZG8gZGUgbGEgcmV1bmnDs24gYW50ZXMgZGUgdm9sdmVyIGEgaW50ZW50YXJsby4nXG4gICAgICAgICAgICAgICAgICAgICAgICA6IChlcnJvci5tZXNzYWdlIHx8ICdObyBmdWUgcG9zaWJsZSBndWFyZGFyIGxvcyBjYW1iaW9zLicpO1xuICAgICAgICAgICAgICAgIGZvcm0ucXVlcnlTZWxlY3RvcignLmFnZW5kYS1mb3JtLWVycm9yJyk/LnJlbW92ZSgpO1xuICAgICAgICAgICAgICAgIGZvcm0ucHJlcGVuZChjYWphKTtcbiAgICAgICAgICAgIH0gZmluYWxseSB7XG4ifV0sImFsdGVybmF0aXZlcyI6W1t7ImJlZm9yZSI6IiAgICAgICAgICAgICAgICBjb25zdCBjYWphID0gZG9jdW1lbnQuY3JlYXRlRWxlbWVudCgnZGl2Jyk7XG4gICAgICAgICAgICAgICAgY2FqYS5jbGFzc05hbWUgPSAnYWdlbmRhLWZvcm0tZXJyb3InO1xuICAgICAgICAgICAgICAgIGNhamEudGV4dENvbnRlbnQgPVxuICAgICAgICAgICAgICAgICAgICBlcnJvci5tZXNzYWdlIHx8ICdObyBmdWUgcG9zaWJsZSBndWFyZGFyIGxvcyBjYW1iaW9zLic7XG4gICAgICAgICAgICAgICAgZm9ybS5xdWVyeVNlbGVjdG9yKCcuYWdlbmRhLWZvcm0tZXJyb3InKT8ucmVtb3ZlKCk7XG4gICAgICAgICAgICAgICAgZm9ybS5wcmVwZW5kKGNhamEpO1xuICAgICAgICAgICAgfSBmaW5hbGx5IHtcbiIsImFmdGVyIjoiICAgICAgICAgICAgICAgIGNvbnN0IGNhamEgPSBkb2N1bWVudC5jcmVhdGVFbGVtZW50KCdkaXYnKTtcbiAgICAgICAgICAgICAgICBjYWphLmNsYXNzTmFtZSA9ICdhZ2VuZGEtZm9ybS1lcnJvcic7XG4gICAgICAgICAgICAgICAgY2FqYS50ZXh0Q29udGVudCA9XG4gICAgICAgICAgICAgICAgICAgIGVycm9yIGluc3RhbmNlb2YgVHlwZUVycm9yIHx8IGVycm9yLm5hbWU9PT0nQWJvcnRFcnJvcicgfHwgL2ZhaWxlZCB0byBmZXRjaHxuZXR3b3JrZXJyb3J8bG9hZCBmYWlsZWQvaS50ZXN0KGVycm9yLm1lc3NhZ2UgfHwgJycpXG4gICAgICAgICAgICAgICAgICAgICAgICA/ICdObyBzZSBwdWRvIGNvbXByb2JhciBsYSBvcGVyYWNpw7NuIHBvciB1biBwcm9ibGVtYSBkZSBjb25leGnDs24uIEFjdHVhbGl6YSBsYSBhZ2VuZGEgcGFyYSByZXZpc2FyIGVsIGVzdGFkbyBkZSBsYSByZXVuacOzbiBhbnRlcyBkZSB2b2x2ZXIgYSBpbnRlbnRhcmxvLidcbiAgICAgICAgICAgICAgICAgICAgICAgIDogKGVycm9yLm1lc3NhZ2UgfHwgJ05vIGZ1ZSBwb3NpYmxlIGd1YXJkYXIgbG9zIGNhbWJpb3MuJyk7XG4gICAgICAgICAgICAgICAgZm9ybS5xdWVyeVNlbGVjdG9yKCcuYWdlbmRhLWZvcm0tZXJyb3InKT8ucmVtb3ZlKCk7XG4gICAgICAgICAgICAgICAgZm9ybS5wcmVwZW5kKGNhamEpO1xuICAgICAgICAgICAgfSBmaW5hbGx5IHtcbiJ9XV19XQ==', true), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? '--check';
if (!(count($argv) <= 2 && in_array($mode, ['--check','--apply'], true)) &&
    !(count($argv) === 3 && $mode === '--restore')) {
    fwrite(STDERR, "Uso: --check | --apply | --restore RUTA\n");exit(2);
}
function stopDeploy($message) { throw new RuntimeException($message); }
function safePath($path) {
    $path = str_replace('\\','/',$path);
    for ($part=$path; $part!==dirname($part); $part=dirname($part)) {
        if (is_link($part)) stopDeploy('Enlace simbólico no permitido: '.$path);
    }
}
function absolutePath($path) {
    if (!preg_match('~^(?:/|[A-Za-z]:/)~',str_replace('\\','/',$path)) || strpos($path,"\0")!==false) stopDeploy('Ruta absoluta requerida.');
    safePath($path);
    if (file_exists($path)) return str_replace('\\','/',realpath($path));
    $name=basename($path);
    if ($name==='.' || $name==='..' || $name==='') stopDeploy('Ruta inválida.');
    return rtrim(absolutePath(dirname($path)),'/').'/'.$name;
}
function beneath($path,$parent) { return $path===$parent || strpos($path,$parent.'/')===0; }
function digest($path) { return is_file($path) ? hash_file('sha256',$path) : null; }
function putFile($path,$text,$mode=0600) {
    if (file_put_contents($path,$text) !== strlen($text) || !chmod($path,$mode)) stopDeploy('No fue posible preparar un archivo.');
}
function atomicFile($path,$text,$mode) {
    safePath($path);
    $dir=dirname($path);
    if (!is_dir($dir) && !mkdir($dir,0755,true)) stopDeploy('No fue posible crear la carpeta de destino.');
    safePath($dir);
    $tmp=tempnam($dir,'.impe-agenda-');
    if ($tmp===false) stopDeploy('No se pudo preparar el destino.');
    try { putFile($tmp,$text,$mode); if (!rename($tmp,$path)) stopDeploy('No se pudo instalar el archivo.'); }
    finally { if (is_file($tmp)) unlink($tmp); }
}
$lock=null;$ownedLock=false;$written=[];$staged=[];$originals=[];$before=[];$after=[];$modes=[];
try {
    $live=absolutePath(getenv('IMPE_CORREOS_PUBLIC') ?: getenv('HOME').'/domains/fundacionrededumex.org/public_html');
    if (!is_dir($live) || !is_file($live.'/index.php')) stopDeploy('Raíz del CRM no encontrada.');
    $backups=absolutePath(getenv('IMPE_CORREOS_BACKUPS') ?: getenv('HOME').'/respaldos-crm');
    if (beneath($backups,$live) || beneath($live,$backups)) stopDeploy('Los respaldos deben estar fuera del sitio y de sus antecesores.');
    if ($mode!=='--check') {
        $lock=dirname($live).'/.impe-correos-deploy.lock';safePath($lock);
        if (!@mkdir($lock,0700)) stopDeploy('Otra publicación está en curso.');
        $ownedLock=true;
    }
    foreach ($files as $i=>$file) {
        $path=$live.'/'.$file['path'];safePath($path);
        if (file_exists($path) && !is_file($path)) stopDeploy('Destino incompatible: '.$file['path']);
        $before[$i]=digest($path);
        $originals[$i]=$before[$i]===null ? null : file_get_contents($path);
        $modes[$i]=$before[$i]===null ? 0644 : (fileperms($path)&0777);
    }
    if ($mode==='--restore') {
        $restore=absolutePath($argv[2]);
        if (dirname($restore)!==$backups) stopDeploy('Respaldo fuera de la carpeta prevista.');
        safePath($restore.'/manifest.json');
        $manifest=json_decode(file_get_contents($restore.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if (($manifest['version']??'')!==$version || count($manifest['files']??[])!==count($files)) stopDeploy('Respaldo de otra entrega.');
        foreach ($files as $i=>$file) {
            $entry=$manifest['files'][$i];
            if ($entry['path']!==$file['path'] || !in_array($before[$i],[$entry['before'],$entry['after']],true)) stopDeploy('Archivo editado después de publicar: '.$file['path']);
            $staged[$i]=null;
            if ($entry['before']!==null) {
                $saved=$restore.'/original.'.$i;safePath($saved);
                if (digest($saved)!==$entry['before']) stopDeploy('Respaldo incompleto o alterado.');
                $staged[$i]=file_get_contents($saved);
            }
            $after[$i]=$entry['before'];$modes[$i]=$entry['mode'];
        }
    } else {
        foreach ($files as $i=>$file) {
            if (isset($file['content'])) {
                if (hash('sha256',$file['content'])!==$file['sha256']) stopDeploy('Contenido integrado alterado.');
                $text=$file['content'];
                if ($originals[$i]!==null && str_replace("\r\n","\n",$originals[$i])!==$text) stopDeploy('Archivo de comentarios personalizado: '.$file['path']);
                if ($originals[$i]!==null) $text=$originals[$i];
            } else {
                if ($originals[$i]===null) stopDeploy('Falta '.$file['path']);
                $crlf=strpos($originals[$i],"\r\n")!==false;
                $text=str_replace("\r\n","\n",$originals[$i]);
                $compatible=false;
                foreach (array_merge([$file['fragments']],$file['alternatives']??[]) as $variant) {
                    $candidate=$text;$matches=true;
                    foreach ($variant as $fragment) {
                        $old=$fragment['before'];$new=$fragment['after'];
                        $oldCount=substr_count($candidate,$old);$newCount=substr_count($candidate,$new);
                        if ($oldCount===1 && $newCount===0) $candidate=str_replace($old,$new,$candidate);
                        elseif ($oldCount!==0 || $newCount!==1) { $matches=false;break; }
                    }
                    if ($matches) { $text=$candidate;$compatible=true;break; }
                }
                if (!$compatible) stopDeploy('Integración incompatible en '.$file['path'].'. SITIO INTACTO.');
                if ($crlf) $text=str_replace("\n","\r\n",$text);
            }
            if (substr($file['path'],-4)==='.php') token_get_all($text,TOKEN_PARSE);
            $staged[$i]=$text;$after[$i]=hash('sha256',$text);
        }
    }
    $changed=[];
    foreach ($files as $i=>$file) if ($before[$i]!==$after[$i]) $changed[]=$i;
    if ($mode==='--check') {
        echo 'OK: revisión compatible. '.count($changed)." archivo(s) pendientes. SITIO INTACTO. Sin SQL ni envíos.\n";
    } elseif (!$changed) {
        echo "OK: sin cambios pendientes. Sin SQL ni envíos.\n";
    } else {
        $backup=null;
        if ($mode==='--apply') {
            if (!is_dir($backups) && !mkdir($backups,0700,true)) stopDeploy('No se pudo crear la carpeta privada de respaldos.');
            safePath($backups);
            $backup=$backups.'/agenda-conexion-comentarios-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
            if (!mkdir($backup,0700)) stopDeploy('No se pudo crear el respaldo.');
            $manifest=['version'=>$version,'files'=>[]];
            foreach ($files as $i=>$file) {
                if ($originals[$i]!==null) putFile($backup.'/original.'.$i,$originals[$i]);
                $manifest['files'][]=['path'=>$file['path'],'before'=>$before[$i],'after'=>$after[$i],'mode'=>$modes[$i]];
            }
            putFile($backup.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        }
        if ($mode==='--restore') $changed=array_reverse($changed);
        foreach ($changed as $i) {
            $path=$live.'/'.$files[$i]['path'];safePath($path);
            if (digest($path)!==$before[$i]) stopDeploy('Cambio concurrente: '.$files[$i]['path']);
            if ($staged[$i]===null) { if (is_file($path) && !unlink($path)) stopDeploy('No se pudo retirar el archivo nuevo.'); }
            else atomicFile($path,$staged[$i],$modes[$i]);
            $written[]=$i;
            if (digest($path)!==$after[$i]) stopDeploy('No se pudo verificar el archivo publicado.');
        }
        $written=[];
        echo 'OK: '.$mode.' terminado. '.count($changed)." archivo(s). Sin SQL, envíos ni cambios de credenciales.\n";
        if ($backup) {
            echo 'Respaldo: '.$backup."\n";
            echo 'Rollback: bash '.escapeshellarg(getenv('IMPE_AGENDA_PUBLICADOR')).' --restore '.escapeshellarg($backup)."\n";
        }
        if ($mode==='--restore') echo "Los comentarios y sus lecturas se conservan en la base de datos.\n";
    }
} catch (Throwable $e) {
    foreach (array_reverse($written) as $i) {
        $path=$live.'/'.$files[$i]['path'];
        try {
            safePath($path);
            if (digest($path)!==$after[$i]) { fwrite(STDERR,'REVISAR: cambio concurrente en '.$files[$i]['path']."\n");continue; }
            if ($originals[$i]===null) { if (is_file($path)) unlink($path); }
            else atomicFile($path,$originals[$i],$modes[$i]);
        } catch (Throwable $rollbackError) { fwrite(STDERR,'REVISAR restauración de '.$files[$i]['path']."\n"); }
    }
    fwrite(STDERR,'ERROR: '.$e->getMessage()."\n");$failed=true;
} finally {
    if ($ownedLock) rmdir($lock);
}
exit(empty($failed)?0:1);
IMPE_AGENDA_PHP
php "$TMP_SCRIPT" "$@"
