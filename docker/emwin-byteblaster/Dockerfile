# npemwin (https://bitbucket.org/noaaport/npemwin, ISC licence, Copyright (c) 2004-2012 Jose F. Nieves)
# is built from a pinned upstream commit. 5e4059fc is the 3.0.1p1 source the previously vendored amd64
# (production) 3.0.1p1-1 .deb was built from: same installed files. The next commit only changes the
# web UI's About menu.
FROM debian:12-slim AS build

ARG NPEMWIN_COMMIT=5e4059fc6375088642d7e8ba8026ba339b30a3fc
ARG NPEMWIN_SHA256=9008c3e326e71b772711b1bb344108b20328f765001f3c1b9b517a743d565417

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl gcc make m4 libc6-dev binutils \
        tcl-dev libdb5.3-dev libwrap0-dev zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src
RUN curl -fsSL -o /tmp/npemwin.tar.gz "https://bitbucket.org/noaaport/npemwin/get/${NPEMWIN_COMMIT}.tar.gz" \
    && echo "${NPEMWIN_SHA256}  /tmp/npemwin.tar.gz" | sha256sum -c - \
    && tar -xzf /tmp/npemwin.tar.gz --strip-components=1 \
    && rm /tmp/npemwin.tar.gz \
    && chmod -R go-w .

# Two adjustments, each failing the build if it no longer applies:
# - this commit's configure.inc only knows Debian 10/11 (Debian 12 falls back to Tcl 8.5 and db 5.1),
#   so apply upstream's own Debian 12 fix from commit b87d0a7;
# - upstream's .gitattributes export-ignores every "examples" directory, so the tarball lacks
#   tclmetar/tclmetar/examples (an example script that is built but never installed) and its
#   configure.sh would recurse forever; drop that directory from the tclmetar build.
RUN sed -i 's/\[ \$debian = "10" -o \$debian = "11" \]/[ $debian = "11" -o $debian = "12" ]/' configure.inc \
    && grep -q '\$debian = "12"' configure.inc \
    && sed -i 's#^config_dirs="src/lib examples doc"$#config_dirs="src/lib doc"#' tclmetar/tclmetar/configure.sh \
    && sed -i 's#^DIRS = src/lib examples doc$#DIRS = src/lib doc#' tclmetar/tclmetar/Makefile.in \
    && grep -q '^config_dirs="src/lib doc"$' tclmetar/tclmetar/configure.sh \
    && grep -q '^DIRS = src/lib doc$' tclmetar/tclmetar/Makefile.in \
    && ./configure.sh \
    && make 2>&1 | tee /tmp/make.log \
    && ! grep -q '\*\*\*' /tmp/make.log

# The same steps as upstream's build/debian/rules (dh_installdirs, make install, docs, strip, compress).
# debian:12-slim's /usr/local/man is a symlink to share/man, so the man page goes where dpkg put it.
# npemwinversion (shown on the web UI's configuration page) asks dpkg for the package version;
# with no package installed it would fail, so it prints the source VERSION instead.
# Upstream's makefiles loop over subdirectories without stopping on errors, hence the log checks.
RUN while read -r d; do mkdir -p "/staging/$d"; done < build/debian/dirs \
    && make PKGBUILDDIR=/staging PKGCONFDIR=/dist install 2>&1 | tee /tmp/install.log \
    && ! grep -q '\*\*\*' /tmp/install.log \
    && install -d /staging/usr/share/doc/npemwin \
    && install -m 0644 LICENSE build/debian/copyright /staging/usr/share/doc/npemwin/ \
    && gzip -9nc CHANGES > /staging/usr/share/doc/npemwin/changelog.gz \
    && gzip -9nc build/debian/changelog > /staging/usr/share/doc/npemwin/changelog.Debian.gz \
    && strip --strip-unneeded --remove-section=.comment --remove-section=.note \
        /staging/usr/local/sbin/npemwind /staging/usr/local/bin/npemwininsert \
        /staging/usr/local/bin/npemwinudprecv /staging/usr/local/bin/npemwinudpsend \
    && . ./VERSION \
    && printf '#!/bin/sh\n# Built from source, so there is no package entry for "dpkg -s npemwin" to read.\necho %s\n' "$version" \
        > /staging/usr/local/bin/npemwinversion \
    && mkdir -p /staging/usr/local/share \
    && mv /staging/usr/local/man /staging/usr/local/share/man \
    && install -m 0755 build/debian/postinst /staging/postinst

FROM debian:12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates curl unzip rsyslog procps tini util-linux \
        tcl tcllib libdb5.3 libwrap0 gnuplot-nox \
    && rm -rf /var/lib/apt/lists/*

# Upstream's own Debian post-install script, as dpkg ran it for the .deb: creates the noaaport user,
# the /var/npemwin/* and /var/log/npemwin directories it owns, the default configuration and the web tree.
# uid/gid 1000 is what the .deb produced, and existing product volumes are owned by it.
COPY --from=build /staging/ /
RUN /postinst > /dev/null \
    && rm /postinst \
    && [ "$(id -u noaaport)" = 1000 ] && [ "$(id -g noaaport)" = 1000 ]

RUN id noaaport >/dev/null 2>&1 || (groupadd -r noaaport && useradd -r -g noaaport -d /var/npemwin -s /usr/sbin/nologin noaaport)
RUN mkdir -p /var/npemwin/files/delivered /usr/local/etc/npemwin/site \
    && chown noaaport:noaaport /var/npemwin/files/delivered

COPY docker/emwin-byteblaster/site/ /usr/local/etc/npemwin/site/
COPY docker/emwin-byteblaster/servers.conf.fallback /usr/local/etc/npemwin/servers.conf

COPY docker/emwin-byteblaster/rsyslog.conf /etc/rsyslog-emwin.conf
COPY docker/emwin-byteblaster/entrypoint.sh docker/emwin-byteblaster/pan-notify.sh docker/emwin-byteblaster/refresh-servers.sh docker/emwin-byteblaster/healthcheck.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/*.sh && ln -s /usr/local/bin/pan-notify.sh /pan-notify.sh

EXPOSE 8016
HEALTHCHECK --interval=60s --timeout=5s --start-period=60s --retries=3 CMD ["/usr/local/bin/healthcheck.sh"]
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
