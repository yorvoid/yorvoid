# The browser client's toolchain: Node, and ps, which Stryker uses to stop test runs.
FROM node:24.22.1-slim

RUN apt-get update \
	&& apt-get install -y --no-install-recommends procps \
	&& rm -rf /var/lib/apt/lists/*
