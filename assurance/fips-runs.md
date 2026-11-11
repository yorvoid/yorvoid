# FIPS runs

Before each release, YorvoID's tests run on an operating system booted in FIPS mode ([what this shows](../docs/concepts/cryptography.md#what-yorvoids-tests-show)). `tools/fips-host/run.sh` does the run on a throwaway AlmaLinux 9 host, and each section here is one run: the release, the commit, the date, and the report the script wrote, unedited.

A run shows the kernel in FIPS mode, OpenSSL using only its FIPS provider, MD5 refused, and the tests passing. It doesn't show that the provider is validated. That's on the provider's CMVP certificate, for the version you deploy.
