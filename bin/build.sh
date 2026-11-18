#!/bin/sh
# Builds the Lookout SPA without a JS toolchain: concatenates resources/js/*.js (in name order) into one ES module
# and copies the stylesheet. dist/ is committed, so consumers need no npm; the app serves it from the package
# (or from public/vendor/lookout after `php artisan vendor:publish --tag=lookout-assets`).
set -e
cd "$(dirname "$0")/.."
out=dist
mkdir -p "$out"
{ echo "// Built from resources/js by bin/build.sh. Do not edit."; cat resources/js/*.js; } > "$out/app.js"
{ echo "/* Built from resources/css by bin/build.sh. Do not edit. */"; cat resources/css/app.css; } > "$out/app.css"
echo "built $out/app.js ($(wc -c < "$out/app.js") bytes) and $out/app.css"
