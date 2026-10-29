#!/usr/bin/env bash
# Sólo selector visual de hora: vista, CSS y JS del editor. Sin SQL ni envíos.
set -euo pipefail
command -v php >/dev/null || { echo 'Falta PHP CLI.' >&2;exit 1; }
umask 077
TMP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/impe-agenda-hora.XXXXXX")"
trap 'rm -f -- "$TMP_SCRIPT"' EXIT
export IMPE_AGENDA_PUBLICADOR="$0"
cat > "$TMP_SCRIPT" <<'IMPE_AGENDA_PHP'
<?php
// Publicación selectiva. Sólo archivos listados; ninguna consulta SQL ni envío.
if (PHP_SAPI !== 'cli') { exit(1); }
$version = 'agenda-hora-nativa-v1';
$files = json_decode(base64_decode('W3sicGF0aCI6ImFwcC92aWV3cy9zZWd1aW1pZW50b192aW5jdWxhY2lvbi9wYXJ0aWFscy9hZ2VuZGFfY29tZW50YXJpb3MucGhwIiwiZnJhZ21lbnRzIjpbeyJiZWZvcmUiOiIgICAgICAgIDxsYWJlbCBjbGFzcz1cInZpc3VhbGx5LWhpZGRlblwiIGZvcj1cImFnZW5kYUNvbWVudGFyaW9UZXh0b1wiPkNvbWVudGFyaW88L2xhYmVsPlxuICAgICAgICA8dGV4dGFyZWEgaWQ9XCJhZ2VuZGFDb21lbnRhcmlvVGV4dG9cIiBuYW1lPVwiY29tZW50YXJpb1wiIHJvd3M9XCI0XCIgbWF4bGVuZ3RoPVwiNTAwXCIgcGxhY2Vob2xkZXI9XCJFc2NyaWJlIHVuIGNvbWVudGFyaW/igKZcIiBhcmlhLWRlc2NyaWJlZGJ5PVwiYWdlbmRhQ29tZW50YXJpb0NvbnRhZG9yXCIgcmVxdWlyZWQ+PC90ZXh0YXJlYT5cbiAgICAgICAgPHAgaWQ9XCJhZ2VuZGFDb21lbnRhcmlvQ29udGFkb3JcIiBjbGFzcz1cImFnZW5kYS1jb21tZW50LWNvdW50ZXJcIiBkYXRhLWFnZW5kYS1jb21tZW50LWNvdW50ZXI+MCAvIDI1MDwvcD5cbiAgICAgICAgPHNwYW4gaWQ9XCJhZ2VuZGFDb21lbnRhcmlvSG9yYVRpdHVsb1wiIGNsYXNzPVwiYWdlbmRhLWNvbW1lbnQtaG91ci1sYWJlbFwiPkhvcmEgPHNwYW4+KG9wY2lvbmFsKTwvc3Bhbj48L3NwYW4+XG4gICAgICAgIDxkaXYgY2xhc3M9XCJhZ2VuZGEtY29tbWVudC1ob3VyLXJvd1wiPlxuICAgICAgICAgICAgPGRpdiBjbGFzcz1cImFnZW5kYS1jb21tZW50LXRpbWUtc2VsZWN0c1wiIHJvbGU9XCJncm91cFwiIGFyaWEtbGFiZWxsZWRieT1cImFnZW5kYUNvbWVudGFyaW9Ib3JhVGl0dWxvXCIgYXJpYS1kZXNjcmliZWRieT1cImFnZW5kYUNvbWVudGFyaW9Ib3JhQXl1ZGFcIj5cbiAgICAgICAgICAgICAgICA8c2VsZWN0IGlkPVwiYWdlbmRhQ29tZW50YXJpb0hvcmFcIiBuYW1lPVwiaG9yYV9ob3JhXCIgY2xhc3M9XCJmb3JtLXNlbGVjdCBzeXN0ZW0tZm9ybS1jb250cm9sXCIgYXJpYS1sYWJlbD1cIkhvcmEgZGVsIHJlY29yZGF0b3Jpb1wiIGRpc2FibGVkPlxuICAgICAgICAgICAgICAgICAgICA8b3B0aW9uIHZhbHVlPVwiXCI+SG9yYTwvb3B0aW9uPlxuICAgICAgICAgICAgICAgICAgICA8P3BocCBmb3IgKCRoPTA7ICRoPDI0OyAkaCsrKTogJGhoPXN0cl9wYWQoKHN0cmluZykkaCwyLCcwJyxTVFJfUEFEX0xFRlQpOyA/PlxuICAgICAgICAgICAgICAgICAgICAgICAgPG9wdGlvbiB2YWx1ZT1cIjw/PSAkaGggPz5cIj48Pz0gJGhoID8+PC9vcHRpb24+XG4gICAgICAgICAgICAgICAgICAgIDw/cGhwIGVuZGZvcjsgPz5cbiAgICAgICAgICAgICAgICA8L3NlbGVjdD5cbiAgICAgICAgICAgICAgICA8c3BhbiBhcmlhLWhpZGRlbj1cInRydWVcIj46PC9zcGFuPlxuICAgICAgICAgICAgICAgIDxzZWxlY3QgbmFtZT1cImhvcmFfbWludXRvXCIgY2xhc3M9XCJmb3JtLXNlbGVjdCBzeXN0ZW0tZm9ybS1jb250cm9sXCIgYXJpYS1sYWJlbD1cIk1pbnV0b3MgZGVsIHJlY29yZGF0b3Jpb1wiIGRpc2FibGVkPlxuICAgICAgICAgICAgICAgICAgICA8b3B0aW9uIHZhbHVlPVwiXCI+TWludXRvczwvb3B0aW9uPlxuICAgICAgICAgICAgICAgICAgICA8P3BocCBmb3IgKCRtPTA7ICRtPDYwOyAkbSsrKTogJG1tPXN0cl9wYWQoKHN0cmluZykkbSwyLCcwJyxTVFJfUEFEX0xFRlQpOyA/PlxuICAgICAgICAgICAgICAgICAgICAgICAgPG9wdGlvbiB2YWx1ZT1cIjw/PSAkbW0gPz5cIj48Pz0gJG1tID8+PC9vcHRpb24+XG4gICAgICAgICAgICAgICAgICAgIDw/cGhwIGVuZGZvcjsgPz5cbiAgICAgICAgICAgICAgICA8L3NlbGVjdD5cbiAgICAgICAgICAgIDwvZGl2PlxuICAgICAgICAgICAgPGxhYmVsIGNsYXNzPVwiYWdlbmRhLWNvbW1lbnQtc3dpdGNoXCI+PGlucHV0IHR5cGU9XCJjaGVja2JveFwiIHJvbGU9XCJzd2l0Y2hcIiBuYW1lPVwic2luX2hvcmFcIiBjaGVja2VkPiBTaW4gaG9yYTwvbGFiZWw+XG4gICAgICAgIDwvZGl2PlxuICAgICAgICA8cCBjbGFzcz1cImFnZW5kYS1jb21tZW50cy1oaW50XCIgaWQ9XCJhZ2VuZGFDb21lbnRhcmlvSG9yYUF5dWRhXCI+Rm9ybWF0byBkZSAyNCBob3Jhcy4gQ29uIGhvcmEsIHJlY2liaXLDoXMgZWwgcmVjb3JkYXRvcmlvIGhhYml0dWFsLiBTaW4gaG9yYSwgc8OzbG8gdW4gYXZpc28gYWwgYcOxYWRpcmxvLjwvcD5cbiAgICAgICAgPGRpdiBjbGFzcz1cImFnZW5kYS1jb21tZW50LXByaXZhY3lcIj5cbiAgICAgICAgICAgIDxsYWJlbCBjbGFzcz1cImFnZW5kYS1jb21tZW50LXN3aXRjaFwiPjxpbnB1dCB0eXBlPVwiY2hlY2tib3hcIiByb2xlPVwic3dpdGNoXCIgbmFtZT1cInByaXZhZG9cIiBhcmlhLWRlc2NyaWJlZGJ5PVwiYWdlbmRhQ29tZW50YXJpb1ByaXZhZG9BeXVkYVwiPiBDb21lbnRhcmlvIHByaXZhZG8gPHN0cm9uZyBkYXRhLWFnZW5kYS1jb21tZW50LXByaXZhdGUtdmFsdWU+Tm88L3N0cm9uZz48L2xhYmVsPlxuICAgICAgICAgICAgPHAgY2xhc3M9XCJhZ2VuZGEtY29tbWVudHMtaGludFwiIGlkPVwiYWdlbmRhQ29tZW50YXJpb1ByaXZhZG9BeXVkYVwiPkNvbXBhcnRpZG8gY29uIGxvcyB1c3VhcmlvcyBxdWUgdGllbmVuIGFjY2VzbyBhIEFnZW5kYS48L3A+XG4iLCJhZnRlciI6IiAgICAgICAgPGxhYmVsIGNsYXNzPVwidmlzdWFsbHktaGlkZGVuXCIgZm9yPVwiYWdlbmRhQ29tZW50YXJpb1RleHRvXCI+Q29tZW50YXJpbzwvbGFiZWw+XG4gICAgICAgIDx0ZXh0YXJlYSBpZD1cImFnZW5kYUNvbWVudGFyaW9UZXh0b1wiIG5hbWU9XCJjb21lbnRhcmlvXCIgcm93cz1cIjRcIiBtYXhsZW5ndGg9XCI1MDBcIiBwbGFjZWhvbGRlcj1cIkVzY3JpYmUgdW4gY29tZW50YXJpb+KAplwiIGFyaWEtZGVzY3JpYmVkYnk9XCJhZ2VuZGFDb21lbnRhcmlvQ29udGFkb3JcIiByZXF1aXJlZD48L3RleHRhcmVhPlxuICAgICAgICA8cCBpZD1cImFnZW5kYUNvbWVudGFyaW9Db250YWRvclwiIGNsYXNzPVwiYWdlbmRhLWNvbW1lbnQtY291bnRlclwiIGRhdGEtYWdlbmRhLWNvbW1lbnQtY291bnRlcj4wIC8gMjUwPC9wPlxuICAgICAgICA8bGFiZWwgZm9yPVwiYWdlbmRhQ29tZW50YXJpb0hvcmFcIiBjbGFzcz1cImFnZW5kYS1jb21tZW50LWhvdXItbGFiZWxcIj5Ib3JhIDxzcGFuPihvcGNpb25hbCk8L3NwYW4+PC9sYWJlbD5cbiAgICAgICAgPGRpdiBjbGFzcz1cImFnZW5kYS1jb21tZW50LWhvdXItcm93XCI+XG4gICAgICAgICAgICA8aW5wdXQgaWQ9XCJhZ2VuZGFDb21lbnRhcmlvSG9yYVwiIG5hbWU9XCJob3JhXCIgY2xhc3M9XCJmb3JtLWNvbnRyb2wgc3lzdGVtLWZvcm0tY29udHJvbFwiIHR5cGU9XCJ0aW1lXCIgbGFuZz1cImVzLU1YXCIgc3RlcD1cIjYwXCIgZGlzYWJsZWQgYXJpYS1kZXNjcmliZWRieT1cImFnZW5kYUNvbWVudGFyaW9Ib3JhQXl1ZGFcIj5cbiAgICAgICAgICAgIDxsYWJlbCBjbGFzcz1cImFnZW5kYS1jb21tZW50LXN3aXRjaFwiPjxpbnB1dCB0eXBlPVwiY2hlY2tib3hcIiByb2xlPVwic3dpdGNoXCIgbmFtZT1cInNpbl9ob3JhXCIgY2hlY2tlZD4gU2luIGhvcmE8L2xhYmVsPlxuICAgICAgICA8L2Rpdj5cbiAgICAgICAgPHAgY2xhc3M9XCJhZ2VuZGEtY29tbWVudHMtaGludFwiIGlkPVwiYWdlbmRhQ29tZW50YXJpb0hvcmFBeXVkYVwiPlB1bHNhIGVsIHJlbG9qIHBhcmEgZWxlZ2lyIGhvcmEsIG1pbnV0b3MgeSBhLiBtLi9wLiBtLiBDb24gaG9yYSwgcmVjaWJpcsOhcyBlbCByZWNvcmRhdG9yaW8gaGFiaXR1YWwuIFNpbiBob3JhLCBzw7NsbyB1biBhdmlzbyBhbCBhw7FhZGlybG8uPC9wPlxuICAgICAgICA8ZGl2IGNsYXNzPVwiYWdlbmRhLWNvbW1lbnQtcHJpdmFjeVwiPlxuICAgICAgICAgICAgPGxhYmVsIGNsYXNzPVwiYWdlbmRhLWNvbW1lbnQtc3dpdGNoXCI+PGlucHV0IHR5cGU9XCJjaGVja2JveFwiIHJvbGU9XCJzd2l0Y2hcIiBuYW1lPVwicHJpdmFkb1wiIGFyaWEtZGVzY3JpYmVkYnk9XCJhZ2VuZGFDb21lbnRhcmlvUHJpdmFkb0F5dWRhXCI+IENvbWVudGFyaW8gcHJpdmFkbyA8c3Ryb25nIGRhdGEtYWdlbmRhLWNvbW1lbnQtcHJpdmF0ZS12YWx1ZT5Obzwvc3Ryb25nPjwvbGFiZWw+XG4gICAgICAgICAgICA8cCBjbGFzcz1cImFnZW5kYS1jb21tZW50cy1oaW50XCIgaWQ9XCJhZ2VuZGFDb21lbnRhcmlvUHJpdmFkb0F5dWRhXCI+Q29tcGFydGlkbyBjb24gbG9zIHVzdWFyaW9zIHF1ZSB0aWVuZW4gYWNjZXNvIGEgQWdlbmRhLjwvcD5cbiJ9XX0seyJwYXRoIjoicHVibGljL2Nzcy9hZ2VuZGFfY29tZW50YXJpb3MuY3NzIiwiZnJhZ21lbnRzIjpbeyJiZWZvcmUiOiIuYWdlbmRhLWNvbW1lbnQtZWRpdG9yOjpiYWNrZHJvcCB7IGJhY2tncm91bmQ6IzE3MmI0NjEyOyB9XG4uYWdlbmRhLWNvbW1lbnQtZGVsZXRlOjpiYWNrZHJvcCB7IGJhY2tncm91bmQ6IzE3MmI0NjMzOyB9XG4uYWdlbmRhLWNvbW1lbnQtZWRpdG9yIHRleHRhcmVhIHsgZGlzcGxheTpibG9jazt3aWR0aDoxMDAlO3Jlc2l6ZTp2ZXJ0aWNhbDttaW4taGVpZ2h0OjEwNXB4O21heC1oZWlnaHQ6NDB2aDttYXJnaW4tdG9wOjE4cHg7Ym94LXNpemluZzpib3JkZXItYm94O3BhZGRpbmc6MTBweCAxMnB4O2JvcmRlcjoxcHggc29saWQgI2Q1ZTBlZjtib3JkZXItcmFkaXVzOjhweDtjb2xvcjojMzA0YjZjO2ZvbnQ6aW5oZXJpdDtmb250LXNpemU6MTNweDtsaW5lLWhlaWdodDoxLjU7YmFja2dyb3VuZDp3aGl0ZTsgfVxuLmFnZW5kYS1jb21tZW50LWVkaXRvciB0ZXh0YXJlYTpmb2N1cywuYWdlbmRhLWNvbW1lbnQtZWRpdG9yIHNlbGVjdDpmb2N1cyB7IG91dGxpbmU6M3B4IHNvbGlkICNkNWU2ZmY7Ym9yZGVyLWNvbG9yOiM1NTk1ZjY7IH1cbi5hZ2VuZGEtY29tbWVudC1lZGl0b3IgdGV4dGFyZWFbYXJpYS1pbnZhbGlkPXRydWVdIHsgYm9yZGVyLWNvbG9yOiNjZjY3Njc7IH1cbi5hZ2VuZGEtY29tbWVudC1jb3VudGVyIHsgdGV4dC1hbGlnbjpyaWdodDtmb250LXNpemU6MTJweDtjb2xvcjojNzU4MzliO21hcmdpbjo1cHggMCAxNXB4OyB9XG4uYWdlbmRhLWNvbW1lbnQtaG91ci1sYWJlbCB7IGRpc3BsYXk6YmxvY2s7Zm9udC1zaXplOjEycHg7Zm9udC13ZWlnaHQ6NjUwO21hcmdpbi1ib3R0b206OHB4OyB9XG4uYWdlbmRhLWNvbW1lbnQtaG91ci1sYWJlbCA+IHNwYW4geyBjb2xvcjojNzY4NDliO2ZvbnQtd2VpZ2h0OjQwMDsgfVxuLmFnZW5kYS1jb21tZW50LWhvdXItcm93IHsgZGlzcGxheTpmbGV4O2FsaWduLWl0ZW1zOmNlbnRlcjtnYXA6MTZweDsgfVxuLmFnZW5kYS1jb21tZW50LXRpbWUtc2VsZWN0cyB7IGRpc3BsYXk6ZmxleDthbGlnbi1pdGVtczpjZW50ZXI7Z2FwOjZweDtmbGV4OjE7bWluLXdpZHRoOjA7IH1cbi5hZ2VuZGEtY29tbWVudC10aW1lLXNlbGVjdHMgc2VsZWN0IHsgZmxleDoxO21pbi13aWR0aDowO21pbi1oZWlnaHQ6NDBweDtmb250LXNpemU6MTNweDtwYWRkaW5nOjhweCAyNXB4IDhweCA4cHg7Ym9yZGVyLWNvbG9yOiNkNWUwZWY7Ym9yZGVyLXJhZGl1czo3cHg7YmFja2dyb3VuZC1wb3NpdGlvbjpyaWdodCA3cHggY2VudGVyOyB9XG4uYWdlbmRhLWNvbW1lbnQtdGltZS1zZWxlY3RzIHNlbGVjdDpkaXNhYmxlZCB7IGJhY2tncm91bmQtY29sb3I6I2Y1ZjdmYTtjb2xvcjojNzY4NDliOyB9XG4uYWdlbmRhLWNvbW1lbnQtc3dpdGNoIHsgZGlzcGxheTpmbGV4O2FsaWduLWl0ZW1zOmNlbnRlcjtnYXA6OHB4O2ZvbnQtc2l6ZToxMnB4O2NvbG9yOiM1NzcwOTc7Y3Vyc29yOnBvaW50ZXI7IH1cbi5hZ2VuZGEtY29tbWVudC1ob3VyLXJvdyAuYWdlbmRhLWNvbW1lbnQtc3dpdGNoIHsgd2hpdGUtc3BhY2U6bm93cmFwOyB9XG4uYWdlbmRhLWNvbW1lbnQtc3dpdGNoIGlucHV0IHsgYXBwZWFyYW5jZTpub25lOy13ZWJraXQtYXBwZWFyYW5jZTpub25lO2ZsZXgtc2hyaW5rOjA7d2lkdGg6MzJweDtoZWlnaHQ6MThweDtib3JkZXI6MXB4IHNvbGlkICM5Y2E4Yjg7Ym9yZGVyLXJhZGl1czoxMnB4O2JhY2tncm91bmQ6I2U1ZTllZjtwb3NpdGlvbjpyZWxhdGl2ZTtjdXJzb3I6cG9pbnRlcjt0cmFuc2l0aW9uOmJhY2tncm91bmQtY29sb3IgLjE1czsgfVxuIiwiYWZ0ZXIiOiIuYWdlbmRhLWNvbW1lbnQtZWRpdG9yOjpiYWNrZHJvcCB7IGJhY2tncm91bmQ6IzE3MmI0NjEyOyB9XG4uYWdlbmRhLWNvbW1lbnQtZGVsZXRlOjpiYWNrZHJvcCB7IGJhY2tncm91bmQ6IzE3MmI0NjMzOyB9XG4uYWdlbmRhLWNvbW1lbnQtZWRpdG9yIHRleHRhcmVhIHsgZGlzcGxheTpibG9jazt3aWR0aDoxMDAlO3Jlc2l6ZTp2ZXJ0aWNhbDttaW4taGVpZ2h0OjEwNXB4O21heC1oZWlnaHQ6NDB2aDttYXJnaW4tdG9wOjE4cHg7Ym94LXNpemluZzpib3JkZXItYm94O3BhZGRpbmc6MTBweCAxMnB4O2JvcmRlcjoxcHggc29saWQgI2Q1ZTBlZjtib3JkZXItcmFkaXVzOjhweDtjb2xvcjojMzA0YjZjO2ZvbnQ6aW5oZXJpdDtmb250LXNpemU6MTNweDtsaW5lLWhlaWdodDoxLjU7YmFja2dyb3VuZDp3aGl0ZTsgfVxuLmFnZW5kYS1jb21tZW50LWVkaXRvciB0ZXh0YXJlYTpmb2N1cywuYWdlbmRhLWNvbW1lbnQtZWRpdG9yIGlucHV0W3R5cGU9dGltZV06Zm9jdXMgeyBvdXRsaW5lOjNweCBzb2xpZCAjZDVlNmZmO2JvcmRlci1jb2xvcjojNTU5NWY2OyB9XG4uYWdlbmRhLWNvbW1lbnQtZWRpdG9yIHRleHRhcmVhW2FyaWEtaW52YWxpZD10cnVlXSB7IGJvcmRlci1jb2xvcjojY2Y2NzY3OyB9XG4uYWdlbmRhLWNvbW1lbnQtY291bnRlciB7IHRleHQtYWxpZ246cmlnaHQ7Zm9udC1zaXplOjEycHg7Y29sb3I6Izc1ODM5YjttYXJnaW46NXB4IDAgMTVweDsgfVxuLmFnZW5kYS1jb21tZW50LWhvdXItbGFiZWwgeyBkaXNwbGF5OmJsb2NrO2ZvbnQtc2l6ZToxMnB4O2ZvbnQtd2VpZ2h0OjY1MDttYXJnaW4tYm90dG9tOjhweDsgfVxuLmFnZW5kYS1jb21tZW50LWhvdXItbGFiZWwgPiBzcGFuIHsgY29sb3I6Izc2ODQ5Yjtmb250LXdlaWdodDo0MDA7IH1cbi5hZ2VuZGEtY29tbWVudC1ob3VyLXJvdyB7IGRpc3BsYXk6ZmxleDthbGlnbi1pdGVtczpjZW50ZXI7Z2FwOjE2cHg7IH1cbi5hZ2VuZGEtY29tbWVudC1ob3VyLXJvdyA+IGlucHV0W3R5cGU9dGltZV0geyBmbGV4OjE7bWluLXdpZHRoOjA7bWluLWhlaWdodDo0MHB4O2ZvbnQtc2l6ZToxM3B4O3BhZGRpbmc6OHB4IDEwcHg7Ym9yZGVyLWNvbG9yOiNkNWUwZWY7Ym9yZGVyLXJhZGl1czo3cHg7IH1cbi5hZ2VuZGEtY29tbWVudC1ob3VyLXJvdyA+IGlucHV0W3R5cGU9dGltZV06ZGlzYWJsZWQgeyBiYWNrZ3JvdW5kLWNvbG9yOiNmNWY3ZmE7Y29sb3I6Izc2ODQ5YjsgfVxuLmFnZW5kYS1jb21tZW50LXN3aXRjaCB7IGRpc3BsYXk6ZmxleDthbGlnbi1pdGVtczpjZW50ZXI7Z2FwOjhweDtmb250LXNpemU6MTJweDtjb2xvcjojNTc3MDk3O2N1cnNvcjpwb2ludGVyOyB9XG4uYWdlbmRhLWNvbW1lbnQtaG91ci1yb3cgLmFnZW5kYS1jb21tZW50LXN3aXRjaCB7IHdoaXRlLXNwYWNlOm5vd3JhcDsgfVxuLmFnZW5kYS1jb21tZW50LXN3aXRjaCBpbnB1dCB7IGFwcGVhcmFuY2U6bm9uZTstd2Via2l0LWFwcGVhcmFuY2U6bm9uZTtmbGV4LXNocmluazowO3dpZHRoOjMycHg7aGVpZ2h0OjE4cHg7Ym9yZGVyOjFweCBzb2xpZCAjOWNhOGI4O2JvcmRlci1yYWRpdXM6MTJweDtiYWNrZ3JvdW5kOiNlNWU5ZWY7cG9zaXRpb246cmVsYXRpdmU7Y3Vyc29yOnBvaW50ZXI7dHJhbnNpdGlvbjpiYWNrZ3JvdW5kLWNvbG9yIC4xNXM7IH1cbiJ9XX0seyJwYXRoIjoicHVibGljL2phdmFzY3JpcHQvYWdlbmRhX2NvbWVudGFyaW9zLmpzIiwiZnJhZ21lbnRzIjpbeyJiZWZvcmUiOiIgICAgICAgIGNvbnN0IHJlbW92ZURpYWxvZyA9IHJvb3QucXVlcnlTZWxlY3RvcignW2RhdGEtYWdlbmRhLWNvbW1lbnQtZGVsZXRlXScpO1xuICAgICAgICBjb25zdCBmb3JtID0gZWRpdG9yLnF1ZXJ5U2VsZWN0b3IoJ2Zvcm0nKTtcbiAgICAgICAgY29uc3QgdGV4dCA9IGZvcm0uZWxlbWVudHMuY29tZW50YXJpbztcbiAgICAgICAgY29uc3QgaG91ciA9IGZvcm0uZWxlbWVudHMuaG9yYV9ob3JhO1xuICAgICAgICBjb25zdCBtaW51dGUgPSBmb3JtLmVsZW1lbnRzLmhvcmFfbWludXRvO1xuICAgICAgICBjb25zdCBub0hvdXIgPSBmb3JtLmVsZW1lbnRzLnNpbl9ob3JhO1xuICAgICAgICBjb25zdCBwcml2YXRlQ29tbWVudCA9IGZvcm0uZWxlbWVudHMucHJpdmFkbztcbiAgICAgICAgbGV0IHByaXZhY3lBdmFpbGFibGUgPSBmYWxzZTtcbiAgICAgICAgZnVuY3Rpb24gdXBkYXRlT3B0aW9ucygpIHtcbiAgICAgICAgICAgIGhvdXIuZGlzYWJsZWQgPSBtaW51dGUuZGlzYWJsZWQgPSBub0hvdXIuY2hlY2tlZDtcbiAgICAgICAgICAgIHByaXZhdGVDb21tZW50LmRpc2FibGVkID0gIXByaXZhY3lBdmFpbGFibGU7XG4gICAgICAgICAgICBmb3JtLnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWFnZW5kYS1jb21tZW50LXByaXZhdGUtdmFsdWVdJykudGV4dENvbnRlbnQgPSBwcml2YXRlQ29tbWVudC5jaGVja2VkID8gJ1PDrScgOiAnTm8nO1xuICAgICAgICAgICAgZm9ybS5xdWVyeVNlbGVjdG9yKCcjYWdlbmRhQ29tZW50YXJpb1ByaXZhZG9BeXVkYScpLnRleHRDb250ZW50ID0gIXByaXZhY3lBdmFpbGFibGVcbiIsImFmdGVyIjoiICAgICAgICBjb25zdCByZW1vdmVEaWFsb2cgPSByb290LnF1ZXJ5U2VsZWN0b3IoJ1tkYXRhLWFnZW5kYS1jb21tZW50LWRlbGV0ZV0nKTtcbiAgICAgICAgY29uc3QgZm9ybSA9IGVkaXRvci5xdWVyeVNlbGVjdG9yKCdmb3JtJyk7XG4gICAgICAgIGNvbnN0IHRleHQgPSBmb3JtLmVsZW1lbnRzLmNvbWVudGFyaW87XG4gICAgICAgIGNvbnN0IGhvdXIgPSBmb3JtLmVsZW1lbnRzLmhvcmE7XG4gICAgICAgIGNvbnN0IG5vSG91ciA9IGZvcm0uZWxlbWVudHMuc2luX2hvcmE7XG4gICAgICAgIGNvbnN0IHByaXZhdGVDb21tZW50ID0gZm9ybS5lbGVtZW50cy5wcml2YWRvO1xuICAgICAgICBsZXQgcHJpdmFjeUF2YWlsYWJsZSA9IGZhbHNlO1xuICAgICAgICBmdW5jdGlvbiB1cGRhdGVPcHRpb25zKCkge1xuICAgICAgICAgICAgaG91ci5kaXNhYmxlZCA9IG5vSG91ci5jaGVja2VkO1xuICAgICAgICAgICAgcHJpdmF0ZUNvbW1lbnQuZGlzYWJsZWQgPSAhcHJpdmFjeUF2YWlsYWJsZTtcbiAgICAgICAgICAgIGZvcm0ucXVlcnlTZWxlY3RvcignW2RhdGEtYWdlbmRhLWNvbW1lbnQtcHJpdmF0ZS12YWx1ZV0nKS50ZXh0Q29udGVudCA9IHByaXZhdGVDb21tZW50LmNoZWNrZWQgPyAnU8OtJyA6ICdObyc7XG4gICAgICAgICAgICBmb3JtLnF1ZXJ5U2VsZWN0b3IoJyNhZ2VuZGFDb21lbnRhcmlvUHJpdmFkb0F5dWRhJykudGV4dENvbnRlbnQgPSAhcHJpdmFjeUF2YWlsYWJsZVxuIn0seyJiZWZvcmUiOiIgICAgICAgIHRleHQuYWRkRXZlbnRMaXN0ZW5lcignaW5wdXQnLGNvdW50ZXIpO1xuICAgICAgICBub0hvdXIuYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJywoKT0+e1xuICAgICAgICAgICAgdXBkYXRlT3B0aW9ucygpO1xuICAgICAgICAgICAgaWYobm9Ib3VyLmNoZWNrZWQpIHtob3VyLnZhbHVlPScnO21pbnV0ZS52YWx1ZT0nJzt9IGVsc2UgaG91ci5mb2N1cygpO1xuICAgICAgICB9KTtcbiAgICAgICAgcHJpdmF0ZUNvbW1lbnQuYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJyx1cGRhdGVPcHRpb25zKTtcbiAgICAgICAgZm9ybS5hZGRFdmVudExpc3RlbmVyKCdzdWJtaXQnLGFzeW5jIGV2ZW50PT57XG4iLCJhZnRlciI6IiAgICAgICAgdGV4dC5hZGRFdmVudExpc3RlbmVyKCdpbnB1dCcsY291bnRlcik7XG4gICAgICAgIG5vSG91ci5hZGRFdmVudExpc3RlbmVyKCdjaGFuZ2UnLCgpPT57XG4gICAgICAgICAgICB1cGRhdGVPcHRpb25zKCk7XG4gICAgICAgICAgICBpZihub0hvdXIuY2hlY2tlZCkgaG91ci52YWx1ZT0nJztlbHNlIGhvdXIuZm9jdXMoKTtcbiAgICAgICAgfSk7XG4gICAgICAgIHByaXZhdGVDb21tZW50LmFkZEV2ZW50TGlzdGVuZXIoJ2NoYW5nZScsdXBkYXRlT3B0aW9ucyk7XG4gICAgICAgIGZvcm0uYWRkRXZlbnRMaXN0ZW5lcignc3VibWl0Jyxhc3luYyBldmVudD0+e1xuIn0seyJiZWZvcmUiOiIgICAgICAgICAgICBpZiAocHJpdmF0ZUNvbW1lbnQuY2hlY2tlZCYmIXByaXZhY3lBdmFpbGFibGUpIHtcbiAgICAgICAgICAgICAgICBlcnJvci50ZXh0Q29udGVudD0nTm8gc2UgcHVlZGUgZ3VhcmRhciBlbCBjb21lbnRhcmlvIHByaXZhZG8gZW4gZXN0ZSBtb21lbnRvLic7ZXJyb3IuaGlkZGVuPWZhbHNlO3JldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICghY29udGVudHx8QXJyYXkuZnJvbShjb250ZW50KS5sZW5ndGg+MjUwfHwoIW5vSG91ci5jaGVja2VkJiYoIWhvdXIudmFsdWV8fCFtaW51dGUudmFsdWUpKSkge1xuICAgICAgICAgICAgICAgIGVycm9yLnRleHRDb250ZW50PSFjb250ZW50fHxBcnJheS5mcm9tKGNvbnRlbnQpLmxlbmd0aD4yNTA/J0VzY3JpYmUgZW50cmUgMSB5IDI1MCBjYXJhY3RlcmVzLic6J1NlbGVjY2lvbmEgbGEgaG9yYSB5IGxvcyBtaW51dG9zIG8gYWN0aXZhIMKrU2luIGhvcmHCuy4nO2Vycm9yLmhpZGRlbj1mYWxzZTtyZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBzYXZpbmc9dHJ1ZTtlcnJvci5oaWRkZW49dHJ1ZTtcbiAgICAgICAgICAgIGZvcm0ucXVlcnlTZWxlY3RvcignW2RhdGEtYWdlbmRhLWNvbW1lbnQtc2F2ZV0nKS5kaXNhYmxlZD10cnVlO1xuICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICBjb25zdCBzYXZlZD1hd2FpdCByZXF1ZXN0KCdjcmVhcicse2ZlY2hhOnNlbGVjdGVkLGNvbWVudGFyaW86Y29udGVudCxob3JhOm5vSG91ci5jaGVja2VkPycnOmhvdXIudmFsdWUrJzonK21pbnV0ZS52YWx1ZSxwcml2YWRvOnByaXZhdGVDb21tZW50LmNoZWNrZWQ/JzEnOicwJ30pO1xuICAgICAgICAgICAgICAgIGNoYW5nZVZlcnNpb24rKztcbiAgICAgICAgICAgICAgICBlZGl0b3IuY2xvc2UoKTttb3N0cmFyQ29uZmlybWFjaW9uQ29tZW50YXJpbygnQ29tZW50YXJpbyBhw7FhZGlkbyBhIGxhIGFnZW5kYS4nKTtcbiAgICAgICAgICAgICAgICByZWZyZXNoQmVsbChzYXZlZC5jb21lbnRhcmlvPy5pZCk7YXdhaXQgbG9hZCgpO1xuIiwiYWZ0ZXIiOiIgICAgICAgICAgICBpZiAocHJpdmF0ZUNvbW1lbnQuY2hlY2tlZCYmIXByaXZhY3lBdmFpbGFibGUpIHtcbiAgICAgICAgICAgICAgICBlcnJvci50ZXh0Q29udGVudD0nTm8gc2UgcHVlZGUgZ3VhcmRhciBlbCBjb21lbnRhcmlvIHByaXZhZG8gZW4gZXN0ZSBtb21lbnRvLic7ZXJyb3IuaGlkZGVuPWZhbHNlO3JldHVybjtcbiAgICAgICAgICAgIH1cbiAgICAgICAgICAgIGlmICghY29udGVudHx8QXJyYXkuZnJvbShjb250ZW50KS5sZW5ndGg+MjUwfHwoIW5vSG91ci5jaGVja2VkJiYoIWhvdXIudmFsdWV8fCFob3VyLnZhbGlkaXR5LnZhbGlkKSkpIHtcbiAgICAgICAgICAgICAgICBlcnJvci50ZXh0Q29udGVudD0hY29udGVudHx8QXJyYXkuZnJvbShjb250ZW50KS5sZW5ndGg+MjUwPydFc2NyaWJlIGVudHJlIDEgeSAyNTAgY2FyYWN0ZXJlcy4nOidTZWxlY2Npb25hIHVuYSBob3JhIG8gYWN0aXZhIMKrU2luIGhvcmHCuy4nO2Vycm9yLmhpZGRlbj1mYWxzZTtyZXR1cm47XG4gICAgICAgICAgICB9XG4gICAgICAgICAgICBzYXZpbmc9dHJ1ZTtlcnJvci5oaWRkZW49dHJ1ZTtcbiAgICAgICAgICAgIGZvcm0ucXVlcnlTZWxlY3RvcignW2RhdGEtYWdlbmRhLWNvbW1lbnQtc2F2ZV0nKS5kaXNhYmxlZD10cnVlO1xuICAgICAgICAgICAgdHJ5IHtcbiAgICAgICAgICAgICAgICBjb25zdCBzYXZlZD1hd2FpdCByZXF1ZXN0KCdjcmVhcicse2ZlY2hhOnNlbGVjdGVkLGNvbWVudGFyaW86Y29udGVudCxob3JhOm5vSG91ci5jaGVja2VkPycnOmhvdXIudmFsdWUscHJpdmFkbzpwcml2YXRlQ29tbWVudC5jaGVja2VkPycxJzonMCd9KTtcbiAgICAgICAgICAgICAgICBjaGFuZ2VWZXJzaW9uKys7XG4gICAgICAgICAgICAgICAgZWRpdG9yLmNsb3NlKCk7bW9zdHJhckNvbmZpcm1hY2lvbkNvbWVudGFyaW8oJ0NvbWVudGFyaW8gYcOxYWRpZG8gYSBsYSBhZ2VuZGEuJyk7XG4gICAgICAgICAgICAgICAgcmVmcmVzaEJlbGwoc2F2ZWQuY29tZW50YXJpbz8uaWQpO2F3YWl0IGxvYWQoKTtcbiJ9XX1d', true), true, 512, JSON_THROW_ON_ERROR);
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
            $backup=$backups.'/agenda-hora-nativa-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
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
