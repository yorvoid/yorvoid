#!/bin/bash
# Runs on the FIPS-enabled host (run.sh), after setup.sh: runs the checks and the tests
# the FIPS mode depends on. Prints the report docs/concepts/cryptography.md records.
set -euo pipefail
cd ~/yorvoid

echo "== The operating system"
cat /etc/os-release | grep -E '^(NAME|VERSION)='
echo "kernel fips_enabled: $(cat /proc/sys/crypto/fips_enabled)"
fips-mode-setup --check
update-crypto-policies --show
openssl version
openssl list -providers
if openssl dgst -md5 /dev/null >/dev/null 2>&1; then echo "MD5: allowed (NOT FIPS)"; exit 1; else echo "MD5: refused"; fi

echo "== PHP"
php -v | head -1
php -r 'echo "PHP links OpenSSL: ", OPENSSL_VERSION_TEXT, PHP_EOL;'
php -r 'echo "MD5 from PHP: ", var_export(@openssl_digest("", "md5"), true), PHP_EOL;'

echo "== YorvoID's probe"
php -r 'require "vendor/autoload.php"; $p = YorvoID\Core\Crypto\CryptoProvider::probe(); var_export($p); echo PHP_EOL; echo "FIPS policy: "; $policy = YorvoID\Core\Crypto\CryptoPolicy::fips($p); echo "starts, EdDSA allowed: ", var_export($policy->allowsEdDsa(), true), ", COSE algorithms: ", json_encode($policy->coseAlgorithms()), PHP_EOL;'

echo "== The tests (composer test-fips)"
echo "SQLite for the tests: $(LD_LIBRARY_PATH=/opt/sqlite/lib php -r 'echo (new PDO("sqlite::memory:"))->query("select sqlite_version()")->fetchColumn();')"
# The tests with Ed25519 keys run when the provider has Ed25519: CI's FIPS provider doesn't, so only
# such a host tests the FIPS mode with EdDSA.
if php -r 'require "vendor/autoload.php"; exit(YorvoID\Core\Crypto\CryptoProvider::probe()->ed25519 ? 0 : 1);'; then
	echo "Ed25519 in the provider: the tests with Ed25519 keys run"
	exclude=()
else
	echo "No Ed25519 in the provider: the tests with Ed25519 keys are left out"
	exclude=(--exclude-group ed25519-keys)
fi
status=0
YORVOID_TEST_FIPS=1 LD_LIBRARY_PATH=/opt/sqlite/lib php -d memory_limit=2G -d zend.assertions=1 vendor/bin/phpunit "${exclude[@]}" --no-progress > ~/phpunit.txt 2>&1 || status=$?
cat ~/phpunit.txt
exit "$status"
