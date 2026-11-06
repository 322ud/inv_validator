#!/usr/bin/env bash

set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
output_directory="${1:-$project_root/dist}"
app_id="$(sed -n 's:.*<id>\([^<]*\)</id>.*:\1:p' "$project_root/appinfo/info.xml")"
signing_key="${APP_SIGNING_KEY_FILE:-}"
signing_certificate="${APP_SIGNING_CERTIFICATE_FILE:-}"
nextcloud_occ="${NEXTCLOUD_OCC:-}"
require_signing="${APP_REQUIRE_SIGNING:-0}"
sign_package=0

if [[ ! "$app_id" =~ ^[a-z][a-z0-9_]*$ || ! "$require_signing" =~ ^[01]$ ]]; then
    echo "Invalid app ID or APP_REQUIRE_SIGNING (expected 0 or 1)" >&2
    exit 1
fi
if [[ "$require_signing" == 1 || -n "$signing_key" || -n "$signing_certificate" ]]; then
    for dependency in php openssl; do
        command -v "$dependency" >/dev/null || { echo "Required tool missing: $dependency" >&2; exit 1; }
    done
    if [[ ! -r "$signing_key" || ! -r "$signing_certificate" || ! -f "$nextcloud_occ" ]]; then
        echo "Signing requires APP_SIGNING_KEY_FILE, APP_SIGNING_CERTIFICATE_FILE and NEXTCLOUD_OCC" >&2
        exit 1
    fi
    signing_key="$(realpath "$signing_key")"
    signing_certificate="$(realpath "$signing_certificate")"
    nextcloud_occ="$(realpath "$nextcloud_occ")"
    php "$project_root/scripts/check-signing-material.php" "$signing_key" "$signing_certificate" "$app_id"
    sign_package=1
fi
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
output_directory="$(cd "$output_directory" && pwd)"

release_entries=(
    appinfo
    css
    img
    js
    lib
    LICENSE
    LICENSES
    templates
    THIRD_PARTY_NOTICES.md
    README.md
)

for entry in "${release_entries[@]}"; do
    cp --archive "$project_root/$entry" "$package_root/"
done

# Never ship a stale signature copied from a source checkout.
rm --force "$package_root/appinfo/signature.json"

mkdir --parents "$package_root/scripts"
cp --archive "$project_root/scripts/extract_embedded_xml.py" "$package_root/scripts/"

find "$package_root" -type d -exec chmod 0755 {} +
find "$package_root" -type f -exec chmod 0644 {} +

if [[ "$sign_package" == 1 ]]; then
    php "$nextcloud_occ" integrity:sign-app \
        --privateKey="$signing_key" --certificate="$signing_certificate" --path="$package_root" >&2
    test -s "$package_root/appinfo/signature.json"
    chmod 0644 "$package_root/appinfo/signature.json"
    php "$nextcloud_occ" integrity:check-app "$app_id" --path="$package_root" >&2
fi

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
    --format=ustar \
    "$app_id"

# Remove sidecars from an earlier signed build when producing an unsigned build.
rm --force "$archive.sig" "$archive.sig.b64" "$archive.crt"
if [[ "$sign_package" == 1 ]]; then
    openssl dgst -sha512 -sign "$signing_key" -out "$archive.sig" "$archive"
    openssl base64 -A -in "$archive.sig" -out "$archive.sig.b64"
    cp -- "$signing_certificate" "$archive.crt"
    openssl x509 -in "$archive.crt" -pubkey -noout > "$staging_directory/public.pem"
    openssl dgst -sha512 -verify "$staging_directory/public.pem" -signature "$archive.sig" "$archive" >&2
fi

(
    cd "$output_directory"
    checksum_entries=("$(basename "$archive")")
    if [[ "$sign_package" == 1 ]]; then
        checksum_entries+=("$(basename "$archive").sig" "$(basename "$archive").sig.b64" "$(basename "$archive").crt")
    fi
    sha256sum "${checksum_entries[@]}" > "$(basename "$archive").sha256"
)

echo "$archive"
