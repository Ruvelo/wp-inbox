#!/usr/bin/env bash
# Builds (or rebuilds) the local demo site in .wp/: WordPress on SQLite with
# the plugin symlinked in (and Ruvelo Feedback, if it's checked out next to it)
# and Halyard's demo content. Then: wp server --port=8996
#   bin/dev-site.sh          create it if missing
#   bin/dev-site.sh --fresh  wipe the database and seed again
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
SITE=$ROOT/.wp
PORT=${PORT:-8996}
WP_VERSION=${WP_VERSION:-7.1.3}

if [ ! -f "$SITE/wp-load.php" ]; then
    mkdir -p "$SITE"
    wp core download --path="$SITE" --version="$WP_VERSION" --quiet
fi
if [ ! -d "$SITE/wp-content/plugins/sqlite-database-integration" ]; then
    tmp=$(mktemp -d)
    curl -sSL -o "$tmp/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
    unzip -q "$tmp/sqlite.zip" -d "$SITE/wp-content/plugins/"
    rm -rf "$tmp"
fi
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SITE/wp-content/plugins/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
    "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" > "$SITE/wp-content/db.php"
[ -f "$SITE/wp-config.php" ] || wp config create --path="$SITE" --dbname=wp --dbuser=x --dbpass=x --skip-check --quiet --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
PHP
ln -sfn "$ROOT" "$SITE/wp-content/plugins/ruvelo-inbox"
# Ruvelo Feedback next to it, when it's checked out alongside, to show the integration.
FEEDBACK=${FEEDBACK:-$ROOT/../wp-feedback}
[ -f "$FEEDBACK/ruvelo-feedback.php" ] && ln -sfn "$(cd "$FEEDBACK" && pwd)" "$SITE/wp-content/plugins/ruvelo-feedback"
mkdir -p "$SITE/wp-content/mu-plugins" && cp bin/screenshot-helper.php "$SITE/wp-content/mu-plugins/screenshots.php"

if [ "${1:-}" = "--fresh" ]; then
    find "$SITE/wp-content/database" -name ".ht.sqlite*" -delete
    rm -rf "$SITE"/wp-content/uploads
fi
if ! wp core is-installed --path="$SITE" 2>/dev/null; then
    wp core install --path="$SITE" --url="http://localhost:$PORT" --title=Halyard --admin_user=maya --admin_password=password --admin_email=maya@halyard.test --skip-email --quiet
    wp rewrite structure '/%postname%/' --path="$SITE" --quiet
    if [ -e "$SITE/wp-content/plugins/ruvelo-feedback" ]; then
        wp plugin activate ruvelo-feedback --path="$SITE" --quiet
        wp ruvelo-feedback demo --yes --path="$SITE"
    fi
    wp plugin activate ruvelo-inbox --path="$SITE" --quiet
    wp ruvelo-inbox demo --yes --path="$SITE"
    wp eval --path="$SITE" 'update_user_meta(1, $GLOBALS["wpdb"]->prefix."persisted_preferences", ["core/edit-post" => ["welcomeGuide" => false], "core" => ["fixedToolbar" => false], "_modified" => gmdate("c")]); update_user_meta(1, "show_welcome_panel", 0);'
    wp option update auto_update_core_major disabled --path="$SITE" --quiet
fi
echo "Ready: wp server --path=.wp --port=$PORT  (sign in as maya / password)"
