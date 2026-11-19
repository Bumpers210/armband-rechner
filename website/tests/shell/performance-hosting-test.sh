#!/usr/bin/env bash
set -euo pipefail
apache_binary="$1"
modules="$2"
source_rules="$3"
fixture=$(mktemp -d /tmp/carmaja-apache-test.XXXXXXXX)
server_pid=''
cleanup() {
  if [ -n "$server_pid" ]; then kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true; fi
  case "$fixture" in /tmp/carmaja-apache-test.*) rm -rf -- "$fixture" ;; esac
}
trap cleanup EXIT
webroot="$fixture/site"
mkdir -p "$webroot/_next/static/chunks" "$webroot/images/product-previews/CP-2026-0001/aaaaaaaaaaaaaaaa/webp-v1" "$webroot/images/site-previews/v1/aaaaaaaaaaaaaaaa" "$webroot/images/products/CP-2026-0001" "$webroot/api" "$webroot/warenkorb"
awk '/# BEGIN CARMAJA STATIC PERFORMANCE/{copy=1} copy{print} /# END CARMAJA STATIC PERFORMANCE/{copy=0}' "$source_rules" > "$webroot/.htaccess"
test -s "$webroot/.htaccess"
printf '<h1>Local fixture</h1>\n' > "$webroot/index.html"
printf '<h1>Cart fixture</h1>\n' > "$webroot/warenkorb/index.html"
printf 'Header always set Cache-Control "no-store"\n' > "$webroot/api/.htaccess"
printf '{"priceMinor":1234}\n' > "$webroot/api/product.json"
for file in test.js test.css; do yes '/* local compressible fixture */' | head -1000 > "$webroot/_next/static/chunks/$file" || true; done
printf 'fixture' > "$webroot/images/product-previews/CP-2026-0001/aaaaaaaaaaaaaaaa/webp-v1/01-960.webp"
printf 'fixture' > "$webroot/images/site-previews/v1/aaaaaaaaaaaaaaaa/logo-128.png"
printf 'fixture' > "$webroot/images/products/CP-2026-0001/01.jpg"
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
cat > "$fixture/mime.types" <<'MIME'
text/html html
text/css css
application/javascript js
application/json json
image/webp webp
image/png png
image/jpeg jpg
MIME
cat > "$fixture/httpd.conf" <<CONF
ServerRoot "$fixture"
ServerName localhost
Listen 127.0.0.1:$port
PidFile "$fixture/server.pid"
ErrorLog "$fixture/error.log"
LoadModule mpm_event_module "$modules/mod_mpm_event.so"
LoadModule authz_core_module "$modules/mod_authz_core.so"
LoadModule mime_module "$modules/mod_mime.so"
LoadModule headers_module "$modules/mod_headers.so"
LoadModule filter_module "$modules/mod_filter.so"
LoadModule deflate_module "$modules/mod_deflate.so"
LoadModule dir_module "$modules/mod_dir.so"
User $(id -un)
Group $(id -gn)
TypesConfig "$fixture/mime.types"
DocumentRoot "$webroot"
DirectoryIndex index.html
<Directory "$webroot">
  AllowOverride All
  Require all granted
</Directory>
CONF
origin="http://127.0.0.1:$port"
start_server() {
  "$apache_binary" -t -f "$fixture/httpd.conf"
  "$apache_binary" -X -f "$fixture/httpd.conf" >"$fixture/server.log" 2>&1 &
  server_pid=$!
  for attempt in $(seq 1 50); do
    if curl --silent --fail "$origin/" >/dev/null; then return; fi
    if ! kill -0 "$server_pid" 2>/dev/null; then cat "$fixture/server.log" "$fixture/error.log"; return 1; fi
    sleep 0.1
  done
  cat "$fixture/server.log" "$fixture/error.log"
  return 1
}
start_server
for resource in /_next/static/chunks/test.js /_next/static/chunks/test.css; do
  curl --silent --fail -H 'Accept-Encoding: gzip' -D "$fixture/headers" "$origin$resource" -o "$fixture/body.gz"
  grep -qi '^Content-Encoding: gzip' "$fixture/headers"
  grep -qi '^Vary:.*Accept-Encoding' "$fixture/headers"
  grep -qi '^Cache-Control: public, max-age=31536000, immutable' "$fixture/headers"
  gzip -dc "$fixture/body.gz" > "$fixture/decoded"
  cmp "$fixture/decoded" "$webroot$resource"
  curl --silent --fail -H 'Accept-Encoding: identity' -D "$fixture/headers" "$origin$resource" -o "$fixture/decoded"
  ! grep -qi '^Content-Encoding: gzip' "$fixture/headers"
  cmp "$fixture/decoded" "$webroot$resource"
done
for resource in /images/product-previews/CP-2026-0001/aaaaaaaaaaaaaaaa/webp-v1/01-960.webp /images/site-previews/v1/aaaaaaaaaaaaaaaa/logo-128.png; do
  curl --silent --fail -D "$fixture/headers" "$origin$resource" -o /dev/null
  grep -qi '^Cache-Control: public, max-age=31536000, immutable' "$fixture/headers"
done
for resource in / /warenkorb/ /api/product.json /images/products/CP-2026-0001/01.jpg; do
  curl --silent --fail -D "$fixture/headers" "$origin$resource" -o /dev/null
  ! grep -qi '^Cache-Control:.*immutable' "$fixture/headers"
done
curl --silent --fail -D "$fixture/headers" "$origin/api/product.json" -o /dev/null
grep -qi '^Cache-Control: no-store' "$fixture/headers"
status=$(curl --silent -D "$fixture/headers" -o /dev/null -w '%{http_code}' "$origin/_next/static/chunks/missing.js")
test "$status" = 404
! grep -qi '^Cache-Control:.*immutable' "$fixture/headers"
kill "$server_pid"
wait "$server_pid" || true
server_pid=''
sed -i '/LoadModule headers_module/d; /LoadModule filter_module/d; /LoadModule deflate_module/d' "$fixture/httpd.conf"
start_server
curl --silent --fail "$origin/_next/static/chunks/test.js" -o "$fixture/decoded"
cmp "$fixture/decoded" "$webroot/_next/static/chunks/test.js"
printf 'APACHE_PERFORMANCE_OK: gzip, identity, Vary, versioned cache, API no-store, mutable originals, 404 and missing-module fallback.\n'
