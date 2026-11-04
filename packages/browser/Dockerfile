# The browser client's toolchain: Node, and ps, which Stryker uses to stop test runs.
FROM node:26.12.1-slim@sha256:94816674c62774da0d099a947bf4613b9e1972a2733abd0d0cd80e795f8411d0

RUN apt-get update \
	&& apt-get install -y --no-install-recommends procps \
	&& rm -rf /var/lib/apt/lists/*
