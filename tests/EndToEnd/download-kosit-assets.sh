#!/usr/bin/env bash

set -euo pipefail

: "${KOSIT_ASSET_DIRECTORY:?KOSIT_ASSET_DIRECTORY is required}"
: "${KOSIT_VALIDATOR_VERSION:?KOSIT_VALIDATOR_VERSION is required}"
: "${KOSIT_CONFIGURATION_VERSION:?KOSIT_CONFIGURATION_VERSION is required}"

mkdir --parents "$KOSIT_ASSET_DIRECTORY"
cd "$KOSIT_ASSET_DIRECTORY"

validator="validator-${KOSIT_VALIDATOR_VERSION}-standalone.jar"
configuration="xrechnung-3.0.2-validator-configuration-${KOSIT_CONFIGURATION_VERSION}.zip"
invoice="01.01a-INVOICE_ubl.xml"

if [ ! -f "$validator" ]; then
    curl --fail --silent --show-error --location \
        "https://github.com/itplr-kosit/validator/releases/download/v${KOSIT_VALIDATOR_VERSION}/$validator" \
        --output "$validator"
fi

if [ ! -f "$configuration" ]; then
    curl --fail --silent --show-error --location \
        "https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/v${KOSIT_CONFIGURATION_VERSION}/$configuration" \
        --output "$configuration"
fi

if [ ! -f "$invoice" ]; then
    curl --fail --silent --show-error --location \
        "https://projekte.kosit.org/xrechnung/xrechnung-testsuite/-/raw/33cf3bdf13fb0d8443c51371ab892f25a9468d79/src/test/business-cases/standard/$invoice" \
        --output "$invoice"
fi

echo '244978514ad48f67c7573acfffc8f4fd73d81feda6f276710033f9913579857e  validator-1.6.2-standalone.jar' \
    | sha256sum --check --strict -
echo '6a5a5911a421b25fbc423f62f93f894df7b236f5d73ca4f84bb222a945082704  xrechnung-3.0.2-validator-configuration-2026-01-31.zip' \
    | sha256sum --check --strict -
echo '74fb09c609d5fba15a8c543060998d3b92858f56a81fb5b0ed244d6794e498d1  01.01a-INVOICE_ubl.xml' \
    | sha256sum --check --strict -
