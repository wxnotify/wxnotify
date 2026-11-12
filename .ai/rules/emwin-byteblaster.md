---
paths:
  - 'docker/emwin-byteblaster/**'
---

# Emwin

## EMWIN container invariants
The emwin image builds npemwin from source in a build stage: Bitbucket tarball of the pinned commit NPEMWIN_COMMIT (5e4059fc, 3.0.1p1, the source of the old amd64 3.0.1p1-1 .deb), verified against NPEMWIN_SHA256, then upstream's build/debian/postinst run in the final stage (noaaport uid 1000, /var/npemwin/* dirs). Bumping the commit means a new checksum and a 7-minute live run comparing against the previous image. Upstream's makefiles ignore subdirectory errors, so the Dockerfile greps make output for "***"; the tarball omits every "examples" dir (export-ignore), so tclmetar's examples is dropped from its build. npemwind already unzips and uniquely names (content MD5) text products under /var/npemwin/files/txt/<cccc>/; pan-notify.sh delivers those by relative path and only unzips defensively into delivered/. pan-notify.sh must always exit 0 and refuse unsafe file names. refresh-servers.sh must never overwrite servers.conf with fewer than 3 servers (exit 0 unchanged, 10 changed, 1 failed). Cross-feed dedup keys on ingested_products.wmo_header_key (ExtractWmoHeaderIdentity); both feed controllers must go through IngestOncePerWmoHeader. Laravel keys ingested rows by file_path, so never post a path whose content can be overwritten by a later issuance.

## pan-notify.sh rewrites products to single LF line endings before posting
EMWIN text arrives with CR CR LF line endings, which read as a blank line after every line. deliver() in pan-notify.sh strips the carriage returns at each line end (sed -i 's/\r*$//') in place on the stored file (or the delivered/ copy of a zip member) BEFORE the POST, so Laravel ingests single-spaced text. npemwind has already written the file when the hook runs, so this is a rewrite, not a write. Because npemwind names text files by the MD5 of their original content, a rewritten file's name no longer matches its content hash: treat the name as a key only. Files without a CR are not touched; a normalization failure is logged and the product is still delivered as received (a double-spaced alert beats a missing one). Files with unsafe names are refused before they are touched. Control bytes (SOH/ETX) and lone mid-line CRs are deliberately left alone.
