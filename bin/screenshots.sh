#!/usr/bin/env bash
# Regenerates .wordpress-org/ (screenshots, banners, icons) from the local
# demo site. Run bin/dev-site.sh --fresh and `wp server --path=.wp --port=8996`
# first. Needs a Chrome or chrome-headless-shell binary (set CHROME=...).
set -euo pipefail
cd "$(dirname "$0")/.."

BASE=${BASE:-http://localhost:8996}
CHROME=${CHROME:-$(ls -d ~/.cache/ms-playwright/chromium_headless_shell-*/*/ 2>/dev/null | tail -1)chrome-headless-shell}
[ -x "$CHROME" ] || { echo "Chrome not found; set CHROME=/path/to/chrome" >&2; exit 1; }
OUT=${OUT:-$PWD/.wordpress-org}
mkdir -p "$OUT"

shot() { # file, url, width,height, [budget ms], [scale]
    local file=$1
    case $file in /*) ;; *) file=$PWD/$file ;; esac
    for attempt in 1 2 3; do # Headless Chrome sometimes hangs on a page; try again.
        timeout 90 "$CHROME" --headless --no-sandbox --hide-scrollbars --force-device-scale-factor="${5:-2}" \
            --virtual-time-budget="${4:-5000}" --window-size="$3" --screenshot="$file" "$2" 2>/dev/null >/dev/null && break
    done
    command -v convert >/dev/null && convert "$file" -strip -define png:compression-level=9 "$file"
    echo "$1"
}

MAYA="shot_as=maya"
shot "$OUT/screenshot-1.png" "$BASE/wp-admin/edit.php?$MAYA&shot_open=1"                                  1440,900
shot "$OUT/screenshot-2.png" "$BASE/wp-admin/admin.php?page=ruvelo-inbox&$MAYA"                            1440,1300
shot "$OUT/screenshot-3.png" "$BASE/wp-admin/admin.php?page=ruvelo-inbox-preferences&$MAYA"                1440,1500
shot "$OUT/screenshot-4.png" "$BASE/notifications/?$MAYA"                                                 1440,1200
shot "$OUT/screenshot-5.png" "$BASE/wp-admin/admin.php?page=ruvelo-inbox&$MAYA&shot_scheme=dark&shot_open=1" 1440,1000
shot "$OUT/screenshot-6.png" "$BASE/notifications/?$MAYA"                                                 390,844 5000 2
shot "$OUT/screenshot-7.png" "$BASE/wp-admin/admin.php?page=ruvelo-inbox-settings&$MAYA"                   1440,1700

# Banners and icons, from bin/art/.
if [ -f bin/art/banner.html ]; then
    convert "$OUT/screenshot-1.png" -crop 860x1120+1700+0 -resize 600x bin/art/bell-crop.png
    shot "$OUT/banner-772x250.png"   "file://$PWD/bin/art/banner.html" 772,250 3000 1
    shot "$OUT/banner-1544x500.png"  "file://$PWD/bin/art/banner.html" 772,250 3000 2
    "$CHROME" --headless --no-sandbox --hide-scrollbars --default-background-color=00000000 --force-device-scale-factor=1 --virtual-time-budget=3000 --window-size=128,128 --screenshot="$OUT/icon-128x128.png" "file://$PWD/bin/art/icon.html" 2>/dev/null >/dev/null
    "$CHROME" --headless --no-sandbox --hide-scrollbars --default-background-color=00000000 --force-device-scale-factor=2 --virtual-time-budget=3000 --window-size=128,128 --screenshot="$OUT/icon-256x256.png" "file://$PWD/bin/art/icon.html" 2>/dev/null >/dev/null
    rm -f bin/art/bell-crop.png
fi
echo "Done. Look at every image before committing."
