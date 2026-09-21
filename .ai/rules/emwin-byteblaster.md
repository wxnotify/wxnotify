---
paths:
  - 'docker/emwin-byteblaster/**'
---

# Emwin

## EMWIN container invariants
The emwin image picks its npemwin .deb from TARGETARCH out of docker/emwin-byteblaster/packages (verified against SHA256SUMS); bump amd64 and arm64 together. npemwind already unzips and uniquely names (content MD5) text products under /var/npemwin/files/txt/<cccc>/; pan-notify.sh delivers those by relative path and only unzips defensively into delivered/. pan-notify.sh must always exit 0 and refuse unsafe file names. refresh-servers.sh must never overwrite servers.conf with fewer than 3 servers (exit 0 unchanged, 10 changed, 1 failed). Cross-feed dedup keys on ingested_products.wmo_header_key (ExtractWmoHeaderIdentity); both feed controllers must go through IngestOncePerWmoHeader. Laravel keys ingested rows by file_path, so never post a path whose content can be overwritten by a later issuance.
