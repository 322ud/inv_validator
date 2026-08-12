#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_directory="${1:-$project_root/dist}"
app_id="kosit_validator"
app_version="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "$project_root/appinfo/info.xml")"

if [[ ! "$app_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Could not determine a valid app version" >&2
    exit 1
fi

staging_directory="$(mktemp -d)"
cleanup() {
    rm -rf -- "$staging_directory"
}
trap cleanup EXIT

package_root="$staging_directory/$app_id"
mkdir --parents "$package_root" "$output_directory"

release_entries=(
    appinfo
    config
    css
    img
    js
    lib
    templates
    README.md
)

for entry in "${release_entries[@]}"; do
    cp --archive "$project_root/$entry" "$package_root/"
done

mkdir --parents "$package_root/scripts"
cp --archive "$project_root/scripts/extract_embedded_xml.py" "$package_root/scripts/"

find "$package_root" -type d -exec chmod 0755 {} +
find "$package_root" -type f -exec chmod 0644 {} +

archive="$output_directory/${app_id}-${app_version}.tar.gz"
tar \
    --create \
    --gzip \
    --file "$archive" \
    --directory "$staging_directory" \
    --sort=name \
    --mtime='@0' \
    --owner=0 \
    --group=0 \
    --numeric-owner \
    --format=posix \
    --pax-option=delete=atime,delete=ctime \
    "$app_id"

(
    cd "$output_directory"
    sha256sum "$(basename "$archive")" > "$(basename "$archive").sha256"
)

echo "$archive"
