#!/usr/bin/env bash
# Respuestas JSON y diagnóstico de confirmación. Publicación sin SQL ni envíos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-json.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
<?php
// Publicación selectiva. Sólo archivos listados; ninguna consulta SQL ni envío.
if (PHP_SAPI !== 'cli') { exit(1); }
$version = 'agenda-confirmacion-json-v1';
$files = json_decode(base64_decode('W3sicGF0aCI6InRvb2xzL2RpYWdub3N0aWNhcl9jb25maXJtYWNpb25fcmV1bmlvbi5waHAiLCJjb250ZW50IjoiPD9waHBcbi8vIFPDs2xvIENMSS4gVmFsaWRhIFBIUCB5IHByZXBhcmEgU1FMIHJlYWw7IGphbcOhcyBlamVjdXRhIElOU0VSVC9VUERBVEUvREVMRVRFLlxuaWYgKFBIUF9TQVBJICE9PSAnY2xpJykgeyBodHRwX3Jlc3BvbnNlX2NvZGUoNDA0KTsgZXhpdDsgfVxuaWYgKCRhcmdjPjIgfHwgKCRhcmd2WzFdPz8nLS1jaGVjaycpIT09Jy0tY2hlY2snKSB7IGZ3cml0ZShTVERFUlIsXCJVc286IC0tY2hlY2tcXG5cIik7ZXhpdCgyKTsgfVxuJHJhaXo9Z2V0ZW52KCdJTVBFX0NPUlJFT1NfUFVCTElDJykgPzogKGlzX2ZpbGUoZGlybmFtZShfX0RJUl9fKS4nL2luZGV4LnBocCcpID8gZGlybmFtZShfX0RJUl9fKSA6IGdldGVudignSE9NRScpLicvZG9tYWlucy9mdW5kYWNpb25yZWRlZHVtZXgub3JnL3B1YmxpY19odG1sJyk7XG5cbmNsYXNzIENvbmZpcm1hY2lvbkNvbnN1bHRhU2VndXJhXG57XG4gICAgcHVibGljICRhZmZlY3RlZF9yb3dzPTE7XG4gICAgcHJpdmF0ZSAkc3RtdDtcbiAgICBwcml2YXRlICRsZWN0dXJhO1xuICAgIHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCgkZGIsJHNxbCkge1xuICAgICAgICAkdGhpcy0+bGVjdHVyYT0oYm9vbClwcmVnX21hdGNoKCcvXlxccypTRUxFQ1RcXGIvaScsJHNxbCk7XG4gICAgICAgIGlmICghJHRoaXMtPmxlY3R1cmEgJiYgIXByZWdfbWF0Y2goJy9eXFxzKihVUERBVEV8SU5TRVJUKVxcYi9pJywkc3FsKSkgdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ1NRTCBubyBwcmV2aXN0by4nKTtcbiAgICAgICAgJHRoaXMtPnN0bXQ9JGRiLT5wcmVwYXJlKCRzcWwpO1xuICAgIH1cbiAgICBwdWJsaWMgZnVuY3Rpb24gYmluZF9wYXJhbSgkdGlwb3MsJi4uLiR2YWxvcmVzKSB7IHJldHVybiAkdGhpcy0+c3RtdC0+YmluZF9wYXJhbSgkdGlwb3MsLi4uJHZhbG9yZXMpOyB9XG4gICAgcHVibGljIGZ1bmN0aW9uIGV4ZWN1dGUoKSB7IHJldHVybiAkdGhpcy0+bGVjdHVyYSA/ICR0aGlzLT5zdG10LT5leGVjdXRlKCkgOiB0cnVlOyB9XG4gICAgcHVibGljIGZ1bmN0aW9uIGdldF9yZXN1bHQoKSB7XG4gICAgICAgIGlmICghJHRoaXMtPmxlY3R1cmEpIHRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdObyBzZSBlamVjdXRhbiBlc2NyaXR1cmFzLicpO1xuICAgICAgICByZXR1cm4gJHRoaXMtPnN0bXQtPmdldF9yZXN1bHQoKTtcbiAgICB9XG59XG5jbGFzcyBDb25maXJtYWNpb25Db25leGlvblNlZ3VyYVxue1xuICAgIHByaXZhdGUgJGRiO1xuICAgIHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCgkZGIpIHsgJHRoaXMtPmRiPSRkYjsgfVxuICAgIHB1YmxpYyBmdW5jdGlvbiBwcmVwYXJlKCRzcWwpIHsgcmV0dXJuIG5ldyBDb25maXJtYWNpb25Db25zdWx0YVNlZ3VyYSgkdGhpcy0+ZGIsJHNxbCk7IH1cbn1cblxuJGZhc2U9J2luaWNpbyc7JGZhbGxvcz0wO1xuJHByb2Jhcj1zdGF0aWMgZnVuY3Rpb24oJG5vbWJyZSwkZm4pdXNlKCYkZmFzZSwmJGZhbGxvcykge1xuICAgICRmYXNlPSRub21icmU7XG4gICAgdHJ5IHskZm4oKTtlY2hvICRub21icmUsXCI9T0tcIixQSFBfRU9MO31cbiAgICBjYXRjaChUaHJvd2FibGUgJGUpeyRmYWxsb3MrKztlY2hvICRub21icmUsXCI9RVJST1IgQ0xBU0U9XCIsZ2V0X2NsYXNzKCRlKSxcIiBDT0RJR089XCIsKGludCkkZS0+Z2V0Q29kZSgpLFwiIEFSQ0hJVk89XCIsYmFzZW5hbWUoJGUtPmdldEZpbGUoKSksXCIgTElORUE9XCIsJGUtPmdldExpbmUoKSxQSFBfRU9MO31cbn07XG50cnkge1xuICAgIHJlcXVpcmVfb25jZSAkcmFpei4nL2FwcC9zZXJ2aWNlcy9BZ2VuZGFSZXVuaW9uUmVwb3NpdG9yeS5waHAnO1xuICAgICRkYj0obmV3IERhdGFiYXNlKCkpLT5jb25uZWN0KGZhbHNlKTtcbiAgICAkcmVwbz0obmV3IFJlZmxlY3Rpb25DbGFzcyhBZ2VuZGFSZXVuaW9uUmVwb3NpdG9yeTo6Y2xhc3MpKS0+bmV3SW5zdGFuY2VXaXRob3V0Q29uc3RydWN0b3IoKTtcbiAgICAkcHJvcGVydHk9bmV3IFJlZmxlY3Rpb25Qcm9wZXJ0eShBZ2VuZGFSZXVuaW9uUmVwb3NpdG9yeTo6Y2xhc3MsJ2Nvbm5lY3Rpb24nKTskcHJvcGVydHktPnNldEFjY2Vzc2libGUodHJ1ZSk7XG4gICAgJHByb3BlcnR5LT5zZXRWYWx1ZSgkcmVwbyxuZXcgQ29uZmlybWFjaW9uQ29uZXhpb25TZWd1cmEoJGRiKSk7XG4gICAgJGZpbGE9JGRiLT5xdWVyeSgnU0VMRUNUIGlkLHNlZ3VpbWllbnRvX2lkLGFuYWxpc3RhX2lkLGN1ZW50YV9jbGF2ZV9pZCxmZWNoYV9wcm9wdWVzdGEsZHVyYWNpb25fbWludXRvcyBGUk9NIHJldW5pb25lc192aW5jdWxhY2lvbiBPUkRFUiBCWSBpZCBERVNDIExJTUlUIDEnKS0+ZmV0Y2hfYXNzb2MoKTtcbiAgICBpZiAoISRmaWxhKSB7ZWNobyBcIlNJTl9SRVVOSU9ORVNfUEFSQV9DT05TVUxUQVwiLFBIUF9FT0w7ZXhpdDt9XG4gICAgJGlkPShpbnQpJGZpbGFbJ2lkJ107JGthbT0oaW50KSRmaWxhWydjdWVudGFfY2xhdmVfaWQnXTtcbiAgICAkcHJvYmFyKCdjb25zdWx0YV9yZXVuaW9uJyxzdGF0aWMgZm4oKT0+JHJlcG8tPnJldW5pb24oJGlkLCRrYW0sNikpO1xuICAgICRwcm9iYXIoJ2NvbnN1bHRhX2NydWNlcycsc3RhdGljIGZuKCk9PiRyZXBvLT5jb25mbGljdG9Ib3JhcmlvKCRmaWxhWydmZWNoYV9wcm9wdWVzdGEnXSwoaW50KSRmaWxhWydkdXJhY2lvbl9taW51dG9zJ10sKGludCkkZmlsYVsnYW5hbGlzdGFfaWQnXSwka2FtLCRpZCkpO1xuICAgICRwcm9iYXIoJ3ByZXBhcmFyX2NvbmZpcm1hY2lvbicsc3RhdGljIGZuKCk9PiRyZXBvLT5jb25maXJtYXIoJGlkLCRrYW0sJ2h0dHBzOi8vem9vbS51cy9qL2RpYWdub3N0aWNvJywnJywnJykpO1xuICAgICRwcm9iYXIoJ3ByZXBhcmFyX2ludGVyYWNjaW9uJyxzdGF0aWMgZm4oKT0+JHJlcG8tPnJlZ2lzdHJhckludGVyYWNjaW9uKChpbnQpJGZpbGFbJ3NlZ3VpbWllbnRvX2lkJ10sJGthbSwnRGlhZ27Ds3N0aWNvIHNpbiBlc2NyaXR1cmEnKSk7XG4gICAgJHByb2JhcigndmFsb3Jlc19pbnRlcmFjY2lvbicsc3RhdGljIGZ1bmN0aW9uKCl1c2UoJGRiKSB7XG4gICAgICAgICRjYW1wb3M9JGRiLT5xdWVyeSgnU0hPVyBDT0xVTU5TIEZST00gaW50ZXJhY2Npb25lc192aW5jdWxhY2lvbicpLT5mZXRjaF9hbGwoTVlTUUxJX0FTU09DKTtcbiAgICAgICAgJHRpcG9zPWFycmF5X2NvbHVtbigkY2FtcG9zLCdUeXBlJywnRmllbGQnKTtcbiAgICAgICAgZm9yZWFjaChbJ2NhbmFsJz0+J1NJU1RFTUEnLCdyZXN1bHRhZG8nPT4nT1RSTyddYXMkY2FtcG89PiR2YWxvcil7XG4gICAgICAgICAgICBpZighaXNzZXQoJHRpcG9zWyRjYW1wb10pKXRocm93IG5ldyBSdW50aW1lRXhjZXB0aW9uKCdDYW1wbyBhdXNlbnRlLicpO1xuICAgICAgICAgICAgaWYoc3RyaXBvcygkdGlwb3NbJGNhbXBvXSwnZW51bSgnKT09PTAmJnN0cnBvcygkdGlwb3NbJGNhbXBvXSxcIidcIi4kdmFsb3IuXCInXCIpPT09ZmFsc2UpdGhyb3cgbmV3IFJ1bnRpbWVFeGNlcHRpb24oJ1ZhbG9yIG5vIGRpc3BvbmlibGUuJywxMjY1KTtcbiAgICAgICAgfVxuICAgIH0pO1xuICAgIGZvcmVhY2goWydhcHAvc2VydmljZXMvQWdlbmRhUmV1bmlvblJlcG9zaXRvcnkucGhwJywnYXBwL3NlcnZpY2VzL0FnZW5kYVJldW5pb25TZXJ2aWNlLnBocCcsJ2FwcC9jb250cm9sbGVycy9BZ2VuZGFSZXVuaW9uQ29udHJvbGxlci5waHAnXWFzJGYpZWNobyAnU0hBMjU2ICcsYmFzZW5hbWUoJGYpLCc9JyxoYXNoX2ZpbGUoJ3NoYTI1NicsJHJhaXouJy8nLiRmKSxQSFBfRU9MO1xuICAgIGVjaG8gXCJTT0xPIExFQ1RVUkE6IFNFTEVDVCB5IHByZXBhcmFjacOzbiBkZSBzZW50ZW5jaWFzLiBObyBzZSBlamVjdXTDsyBuaW5nw7puIGNhbWJpbywgY29uZmlybWFjacOzbiwgaW50ZXJhY2Npw7NuIG5pIGVudsOtby5cIixQSFBfRU9MO1xuICAgIGV4aXQoJGZhbGxvcz8xOjApO1xufSBjYXRjaChUaHJvd2FibGUgJGUpIHtcbiAgICBlY2hvICRmYXNlLFwiPUVSUk9SIENMQVNFPVwiLGdldF9jbGFzcygkZSksXCIgQ09ESUdPPVwiLChpbnQpJGUtPmdldENvZGUoKSxcIiBBUkNISVZPPVwiLGJhc2VuYW1lKCRlLT5nZXRGaWxlKCkpLFwiIExJTkVBPVwiLCRlLT5nZXRMaW5lKCksUEhQX0VPTDtcbiAgICBleGl0KDEpO1xufVxuIiwic2hhMjU2IjoiZDk5YTQzNzc3MzE1OGFmZjI2YWJiMzdkODY1NjAxYWZiYjI5NGMwNzVkZWQyMjM1YTUxM2UzMTc2ZGRjNDRhZSJ9LHsicGF0aCI6ImFwcC9jb250cm9sbGVycy9BZ2VuZGFSZXVuaW9uQ29udHJvbGxlci5waHAiLCJmcmFnbWVudHMiOlt7ImJlZm9yZSI6IlxuICAgIHB1YmxpYyBmdW5jdGlvbiBfX2NvbnN0cnVjdCgpXG4gICAge1xuICAgICAgICAkdGhpcy0+c2VydmljZSA9IG5ldyBBZ2VuZGFSZXVuaW9uU2VydmljZSgpO1xuICAgICAgICAkdGhpcy0+cmVwcm9ncmFtYWNpb25TZXJ2aWNlID0gbmV3IFJlcHJvZ3JhbWFjaW9uUmV1bmlvblNlcnZpY2UoKTtcbiAgICAgICAgJHRoaXMtPmV4cGVkaWVudGVTZXJ2aWNlID0gbmV3IFNlZ3VpbWllbnRvRXhwZWRpZW50ZVNlcnZpY2UoKTtcbiAgICB9XG5cbiAgICBwdWJsaWMgZnVuY3Rpb24gaW5kZXgoKVxuIiwiYWZ0ZXIiOiJcbiAgICBwdWJsaWMgZnVuY3Rpb24gX19jb25zdHJ1Y3QoKVxuICAgIHtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICR0aGlzLT5zZXJ2aWNlID0gbmV3IEFnZW5kYVJldW5pb25TZXJ2aWNlKCk7XG4gICAgICAgIH0gY2F0Y2ggKFRocm93YWJsZSAkZXJyb3IpIHtcbiAgICAgICAgICAgIGlmIChzdHJ0b2xvd2VyKChzdHJpbmcpKCRfU0VSVkVSWydIVFRQX1hfUkVRVUVTVEVEX1dJVEgnXSA/PyAnJykpICE9PSAnZmV0Y2gnKSB0aHJvdyAkZXJyb3I7XG4gICAgICAgICAgICAkdGhpcy0+ZXJyb3JBY2Npb24oJGVycm9yLCAnaW5pY2lhcicpO1xuICAgICAgICB9XG4gICAgICAgIC8vIENhZGEgYWNjacOzbiBjYXJnYSBzw7NsbyBzdXMgZGVwZW5kZW5jaWFzLiBDb25maXJtYXIgdW5hIHNvbGljaXR1ZCBub1xuICAgICAgICAvLyBuZWNlc2l0YSBhYnJpciBjb25leGlvbmVzIGRlbCBleHBlZGllbnRlIG5pIGRlIHJlcHJvZ3JhbWFjaW9uZXMuXG4gICAgfVxuXG4gICAgcHVibGljIGZ1bmN0aW9uIGluZGV4KClcbiJ9LHsiYmVmb3JlIjoiXG4gICAgICAgICRyb2xDb25zdWx0YSA9ICRlc01hcmtldGluZyA/IDEgOiAkcm9sSWQ7XG5cbiAgICAgICAgJHJlc3VsdGFkbyA9ICR0aGlzLT5leHBlZGllbnRlU2VydmljZS0+b2J0ZW5lcihcbiAgICAgICAgICAgICRzZWd1aW1pZW50b0lkLFxuICAgICAgICAgICAgJHVzdWFyaW9JZCxcbiIsImFmdGVyIjoiXG4gICAgICAgICRyb2xDb25zdWx0YSA9ICRlc01hcmtldGluZyA/IDEgOiAkcm9sSWQ7XG5cbiAgICAgICAgJHRoaXMtPmV4cGVkaWVudGVTZXJ2aWNlID0gJHRoaXMtPmV4cGVkaWVudGVTZXJ2aWNlID86IG5ldyBTZWd1aW1pZW50b0V4cGVkaWVudGVTZXJ2aWNlKCk7XG4gICAgICAgICRyZXN1bHRhZG8gPSAkdGhpcy0+ZXhwZWRpZW50ZVNlcnZpY2UtPm9idGVuZXIoXG4gICAgICAgICAgICAkc2VndWltaWVudG9JZCxcbiAgICAgICAgICAgICR1c3VhcmlvSWQsXG4ifSx7ImJlZm9yZSI6IiAgICAgICAgICAgIF0sIDQwNSk7XG4gICAgICAgIH1cblxuICAgICAgICAkcmVzdWx0YWRvID0gJHRoaXMtPnNlcnZpY2UtPiRtZXRvZG8oJHVzdWFyaW9JZCwgJHJvbElkLCAkX1BPU1QpO1xuICAgICAgICAkY29kaWdvSHR0cCA9IChpbnQpKCRyZXN1bHRhZG9bJ2NvZGlnb19odHRwJ10gPz8gMjAwKTtcbiAgICAgICAgdW5zZXQoJHJlc3VsdGFkb1snY29kaWdvX2h0dHAnXSk7XG5cbiIsImFmdGVyIjoiICAgICAgICAgICAgXSwgNDA1KTtcbiAgICAgICAgfVxuXG4gICAgICAgIHRyeSB7XG4gICAgICAgICAgICAkcmVzdWx0YWRvID0gJHRoaXMtPnNlcnZpY2UtPiRtZXRvZG8oJHVzdWFyaW9JZCwgJHJvbElkLCAkX1BPU1QpO1xuICAgICAgICB9IGNhdGNoIChUaHJvd2FibGUgJGVycm9yKSB7XG4gICAgICAgICAgICAkdGhpcy0+ZXJyb3JBY2Npb24oJGVycm9yLCAkbWV0b2RvKTtcbiAgICAgICAgfVxuICAgICAgICAkY29kaWdvSHR0cCA9IChpbnQpKCRyZXN1bHRhZG9bJ2NvZGlnb19odHRwJ10gPz8gMjAwKTtcbiAgICAgICAgdW5zZXQoJHJlc3VsdGFkb1snY29kaWdvX2h0dHAnXSk7XG5cbiJ9LHsiYmVmb3JlIjoiICAgICAgICAgICAgXSwgNDA1KTtcbiAgICAgICAgfVxuXG4gICAgICAgICRyZXN1bHRhZG8gPSAkdGhpcy0+cmVwcm9ncmFtYWNpb25TZXJ2aWNlLT4kbWV0b2RvKCR1c3VhcmlvSWQsICRyb2xJZCwgJF9QT1NUKTtcbiAgICAgICAgJGNvZGlnb0h0dHAgPSAoaW50KSgkcmVzdWx0YWRvWydjb2RpZ29faHR0cCddID8/IDIwMCk7XG4gICAgICAgIHVuc2V0KCRyZXN1bHRhZG9bJ2NvZGlnb19odHRwJ10pO1xuXG4iLCJhZnRlciI6IiAgICAgICAgICAgIF0sIDQwNSk7XG4gICAgICAgIH1cblxuICAgICAgICB0cnkge1xuICAgICAgICAgICAgJHRoaXMtPnJlcHJvZ3JhbWFjaW9uU2VydmljZSA9ICR0aGlzLT5yZXByb2dyYW1hY2lvblNlcnZpY2UgPzogbmV3IFJlcHJvZ3JhbWFjaW9uUmV1bmlvblNlcnZpY2UoKTtcbiAgICAgICAgICAgICRyZXN1bHRhZG8gPSAkdGhpcy0+cmVwcm9ncmFtYWNpb25TZXJ2aWNlLT4kbWV0b2RvKCR1c3VhcmlvSWQsICRyb2xJZCwgJF9QT1NUKTtcbiAgICAgICAgfSBjYXRjaCAoVGhyb3dhYmxlICRlcnJvcikge1xuICAgICAgICAgICAgJHRoaXMtPmVycm9yQWNjaW9uKCRlcnJvciwgJG1ldG9kbyk7XG4gICAgICAgIH1cbiAgICAgICAgJGNvZGlnb0h0dHAgPSAoaW50KSgkcmVzdWx0YWRvWydjb2RpZ29faHR0cCddID8/IDIwMCk7XG4gICAgICAgIHVuc2V0KCRyZXN1bHRhZG9bJ2NvZGlnb19odHRwJ10pO1xuXG4ifSx7ImJlZm9yZSI6IlxuICAgIHByaXZhdGUgZnVuY3Rpb24gcmVzcG9uZGVyKCRkYXRvcywgJGNvZGlnb0h0dHAgPSAyMDApXG4gICAge1xuICAgICAgICBodHRwX3Jlc3BvbnNlX2NvZGUoKGludCkkY29kaWdvSHR0cCk7XG4gICAgICAgIGVjaG8ganNvbl9lbmNvZGUoXG4gICAgICAgICAgICAkZGF0b3MsXG4gICAgICAgICAgICBKU09OX1VORVNDQVBFRF9VTklDT0RFIHwgSlNPTl9JTlZBTElEX1VURjhfU1VCU1RJVFVURVxuICAgICAgICApO1xuICAgICAgICBleGl0O1xuICAgIH1cblxuICAgIHByaXZhdGUgZnVuY3Rpb24gbm9tYnJlTWVzKCRtZXMpXG4iLCJhZnRlciI6IlxuICAgIHByaXZhdGUgZnVuY3Rpb24gcmVzcG9uZGVyKCRkYXRvcywgJGNvZGlnb0h0dHAgPSAyMDApXG4gICAge1xuICAgICAgICBoZWFkZXIoJ0NvbnRlbnQtVHlwZTogYXBwbGljYXRpb24vanNvbjsgY2hhcnNldD11dGYtOCcpO1xuICAgICAgICBoZWFkZXIoJ0NhY2hlLUNvbnRyb2w6IG5vLXN0b3JlJyk7XG4gICAgICAgIGh0dHBfcmVzcG9uc2VfY29kZSgoaW50KSRjb2RpZ29IdHRwKTtcbiAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICRqc29uID0ganNvbl9lbmNvZGUoJGRhdG9zLCBKU09OX1VORVNDQVBFRF9VTklDT0RFIHwgSlNPTl9JTlZBTElEX1VURjhfU1VCU1RJVFVURSB8IEpTT05fVEhST1dfT05fRVJST1IpO1xuICAgICAgICB9IGNhdGNoIChUaHJvd2FibGUgJGVycm9yKSB7XG4gICAgICAgICAgICAkdGhpcy0+ZXJyb3JBY2Npb24oJGVycm9yLCAncmVzcHVlc3RhJyk7XG4gICAgICAgIH1cbiAgICAgICAgZWNobyAkanNvbjtcbiAgICAgICAgZXhpdDtcbiAgICB9XG5cbiAgICBwcml2YXRlIGZ1bmN0aW9uIGVycm9yQWNjaW9uKFRocm93YWJsZSAkZXJyb3IsIHN0cmluZyAkYWNjaW9uKTogdm9pZFxuICAgIHtcbiAgICAgICAgJHJlZmVyZW5jaWEgPSBiaW4yaGV4KHJhbmRvbV9ieXRlcyg4KSk7XG4gICAgICAgICRyZWdpc3RybyA9IGpzb25fZW5jb2RlKFsncmVmZXJlbmNpYSc9PiRyZWZlcmVuY2lhLCdhY2Npb24nPT4kYWNjaW9uLFxuICAgICAgICAgICAgJ2NsYXNlJz0+Z2V0X2NsYXNzKCRlcnJvciksJ2NvZGlnbyc9PihpbnQpJGVycm9yLT5nZXRDb2RlKCksXG4gICAgICAgICAgICAnYXJjaGl2byc9PmJhc2VuYW1lKCRlcnJvci0+Z2V0RmlsZSgpKSwnbGluZWEnPT4kZXJyb3ItPmdldExpbmUoKV0sIEpTT05fVU5FU0NBUEVEX1VOSUNPREUpO1xuICAgICAgICAvLyBObyByZWdpc3RyYXIgZm9ybXVsYXJpb3MsIGVubGFjZXMsIG5vdGFzLCBjcmVkZW5jaWFsZXMgbyBtZW5zYWplcyBTUUwuXG4gICAgICAgICRkaXJlY3RvcmlvID0gZGlybmFtZShfX0RJUl9fLDIpIC4gJy9zdG9yYWdlL2xvZ3MnO1xuICAgICAgICBpZiAoKGlzX2RpcigkZGlyZWN0b3JpbykgfHwgQG1rZGlyKCRkaXJlY3RvcmlvLDA3NTAsdHJ1ZSkpICYmIGlzX3dyaXRhYmxlKCRkaXJlY3RvcmlvKSkge1xuICAgICAgICAgICAgaWYgKCFAZXJyb3JfbG9nKCRyZWdpc3RybyAuIFBIUF9FT0wsMywkZGlyZWN0b3JpbyAuICcvYWdlbmRhX3JldW5pb25fanNvbi5sb2cnKSkgZXJyb3JfbG9nKCdbYWdlbmRhX3JldW5pb25fanNvbl0gJyAuICRyZWdpc3Rybyk7XG4gICAgICAgIH0gZWxzZSBlcnJvcl9sb2coJ1thZ2VuZGFfcmV1bmlvbl9qc29uXSAnIC4gJHJlZ2lzdHJvKTtcbiAgICAgICAgJHRoaXMtPnJlc3BvbmRlcihbJ29rJz0+ZmFsc2UsXG4gICAgICAgICAgICAnbWVuc2FqZSc9PidObyBmdWUgcG9zaWJsZSBjb21wbGV0YXIgbGEgb3BlcmFjacOzbi4gQWN0dWFsaXphIGxhIGFnZW5kYSBwYXJhIGNvbXByb2JhciBlbCBlc3RhZG8gZGUgbGEgcmV1bmnDs24gYW50ZXMgZGUgdm9sdmVyIGEgaW50ZW50YXJsby4gUmVmZXJlbmNpYTogJyAuICRyZWZlcmVuY2lhIC4gJy4nLFxuICAgICAgICAgICAgJ3JlZmVyZW5jaWEnPT4kcmVmZXJlbmNpYV0sNTAwKTtcbiAgICB9XG5cbiAgICBwcml2YXRlIGZ1bmN0aW9uIG5vbWJyZU1lcygkbWVzKVxuIn1dfSx7InBhdGgiOiJwdWJsaWMvamF2YXNjcmlwdC9hZ2VuZGFfcmV1bmlvbi5qcyIsImZyYWdtZW50cyI6W3siYmVmb3JlIjoiICAgICAgICAgICAgICAgICAgICAgICAgaGVhZGVyczogeyAnWC1SZXF1ZXN0ZWQtV2l0aCc6ICdmZXRjaCcgfVxuICAgICAgICAgICAgICAgICAgICB9XG4gICAgICAgICAgICAgICAgKTtcbiAgICAgICAgICAgICAgICBjb25zdCBqc29uID0gYXdhaXQgcmVzcHVlc3RhLmpzb24oKTtcblxuICAgICAgICAgICAgICAgIGlmICghcmVzcHVlc3RhLm9rIHx8ICFqc29uLm9rKSB7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihqc29uLm1lbnNhamUgfHwgJ05vIGZ1ZSBwb3NpYmxlIGd1YXJkYXIgbG9zIGNhbWJpb3MuJyk7XG4iLCJhZnRlciI6IiAgICAgICAgICAgICAgICAgICAgICAgIGhlYWRlcnM6IHsgJ1gtUmVxdWVzdGVkLVdpdGgnOiAnZmV0Y2gnIH1cbiAgICAgICAgICAgICAgICAgICAgfVxuICAgICAgICAgICAgICAgICk7XG4gICAgICAgICAgICAgICAgY29uc3QgdGV4dG8gPSBhd2FpdCByZXNwdWVzdGEudGV4dCgpO1xuICAgICAgICAgICAgICAgIGxldCBqc29uO1xuICAgICAgICAgICAgICAgIHRyeSB7XG4gICAgICAgICAgICAgICAgICAgIGpzb24gPSBKU09OLnBhcnNlKHRleHRvKTtcbiAgICAgICAgICAgICAgICAgICAgaWYgKCFqc29uIHx8IHR5cGVvZiBqc29uICE9PSAnb2JqZWN0JyB8fCBBcnJheS5pc0FycmF5KGpzb24pKSB0aHJvdyBuZXcgRXJyb3IoKTtcbiAgICAgICAgICAgICAgICB9IGNhdGNoIChfKSB7XG4gICAgICAgICAgICAgICAgICAgIHRocm93IG5ldyBFcnJvcihyZXNwdWVzdGEuc3RhdHVzID09PSA0MDEgfHwgcmVzcHVlc3RhLnJlZGlyZWN0ZWRcbiAgICAgICAgICAgICAgICAgICAgICAgID8gJ0xhIHNlc2nDs24gZXhwaXLDsy4gSW5pY2lhIHNlc2nDs24gbnVldmFtZW50ZSB5IGNvbXBydWViYSBlbCBlc3RhZG8gZGUgbGEgcmV1bmnDs24uJ1xuICAgICAgICAgICAgICAgICAgICAgICAgOiAnTm8gZnVlIHBvc2libGUgY29uZmlybWFyIGxhIHJlc3B1ZXN0YSBkZWwgc2Vydmlkb3IuIEFjdHVhbGl6YSBsYSBhZ2VuZGEgcGFyYSBjb21wcm9iYXIgZWwgZXN0YWRvIGRlIGxhIHJldW5pw7NuIGFudGVzIGRlIHZvbHZlciBhIGludGVudGFybG8uJyk7XG4gICAgICAgICAgICAgICAgfVxuXG4gICAgICAgICAgICAgICAgaWYgKCFyZXNwdWVzdGEub2sgfHwgIWpzb24ub2spIHtcbiAgICAgICAgICAgICAgICAgICAgdGhyb3cgbmV3IEVycm9yKGpzb24ubWVuc2FqZSB8fCAnTm8gZnVlIHBvc2libGUgZ3VhcmRhciBsb3MgY2FtYmlvcy4nKTtcbiJ9XX1d', true), true, 512, JSON_THROW_ON_ERROR);
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
            $backup=$backups.'/agenda-confirmacion-json-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
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
