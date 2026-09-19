FROM debian:12-slim

ARG TARGETARCH
ARG NPEMWIN_VERSION=3.0.1p1-1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl unzip rsyslog procps tini util-linux \
        tcl tcllib libdb5.3 libwrap0 gnuplot-nox \
    && rm -rf /var/lib/apt/lists/*

COPY docker/emwin/packages/ /tmp/packages/
RUN cd /tmp/packages \
    && grep "npemwin_${NPEMWIN_VERSION}_${TARGETARCH}.deb" SHA256SUMS | sha256sum -c - \
    && apt-get update \
    && apt-get install -y --no-install-recommends "./npemwin_${NPEMWIN_VERSION}_${TARGETARCH}.deb" \
    && rm -rf /var/lib/apt/lists/* /tmp/packages

RUN id noaaport >/dev/null 2>&1 || (groupadd -r noaaport && useradd -r -g noaaport -d /var/npemwin -s /usr/sbin/nologin noaaport)
RUN mkdir -p /var/npemwin/files/text/delivered /usr/local/etc/npemwin/site \
    && chown -R noaaport:noaaport /var/npemwin \
    && chmod -R a+rX /var/npemwin

COPY docker/emwin/site/ /usr/local/etc/npemwin/site/
COPY docker/emwin/servers.conf.fallback /usr/local/etc/npemwin/servers.conf
