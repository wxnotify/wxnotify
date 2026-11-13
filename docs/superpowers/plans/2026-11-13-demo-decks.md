# Demo Decks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build two slide decks about the next-gen WxNotify system from one set of verified facts and real, redacted screenshots: Deck A for engineers (10 slides, "follow one alert") and Deck C for a broader audience (8 slides, plain-language tour).

**Architecture:** Capture real screens from the dev system with the built-in browser, redact personal details with a small Pillow script, verify every claim against the repository into a facts file, draw two diagrams, then build each deck as a private slide artifact (started from the Slides artifact type) and gate both with a deck checker script. Working files live in the session scratchpad, never in the repository.

**Tech Stack:** Built-in browser pane (`mcp__Claude_Browser__*`), Python 3 + Pillow 11 (installed), the `Artifact` tool with the Slides type (quickstart already done; type URL `https://claude.ai/artifact/8jTsAFQMFDb2oA8MsPJ2eL`), inline SVG for diagrams.

**Spec:** `docs/superpowers/specs/2026-11-13-demo-decks-design.md` (read it first; it holds the slide lists and the reasoning).

## Global Constraints

- Working directory for every generated file: `$DECKS` = `<scratchpad>/demo-decks/` (the session scratchpad given in the environment), with subfolders `raw/`, `screens/`, `diagrams/`, `deckA/`, `deckC/`. Nothing generated is committed to the repository, because the screenshots show a real system's data. Only the plan and spec are committed.
- The dev system's address, the login, session cookies and any internal hostname (`*.infra.*`, `192.168.*`, `dev.wxnotify.com`) never appear in a deck, a speaker note, a filename or a commit.
- Decks are private artifacts. Do not share, pin or open them for anyone; the owner decides.
- Slide limits: Deck A at most 10 slides, Deck C at most 8 (spec). Dark theme matching the product. Short speaker notes on every slide.
- Every number or technical claim comes from `$DECKS/facts.md` (Task 4), which cites the repository file or test for it. If it is not in `facts.md` it is not on a slide.
- Rounded-down claims stay rounded down: "over 3,600 tests", "22 channel types".
- Capturing is READ-ONLY on the dev system. Navigate and screenshot only. Never click Start, Stop, Restart, Install, Save, Reset to Factory Defaults, Impersonate, Run, Send, Delete or any button that changes data; never submit a form. The admin panel has all of these.
- Capture at desktop width: the browser pane `window.innerWidth` must be at least 1280 (it is 1374 at 2× pixel density now). A narrow pane renders the mobile layout; stop and ask if it is narrower.
- Deck C uses none of these words: PostGIS, SSRF, queue, idempotent, webhook, container, Docker, Reverb, WebSocket, API, de-dup, payload, retry. (Plain equivalents only.)
- Read `.ai/rules/index.md` before any repository edit; this plan edits none beyond committing itself.

## Review Focus

