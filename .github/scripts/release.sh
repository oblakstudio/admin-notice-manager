#/bin/bash

NEXT_VERSION=$1
CURRENT_VERSION=$(cat composer.json | grep version | head -1 | awk -F= "{ print $2 }" | sed 's/[version:,\",]//g' | tr -d '[[:space:]]')

sed -i "s/\"version\": \"$CURRENT_VERSION\"/\"version\": \"$NEXT_VERSION\"/g" composer.json
sed -i "s/version = '$CURRENT_VERSION'/version = '$NEXT_VERSION'/g" src/Admin_Notice_Manager.php

mkdir /tmp/admin-notice-manager
cp -ar src composer.json composer.lock README.md CHANGELOG.md /tmp/release 2>/dev/null
cd /tmp
zip -qr /tmp/release.zip admin-notice-manager
