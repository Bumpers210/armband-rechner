#!/usr/bin/env bash
set -euo pipefail
apache="$1"; modules="$2"; rules="$3"
fixture=$(mktemp -d /tmp/carmaja-offer-routing.XXXXXXXX)
pid=''
cleanup(){ result=$?; if [ "$result" -ne 0 ]; then cat "$fixture/server.log" "$fixture/error.log" 2>/dev/null || true; fi; if [ -n "$pid" ]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi; case "$fixture" in /tmp/carmaja-offer-routing.*) rm -rf -- "$fixture";; esac; }
trap cleanup EXIT
root="$fixture/site"
mkdir -p "$root/armbaender/cp-2026-9999-test" "$root/_internal" "$root/warenkorb" "$root/_next/static/chunks"
printf 'RewriteEngine On\n' > "$root/.htaccess"
awk '/# BEGIN CARMAJA SERVER OFFERS/{copy=1} copy{print} /# END CARMAJA SERVER OFFERS/{copy=0}' "$rules" >> "$root/.htaccess"
printf 'STATIC_PRODUCT' > "$root/armbaender/cp-2026-9999-test/index.html"
printf 'CATALOG' > "$root/armbaender/index.html"
printf 'CART' > "$root/warenkorb/index.html"
printf 'JS' > "$root/_next/static/chunks/fixture.js"
printf 'RUNTIME_TARGET' > "$root/product-offer.php"
printf 'runtime-present' > "$root/_internal/product-offers.php"
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
cat > "$fixture/httpd.conf" <<CONF
ServerRoot "$fixture"
ServerName localhost
Listen 127.0.0.1:$port
PidFile "$fixture/pid"
ErrorLog "$fixture/error.log"
LoadModule mpm_event_module "$modules/mod_mpm_event.so"
LoadModule authz_core_module "$modules/mod_authz_core.so"
LoadModule rewrite_module "$modules/mod_rewrite.so"
LoadModule mime_module "$modules/mod_mime.so"
TypesConfig /dev/null
LoadModule dir_module "$modules/mod_dir.so"
User $(id -un)
Group $(id -gn)
DocumentRoot "$root"
DirectoryIndex index.html
<Directory "$root">
AllowOverride All
Require all granted
</Directory>
CONF
"$apache" -t -f "$fixture/httpd.conf"
"$apache" -X -f "$fixture/httpd.conf" > "$fixture/server.log" 2>&1 & pid=$!
origin="http://127.0.0.1:$port"
for i in $(seq 1 50); do if curl --silent --fail "$origin/armbaender/" >/dev/null; then break; fi; sleep 0.1; done
for path in /armbaender/cp-2026-9999-test/ /armbaender/cp-2026-9999-test/index.html; do test "$(curl --silent --fail "$origin$path")" = RUNTIME_TARGET; done
test "$(curl --silent --fail "$origin/armbaender/")" = CATALOG
test "$(curl --silent --fail "$origin/warenkorb/")" = CART
test "$(curl --silent --fail "$origin/_next/static/chunks/fixture.js")" = JS
test "$(curl --silent -o /dev/null -w '%{http_code}' "$origin/armbaender/cp-2026-0006-athena/")" = 404
rm "$root/_internal/product-offers.php"
test "$(curl --silent --fail "$origin/armbaender/cp-2026-9999-test/")" = STATIC_PRODUCT
printf 'PRODUCT_OFFER_ROUTING_OK\n'