1. **A screenshot leaks personal data** (an email, a person's name such as in alert titles, a street address or coordinates, a token, the dev hostname in a URL bar). Pinned in Task 3 (redaction script plus a manual look at every final image) and Task 8 (a scan of deck sources and image metadata).
2. **An admin action is triggered during capture** (a feed started or stopped, settings saved, reference data installed, an impersonation started). Pinned in Task 2 (read-only procedure, and a check of the Audit Log and Delivery Log for new entries made during capture).
3. **A stale or unverified number or claim on a slide.** Pinned in Task 4 (`facts.md` with evidence) and Task 8 (every number on every slide appears in `facts.md`).
4. **A deck over its slide limit, or Deck C containing jargon.** Pinned in Task 6/7 by `check_deck.py` and re-run in Task 8.
5. **A narrow-layout, blurry or cropped-wrong screenshot** (mobile layout, text unreadable at slide size, a crop that cuts a heading). Pinned in Tasks 1 and 2 (width check before each capture) and Task 8 (view every slide rendered).

---

### Task 1: Capture the subscriber screens

**Files:**
- Create: `$DECKS/raw/sub-*.png` and `$DECKS/raw/manifest.json`

**Interfaces:**
- Produces: `manifest.json`: a list of `{"id": str, "page": str (path only, no host), "file": str, "sensitive": [{"selector": str, "why": str}]}` consumed by Task 3. Ids: `sub-dashboard`, `sub-map`, `sub-products`, `sub-rules`, `sub-locations`, `sub-channels`, `sub-templates`.

- [ ] **Step 1: Check the pane.** In the tab, run `JSON.stringify({w: innerWidth})`. Expected: `w` of at least 1280. If less, stop and ask the owner to widen the pane.
- [ ] **Step 2: Capture each page** at the paths `/dashboard`, `/dashboard/live-weather/map`, `/dashboard/live-weather/products`, `/dashboard/alert-rules`, `/dashboard/locations`, `/dashboard/notification-channels`, `/dashboard/alert-templates` (the signed-in subscriber session is already open in the pane). Let charts and map tiles finish loading (wait for network quiet) before each screenshot. Save each screenshot file to `$DECKS/raw/<id>.png` (copy from the tool-result path the screenshot returns; convert with `sips -s format png` or Pillow if it is jpg).
- [ ] **Step 3: Record sensitive regions.** For each page, use `javascript_exec` to READ (not modify) the bounding boxes of elements whose text matches an email, a person-like name in an alert title, an address or coordinates, or a token. Store them as selector + `why` in the manifest, and the pixel rectangles (CSS px times the screenshot scale) in `$DECKS/raw/<id>.rects.json`.
- [ ] **Step 4: Verify.** Open each saved image with the Read tool. Expected: desktop layout (sidebar visible), readable text, no loading spinners, no error banners. Re-capture any that fail.
- [ ] **Step 5: Note** the count of files and what each shows in `$DECKS/capture-log.md` (no host names).

### Task 2: Capture the admin screens (read-only)

**Files:**
- Create: `$DECKS/raw/adm-*.png`, extend `manifest.json` and `*.rects.json`

**Interfaces:**
- Produces: manifest entries with ids `adm-dashboard`, `adm-pipeline`, `adm-ingested`, `adm-deliverylog`, `adm-health`, `adm-storage`, `adm-setup-checklist` for paths `/admin`, `/admin/pipeline-control`, `/admin/ingested-products`, `/admin/delivery-logs`, `/admin/health-check-results`, `/admin/product-storage`, `/admin/onboarding`.

- [ ] **Step 1: Snapshot the audit trail.** Open `/admin/audit-log` and note the newest entry's timestamp and the Delivery Log's newest row; record both in `capture-log.md` (this is how Step 5 proves nothing changed).
- [ ] **Step 2: Capture each admin page** following Task 1 Steps 1 to 3 (width check, wait for load, save, read sensitive rectangles). Navigate by URL only; do not click buttons on these pages. For Ingested Products and Delivery Log, capture the list view only (do not open a record).
- [ ] **Step 3: Verify** each image with the Read tool (same criteria as Task 1).
- [ ] **Step 4: Optional page.** If `/admin/health-check-results` is empty or errors, use `/admin/setup` for the health slide instead and say so in `capture-log.md`.
- [ ] **Step 5: Prove capture was read-only.** Reload `/admin/audit-log` and the Delivery Log. Expected: no entry newer than the Step 1 snapshot that is attributable to this session's account (a Delivery Log row from real traffic is fine). Record the result in `capture-log.md`. Any change made by us is a defect to report immediately.

### Task 3: Redact the screenshots

**Files:**
- Create: `$DECKS/redact.py`, `$DECKS/test_redact.py`, `$DECKS/screens/*.png`

**Interfaces:**
- Consumes: `raw/<id>.png`, `raw/<id>.rects.json` (list of `{"x","y","w","h"}` in image pixels).
- Produces: `redact.blur_regions(image: PIL.Image.Image, rects: list[dict], radius: int = 14) -> PIL.Image.Image` and a CLI `python3 redact.py <raw_dir> <screens_dir>` writing `screens/<id>.png` with the same name; EXIF/metadata stripped; output is PNG.

- [ ] **Step 1: Write the failing test** `test_redact.py` (plain `python3 -m unittest`): create a 200x100 image with a sharp black-and-white stripe pattern; `blur_regions` over the rect `{x:50,y:20,w:100,h:40}`; assert the pixel variance inside the rect is below 20% of the original variance, assert pixels outside the rect are identical to the original, assert the returned image size is unchanged, and assert a rect extending beyond the image bounds is clipped without error.
- [ ] **Step 2: Run to verify it fails.** `python3 -m unittest test_redact` in `$DECKS`. Expected: FAIL (module not found).
- [ ] **Step 3: Implement** `blur_regions` with `PIL.ImageFilter.GaussianBlur` on the cropped region pasted back, clipping rects to the image, and the CLI loop over `raw/*.png`, padding each rect by 6 px, saving PNG without metadata.
- [ ] **Step 4: Run to verify it passes.** Expected: all tests OK.
- [ ] **Step 5: Run the CLI** on all raw images, then open EVERY file in `screens/` with the Read tool. Expected: each listed sensitive region is unreadable and nothing else readable is personal (email, person's name, address, coordinates, hostname). If anything is still readable, add a rect to that image's `rects.json` and re-run. Record "reviewed" per image in `capture-log.md`.

### Task 4: Verify the facts

**Files:**
- Create: `$DECKS/facts.md`

**Interfaces:**
- Produces: `facts.md`, one row per claim: `| id | claim as it will appear on a slide | evidence (file:line or test name) | how verified |`. Later tasks may use only these claims. Required ids and the evidence to read:
  - `F-tests`: over 3,600 Pest tests in 341 files. Re-count (`grep -rhE "^(test|it)\(" tests | wc -l`, `find tests -name '*Test.php' | wc -l`).
  - `F-channels`: 22 channel types behind one registry. `app/Support/ChannelTypes/ChannelTypeRegistry.php` and its tests.
  - `F-dedup`: the same product arriving from two feeds is ingested once, keyed on the WMO header. `app/Http/Controllers/IngestEmwinProductController.php`, `app/Http/Controllers/IngestNwwsProductController.php`, `app/Models/PipelineDelivery.php`, and the feature tests that cover it.
  - `F-priority`: workers read `deliveries` before `default` before `broadcasts`. `compose.yaml` (the `queue` service command and its comment).
  - `F-noretry`: workers run with `--tries=1` because a retry would re-send a billed message; timeout (60 s) is below the queue's retry window (90 s). `compose.yaml` comments, `config/queue.php`.
  - `F-longqueue`: long jobs (shapefile scan, storage scan) run on their own worker and connection with their own retry window. `compose.yaml` (`queue-long`), `.ai/rules/app-jobs.md`.
  - `F-postgis`: spatial matching runs in PostGIS. `app/Actions/Weather/GetActiveAlertsForLocation.php` and `app/Actions/Map/GetLiveWarnings.php` (cite the ST_ functions actually used).
  - `F-ssrf`: user-supplied URLs are checked by guards. `app/Support/Delivery/WebhookUrlGuard.php`, `ChatServerGuard.php`, `app/Support/TraccarUrlGuard.php` and their tests; state exactly what they refuse.
  - `F-secrets`: channel destinations and secrets are encrypted columns. `app/Models/NotificationChannel.php:38` and the channel tests.
  - `F-live`: the live Products page streams over Reverb and falls back to polling. `resources/js/echo.js`, `tests/Feature/LiveWeatherProductsPageTest.php`.
  - `F-heartbeat`: pipeline heartbeats and a queue heartbeat feed monitoring. `app/Support/Pipeline/PipelineHeartbeat.php`, `app/Support/Setup/QueueHeartbeat.php`, `app/Jobs/RecordQueueHeartbeat` (cite what exists).
  - `F-guards`: guard tests for known traps. `tests/Feature/NoEnvOutsideConfigTest.php`, `tests/Feature/S3DiskDriverTest.php`, `deploy/verify.sh`.
  - `F-e2e`: before release the published compose files were run end to end (fresh install, upgrade, a failing migration, recovery). `.superpowers`-independent evidence: the commits `35b0546` and `0496c18` and `deploy/README.md`.
  - `F-image`: the image that is smoke-tested is the image that is pushed (same digest). `.github/workflows/release.yml` (the digest verification step).
  - `F-oneimage`: one PHP image serves every role by command; 1 or 4 servers; five images. `deploy/single-server/compose.yaml`, `deploy/multi-server/compose.yaml`, `docker/8.4/Dockerfile`.
  - `F-licence`: GPL-3.0-or-later. `LICENSE`, `composer.json`.
  - `F-scale`: 153 migrations, 585 application classes (re-count).
- [ ] **Step 1: Re-count** the numeric facts and write them with the command used.
- [ ] **Step 2: Read the cited code** for each non-numeric fact and write the claim in the narrowest true wording. Where the code does not support the claim as drafted in the spec, change the wording or drop the claim and say so in a `Dropped` section.
- [ ] **Step 3: Verify.** Every row has a file path that exists (`ls` each cited path; check each cited line range contains what is claimed). Expected: no missing paths.

### Task 5: Draw the diagrams

**Files:**
- Create: `$DECKS/diagrams/system-map.svg`, `$DECKS/diagrams/queues.svg`

**Interfaces:**
- Produces: two self-contained SVG files with `viewBox="0 0 1280 540"`, dark-theme colours, text at least 22 px, no external references, used by Deck A slides 3 and 6 (and a simplified three-step graphic for Deck C slide 3 drawn in Task 7).

- [ ] **Step 1: Load the `artifact-diagramming` skill** and follow its SVG mechanics.
- [ ] **Step 2: Draw `system-map.svg`:** feeds (NWWS-OI, EMWIN, GLM) → ingest → de-dup → match (PostGIS) → queue → deliver (22 channels) → subscriber, with the live stream and admin panel as side branches. Labels use only claims in `facts.md`.
- [ ] **Step 3: Draw `queues.svg`:** three priority lanes (`deliveries`, `default`, `broadcasts`) read in that order by one worker, a separate long-job lane, and a note that failed jobs are not retried.
- [ ] **Step 4: Verify.** Render each SVG in the browser pane (open it as a data URL or in a scratch HTML page) and view it: no overlapping text, all labels readable at 50% size, no clipped shapes.

### Task 6: Build Deck A (engineers, 10 slides)

**Files:**
- Create: `$DECKS/deckA/` (the slide content files the Slides type needs), `$DECKS/check_deck.py`, `$DECKS/test_check_deck.py`, and the private artifact "WxNotify: engineering overview".

**Interfaces:**
- Produces: `check_deck.py <deck_dir> --max-slides N [--no-jargon]` exiting 1 with one line per violation when: the slide count exceeds N; any file contains a forbidden pattern (`dev.wxnotify`, `infra.`, `192.168.`, an email address, the words `password`, `Bearer`, `cookie`); with `--no-jargon`, any word from the Global Constraints jargon list; any slide without speaker notes; any number of 3 or more digits (or with a comma) that does not appear in `facts.md`.
- Slide headlines (final): 1 "WxNotify: weather in, trusted alerts out"; 2 "Why alerts are hard"; 3 "One alert, end to end"; 4 "Ingest: three feeds, one copy of each product"; 5 "Match: where is it, and who cares?"; 6 "Deliver first, and never twice"; 7 "22 ways out, one contract"; 8 "Live, and watching itself"; 9 "Tested like it matters"; 10 "Ships anywhere".

- [ ] **Step 1: Write the failing checker test** `test_check_deck.py`: a temp deck with 11 slides fails `--max-slides 10`; a slide containing an email address fails; a slide with `192.168.1.5` fails; a number not in a temp `facts.md` fails and one that is in it passes; a slide missing notes fails; `--no-jargon` fails on the word "queue" and passes on plain text.
- [ ] **Step 2: Run to verify it fails**, then **implement `check_deck.py`** and run `python3 -m unittest test_check_deck` to green.
- [ ] **Step 3: Start the artifact** from the Slides type (`Artifact` publish with `type_url` above, `title` "WxNotify: engineering overview", no files, `auto_open: "after_first_write"`); read the instructions the create result returns and follow them for how slide content, images and speaker notes are supplied. Use no design system (none is available).
- [ ] **Step 4: Write the ten slides** per the spec table and the headlines above: at most four short lines per slide; slide 4 uses `adm-pipeline` and `adm-ingested`; 5 uses `sub-rules`, `sub-locations`, `sub-map`; 6 uses `adm-deliverylog` and `queues.svg`; 7 uses `sub-channels`; 8 uses `sub-products` and `adm-health`; 1 uses `sub-dashboard`; 3 uses `system-map.svg`; 9 shows the test counts from `F-tests`/`F-guards`/`F-e2e`/`F-image` as four large figures or statements; 10 covers `F-oneimage`, `F-licence` and how to try it. Speaker notes are two or three sentences each and cite which fact id the slide rests on.
- [ ] **Step 5: Check.** Run `python3 check_deck.py $DECKS/deckA --max-slides 10`. Expected: no output, exit 0.
- [ ] **Step 6: Publish and view.** Publish the files to the artifact URL and view the live deck in the browser pane, paging every slide. Expected: images sharp, no text overflow, theme dark, slide count 10.

### Task 7: Build Deck C (broader audience, 8 slides)

**Files:**
- Create: `$DECKS/deckC/` and the private artifact "WxNotify: product overview".

**Interfaces:**
- Consumes: `screens/*.png`, `facts.md`, `check_deck.py`. Headlines (final): 1 "WxNotify"; 2 "Weather alerts that find you"; 3 "Three simple steps"; 4 "Your dashboard, at a glance"; 5 "Your rules, your places"; 6 "Alerts wherever you are"; 7 "Built to be dependable"; 8 "Get started".

- [ ] **Step 1: Start the artifact** from the Slides type with title "WxNotify: product overview" as in Task 6 Step 3.
- [ ] **Step 2: Write the eight slides** per the spec: slide 3 is a simple three-step graphic (choose what you care about, we watch the weather sources, you get alerted); 4 uses `sub-dashboard`; 5 uses `sub-rules` and `sub-locations`; 6 uses `sub-channels` with `sub-products` and `sub-map` beside it; 7 uses `adm-health` and states in plain words only what `facts.md` supports (we monitor ourselves; it keeps working if one part fails; every alert is logged); 8 explains hosted use and running it yourself in two lines each. No jargon; short sentences; speaker notes in plain language.
- [ ] **Step 3: Check.** `python3 check_deck.py $DECKS/deckC --max-slides 8 --no-jargon`. Expected: no output, exit 0.
- [ ] **Step 4: Publish and view** every slide as in Task 6 Step 6.

### Task 8: Final review and hand-over

**Files:** none new.

- [ ] **Step 1: PII and secrets sweep.** Re-run both `check_deck.py` commands. Search every image under `$DECKS/screens` and the deck folders' file names and metadata for the strings in the check (`exiftool` is not required: Pillow `Image.info` and `getexif()` must be empty for every PNG used). Confirm no `raw/` image was uploaded to either artifact (list the artifact files with the Artifact `list` action, scope `files`).
- [ ] **Step 2: Claim sweep.** Extract every number and technical claim from both decks and tick each against `facts.md`. Expected: none unticked.
- [ ] **Step 3: View every slide** of both decks once more, full size, and list any visual defect (overflow, blur, cut crop). Fix and re-publish.
- [ ] **Step 4: Confirm the dev system was left unchanged** (Task 2 Step 5 result) and that the browser pane viewport is reset with `resize_window` preset `desktop`.
- [ ] **Step 5: Hand over.** Give the owner both artifact links, the slide-by-slide list, the count of screenshots used, what was dropped from the spec and why (the `Dropped` section of `facts.md`), and how to export (PowerPoint/PDF from the artifact). Commit nothing further.
