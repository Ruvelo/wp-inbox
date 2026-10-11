#!/usr/bin/env bash
# Builds build/ruvelo-inbox.zip: the plugin as WordPress.org and the GitHub
# release ship it. No Composer needed at runtime (the plugin has its own
# PSR-4 loader), so vendor/ isn't included. Used by `composer zip` and the
# release workflow.
set -euo pipefail
cd "$(dirname "$0")/.."

slug=ruvelo-inbox
out=build/$slug
rm -rf build && mkdir -p "$out/demo"
cp ruvelo-inbox.php uninstall.php readme.txt LICENSE "$out/"
cp -R src blocks templates assets languages "$out/"
cp demo/content.php "$out/demo/"

# PHP's ZipArchive, so the build needs nothing beyond PHP.
php -r '
$zip = new ZipArchive();
$zip->open("build/".$argv[1].".zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("build/".$argv[1], FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen("build/")));
}
$zip->close();
' "$slug"
echo "build/$slug.zip ($(du -h "build/$slug.zip" | cut -f1))"
