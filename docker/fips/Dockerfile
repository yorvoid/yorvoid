# The FIPS-configured image (CRY-01): the development image, with OpenSSL's FIPS provider alone active.
# The provider is OpenSSL 3.1.2's, a validated provider: FIPS 140-3, CMVP certificate #4985, active until
# 2030-03-10 (https://csrc.nist.gov/projects/cryptographic-module-validation-program/certificate/4985).
# It has no EdDSA, so YorvoID's Ed25519 signing keys don't work under it. It's built and installed as that policy says: from the
# release tarball, verified by its published SHA-256, configured with enable-fips, and installed with
# its self-test (openssl fipsinstall). The system's newer OpenSSL loads it, as OpenSSL supports.
#
# This is a FIPS-configured OpenSSL, not a FIPS-enabled operating system: see concepts/cryptography.md.
ARG BASE=yorvoid-php-dev
ARG OPENSSL_FIPS_VERSION=3.1.2
ARG OPENSSL_FIPS_SHA256=a0ce69b8b97ea6a35b96875235aa453b966ba3cba8af2de23657d8b6767d6539

FROM ${BASE} AS provider
ARG OPENSSL_FIPS_VERSION
ARG OPENSSL_FIPS_SHA256
USER root
RUN apt-get update \
	&& apt-get install -y --no-install-recommends build-essential perl curl ca-certificates \
	&& rm -rf /var/lib/apt/lists/*
WORKDIR /build
RUN curl -fsSLo openssl.tar.gz "https://github.com/openssl/openssl/releases/download/openssl-${OPENSSL_FIPS_VERSION}/openssl-${OPENSSL_FIPS_VERSION}.tar.gz" \
	&& echo "${OPENSSL_FIPS_SHA256}  openssl.tar.gz" | sha256sum -c - \
	&& tar xzf openssl.tar.gz --strip-components=1 \
	&& ./Configure enable-fips \
	&& make -j"$(nproc)" \
	&& cp providers/fips.so /build/fips.so

FROM ${BASE}
USER root
COPY --from=provider /build/fips.so /opt/openssl-fips/fips.so
COPY openssl-fips.cnf /opt/openssl-fips/openssl.cnf
# Where OpenSSL finds the provider it's configured with.
ENV OPENSSL_MODULES=/opt/openssl-fips
# The module's self-test, and its configuration with the module's checksum, as the security policy says.
RUN openssl fipsinstall -module /opt/openssl-fips/fips.so -out /opt/openssl-fips/fipsmodule.cnf \
	&& OPENSSL_CONF=/opt/openssl-fips/openssl.cnf openssl list -providers | grep -q 'OpenSSL FIPS Provider' \
	&& ! OPENSSL_CONF=/opt/openssl-fips/openssl.cnf openssl dgst -md5 /dev/null >/dev/null 2>&1
ENV OPENSSL_CONF=/opt/openssl-fips/openssl.cnf \
	YORVOID_TEST_FIPS=1
