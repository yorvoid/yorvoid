#!/bin/bash
# Runs as root on the host, once FIPS mode is on: installs PHP 8.4 from Remi against the system's
# OpenSSL, Composer, and an SQLite for the tests.
set -euo pipefail
test "$(cat /proc/sys/crypto/fips_enabled)" = 1 || { echo "The kernel isn't in FIPS mode."; exit 1; }
dnf install -q -y epel-release https://rpms.remirepo.net/enterprise/remi-release-9.rpm >/dev/null
dnf module reset -q -y php >/dev/null
dnf module enable -q -y php:remi-8.4 >/dev/null
dnf install -q -y php-cli php-mbstring php-xml php-intl php-pdo php-pgsql php-sodium php-process git unzip gcc make >/dev/null
# Composer's installer, without its signature check: acceptable on a throwaway host, not elsewhere.
curl -fsSL https://getcomposer.org/installer | php -- --quiet --install-dir=/usr/local/bin --filename=composer
# RHEL 9's SQLite (3.34) has no RETURNING, which YorvoID needs (3.35 or later): the tests use one of
# their own, built with the column metadata RHEL's pdo_sqlite links against. It isn't cryptography, so
# it changes nothing FIPS checks.
# The release and the SHA3-256 sqlite.org publishes for it (download.html).
sqlite=sqlite-autoconf-3540100
sqlite_sha3=b6ced585c750916b1052667239a12c25a7ff34e7f06e1e6e01ecb0427657d9c4
if [ ! -f /opt/sqlite/.column-metadata ]; then
	cd /tmp
	curl -fsSLo sqlite.tar.gz "https://www.sqlite.org/2026/$sqlite.tar.gz"
	test "$(openssl dgst -sha3-256 sqlite.tar.gz | cut -d' ' -f2)" = "$sqlite_sha3" || { echo "SQLite's tarball doesn't match its published SHA3-256."; exit 1; }
	tar xzf sqlite.tar.gz
	cd "$sqlite"
	CFLAGS="-O2 -DSQLITE_ENABLE_COLUMN_METADATA=1" ./configure -q --prefix=/opt/sqlite >/dev/null
	make -s -j"$(nproc)" >/dev/null 2>&1
	make -s install >/dev/null
	touch /opt/sqlite/.column-metadata
fi
