#!/usr/bin/env python3
"""
Polls NOAA's public, anonymously-readable GOES GLM (Geostationary Lightning
Mapper) S3 buckets for new Level 2 flash files, decodes each with h5py (a
NetCDF4/HDF5 file is just an HDF5 container — no netCDF C library needed for
this), and POSTs the decoded flashes to WxNotify's ingest webhook.

Written in Python rather than PHP for one reason only: there is no PHP
library for NetCDF4/HDF5 (confirmed — nothing on Packagist, no maintained
extension), and hand-rolling an HDF5 reader isn't a reasonable ask for a
solo/AI-assisted project. This mirrors the NWWS client's existing precedent
of a non-PHP pipeline component (that one's Go) POSTing into Laravel via a
bearer-token webhook — not a new architectural pattern for this app.

Each GLM file covers a ~20 second window and is published roughly 20
seconds after real-time; this polls every GLM_POLL_INTERVAL_SECONDS and
processes whatever's new since the last cycle, rather than trying to
process every file the instant it lands.
"""

import io
import json
import logging
import os
import sys
import time
from datetime import datetime, timedelta, timezone

import boto3
import h5py
import requests
from botocore import UNSIGNED
from botocore.client import Config

logging.basicConfig(
    level=os.environ.get("GLM_LOG_LEVEL", "INFO"),
    format="%(asctime)s %(levelname)s %(message)s",
    stream=sys.stdout,
)
logger = logging.getLogger("glm-decoder")

BUCKETS = [b.strip() for b in os.environ.get("GLM_BUCKETS", "noaa-goes19,noaa-goes18").split(",") if b.strip()]
WEBHOOK_URL = os.environ["GLM_WEBHOOK_URL"]
WEBHOOK_TOKEN = os.environ["PIPELINE_WEBHOOK_TOKEN"]
POLL_INTERVAL_SECONDS = int(os.environ.get("GLM_POLL_INTERVAL_SECONDS", "30"))
STATE_FILE = os.environ.get("GLM_STATE_FILE", "/state/processed_keys.json")

# Only a good-quality flash (per NOAA's own flag_meanings) is trustworthy
# enough to alert a subscriber on — see flash_quality_flag's documented
# flag_values/flag_meanings in a real GLM file.
GOOD_QUALITY_FLAG = 0

# How long a processed S3 key is remembered before being pruned from state —
# comfortably longer than any plausible poll-cycle delay or restart gap,
# short enough that the state file never grows unbounded.
PROCESSED_KEY_RETENTION = timedelta(hours=3)

s3 = boto3.client("s3", config=Config(signature_version=UNSIGNED))


def load_processed_keys() -> dict:
    try:
        with open(STATE_FILE) as f:
            return json.load(f)
    except (FileNotFoundError, json.JSONDecodeError):
        return {}


def save_processed_keys(keys: dict) -> None:
    os.makedirs(os.path.dirname(STATE_FILE), exist_ok=True)
    with open(STATE_FILE, "w") as f:
        json.dump(keys, f)


def prune_processed_keys(keys: dict) -> dict:
    cutoff = (datetime.now(timezone.utc) - PROCESSED_KEY_RETENTION).isoformat()
    return {key: seen_at for key, seen_at in keys.items() if seen_at > cutoff}


def hour_prefixes(now: datetime) -> list[str]:
    """Current and previous UTC hour, to catch files near an hour boundary."""
    prefixes = []
    for hours_ago in (0, 1):
        t = now - timedelta(hours=hours_ago)
        prefixes.append(f"GLM-L2-LCFA/{t:%Y}/{t:%j}/{t:%H}/")
    return prefixes


def list_new_keys(bucket: str, processed: dict) -> list[str]:
    new_keys = []
    for prefix in hour_prefixes(datetime.now(timezone.utc)):
        paginator = s3.get_paginator("list_objects_v2")
        for page in paginator.paginate(Bucket=bucket, Prefix=prefix):
            for obj in page.get("Contents", []):
                key = f"{bucket}/{obj['Key']}"
                if key not in processed:
                    new_keys.append((bucket, obj["Key"]))
    return new_keys


def decode_flashes(bucket: str, key: str) -> list[dict]:
    buffer = io.BytesIO()
    s3.download_fileobj(bucket, key, buffer)
    buffer.seek(0)

    flashes = []
    with h5py.File(buffer, "r") as f:
        if "flash_lat" not in f:
            return flashes

        lats = f["flash_lat"][:]
        lons = f["flash_lon"][:]
        ids = f["flash_id"][:]
        quality = f["flash_quality_flag"][:]

        for lat, lon, flash_id, quality_flag in zip(lats, lons, ids, quality):
            if int(quality_flag) != GOOD_QUALITY_FLAG:
                continue

            flashes.append({
                "id": str(int(flash_id)),
                "lat": float(lat),
                "lon": float(lon),
            })

    return flashes


def post_flashes(flashes: list[dict]) -> None:
    if not flashes:
        return

    response = requests.post(
        WEBHOOK_URL,
        json={"flashes": flashes},
        headers={"Authorization": f"Bearer {WEBHOOK_TOKEN}"},
        timeout=30,
    )
    response.raise_for_status()


def poll_once(processed: dict) -> dict:
    for bucket in BUCKETS:
        try:
            new = list_new_keys(bucket, processed)
        except Exception:
            logger.exception("Failed to list new objects in bucket %s", bucket)
            continue

        for bucket_name, key in new:
            full_key = f"{bucket_name}/{key}"
            try:
                flashes = decode_flashes(bucket_name, key)
                post_flashes(flashes)
                logger.info("Processed %s: %d good-quality flash(es)", full_key, len(flashes))
            except Exception:
                logger.exception("Failed to process %s", full_key)
                continue

            processed[full_key] = datetime.now(timezone.utc).isoformat()

    return prune_processed_keys(processed)


def main() -> None:
    logger.info("Starting GLM decoder — buckets=%s poll_interval=%ds", BUCKETS, POLL_INTERVAL_SECONDS)
    processed = load_processed_keys()

    while True:
        processed = poll_once(processed)
        save_processed_keys(processed)
        time.sleep(POLL_INTERVAL_SECONDS)


if __name__ == "__main__":
    main()
