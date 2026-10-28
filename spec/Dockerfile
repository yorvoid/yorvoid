# The tools that check YorvoID's formal models, pinned and verified, so that a check gives the same
# answer on every machine and in CI, and nothing is downloaded while models run.
# Debian 13: Apalache needs Java 21, and Quint's evaluator glibc 2.39 or later.
FROM node:22-trixie-slim

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates curl openjdk-21-jre-headless \
	&& rm -rf /var/lib/apt/lists/*

ARG ALLOY_VERSION=6.2.0
ARG ALLOY_SHA256=5a5494a4bac6e243e471590bb44a91e25a35794a5af1ae1f332be30b9c54a9e7
ARG QUINT_VERSION=0.33.0
# The versions Quint 0.33.0 asks for; preinstalled where it looks for them (QUINT_HOME).
ARG APALACHE_VERSION=0.62.1
ARG APALACHE_SHA256=38202a8c4ebeb3ab73e0fe5a7e4bb948442d46ad5de8a7fb45c9e5cac2beb2a6
ARG EVALUATOR_VERSION=v0.7.0
ARG EVALUATOR_SHA256=869adb8762a518483eb39f67fde622253a58527494e15afe77d610a0b6dab7d2

ENV QUINT_HOME=/opt/quint

RUN set -eux; \
	mkdir -p /opt/alloy "$QUINT_HOME/apalache-dist-$APALACHE_VERSION" "$QUINT_HOME/rust-evaluator-$EVALUATOR_VERSION"; \
	curl -fsSLo /tmp/alloy.tar.gz "https://github.com/AlloyTools/org.alloytools.alloy/releases/download/v$ALLOY_VERSION/alloy-$ALLOY_VERSION-linux-amd64.tar.gz"; \
	echo "$ALLOY_SHA256  /tmp/alloy.tar.gz" | sha256sum -c -; \
	tar xzf /tmp/alloy.tar.gz -C /tmp "alloy-$ALLOY_VERSION/lib/app/org.alloytools.alloy.dist.jar"; \
	mv "/tmp/alloy-$ALLOY_VERSION/lib/app/org.alloytools.alloy.dist.jar" /opt/alloy/alloy.jar; \
	curl -fsSLo /tmp/apalache.tgz "https://github.com/apalache-mc/apalache/releases/download/v$APALACHE_VERSION/apalache.tgz"; \
	echo "$APALACHE_SHA256  /tmp/apalache.tgz" | sha256sum -c -; \
	tar xzf /tmp/apalache.tgz -C "$QUINT_HOME/apalache-dist-$APALACHE_VERSION"; \
	curl -fsSLo /tmp/evaluator.tgz "https://github.com/quint-co/quint/releases/download/evaluator/$EVALUATOR_VERSION/quint_evaluator-x86_64-unknown-linux-gnu.tar.gz"; \
	echo "$EVALUATOR_SHA256  /tmp/evaluator.tgz" | sha256sum -c -; \
	tar xzf /tmp/evaluator.tgz -C "$QUINT_HOME/rust-evaluator-$EVALUATOR_VERSION"; \
	npm install -g --no-fund --no-audit "@informalsystems/quint@$QUINT_VERSION"; \
	rm -rf /tmp/*.tar.gz /tmp/*.tgz /tmp/alloy-*; \
	chmod -R a+rX /opt

WORKDIR /spec
