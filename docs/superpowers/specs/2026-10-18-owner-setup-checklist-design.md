# Owner setup checklist (Admin panel) — Design

Source: owner conversation, 2026-10-18.

## Intent and why

Standing up a WxNotify server (a self-hoster's first install, or the owner's own new cloud server) takes a series of one-off tasks that today live in the owner's head: set the right `.env` values, get the weather feed credentials working, install the reference data, import the shapefile boundaries, make sure the scheduler and queue run, and so on. The planned Docker Hub images (ServerSideUp, with `AUTORUN_ENABLED=true`) already run migrations, the caches and the storage link on start, and assets are built into the image, so the remaining work is **knowing what is needed, doing the few things that cannot be automated, and seeing that each one worked**.

The solution is a **setup checklist in the Admin panel**, built on the already-installed `wallacemartinss/filament-onboarding` plugin, shown to owners in both the self-hosted and the cloud product (as the owner's own "new server" list), plus the **small new admin actions** the checklist needs. The owner triggers the actions on purpose, so they learn what makes WxNotify work. Nothing is seeded or scanned automatically at container start.

## Goals

- An owner opening the Admin panel on a fresh install sees a checklist that explains each thing WxNotify needs, in plain language, and ticks each item itself as soon as it is true.
- Each item links to the page where the owner does it, or tells the owner what to change in `.env` / the Compose file.
- Two owner-pressed actions exist where none does today: **Install reference data** and **Check for shapefile updates**.
- The same checklist helps the owner (cloud) set up a new server, with a few cloud-only steps.

## Non-goals

- No installer script, wizard, or auto-seeding at container start.
- No panel UI for values that belong in the environment (`APP_KEY`, database, `WXNOTIFY_MODE`, NWWS login, `FIREBASE_SERVICE_ACCOUNT` path): the checklist explains them and detects the result.
- Not in this design, each to be designed on its own: the user-panel onboarding journey for new subscribers (the plugin stays registered on the Dashboard panel for it); removing or fixing the unused Firebase field on the App Settings page; the Docker images, example Compose file and documentation.

## Anti-goals

- No hidden automation: the checklist and the actions must make the work clearer, not do it invisibly.
- The reference-data action must never delete anything or overwrite what an admin has edited.
- Subscribers and non-owners never see the checklist.

## Decisions (made with the owner)

- **Plugin on the Admin panel**, with the launcher and progress page, and `manageFlows()` so the owner can edit the text. Flows are per panel (the flow's panel is `admin`); the existing Dashboard-panel registration stays for the later user journey.
- **Visibility:** owners only (`users.is_owner`), in both modes. Cloud-only steps carry a "cloud mode only" visibility condition. Self-hosters see no cloud steps.
- **Self-completing steps** use the plugin's "Condition" completion (named classes in `app/Onboarding/Conditions`, found automatically). A step an owner can only confirm themselves is Manual.
- **NWWS credentials stay in `.env`.** They are read by the NWWS container, so the app cannot check them directly. The step completes when a first NWWS product has been received (proof the credentials and container work).
- **Firebase:** the step says to mount the service-account JSON and set `FIREBASE_SERVICE_ACCOUNT` to its path. It does not mention the App Settings Secrets field (unused, and, as read, saving a value there would replace the path with JSON text and break push; fixed separately).
- **The user triggers the job.** "Install reference data" is a button on a new admin section; it is not run by the image.

## The checklist (draft; order matters)

1. **Basics in the Compose file / `.env`** — `APP_KEY`, `APP_URL`, `WXNOTIFY_MODE`, trusted proxies if behind a load balancer or Cloudflare. Manual tick.
2. **Weather feed login** — `NWWS_USERNAME` / `NWWS_PASSWORD` in `.env` for the NWWS container. Completes when a first NWWS product exists.
3. **Install reference data** — the new action. Completes when the product catalog, the "Did you know" tips and the tracked shapefile layers are present.
4. **Import boundaries** — run the first shapefile scan, then review and activate each layer (Shapefile Layers). Completes when every tracked layer has an active version.
5. **Scheduler and queue worker running** — completes from their heartbeats (the app already runs a schedule health check and a heartbeat queue).
6. **Mail** — completes when the mailer is not `log`.
7. **Mobile push and web push (optional)** — `FIREBASE_SERVICE_ACCOUNT` file path with the file mounted; VAPID keys (`php artisan webpush:vapid`).
8. **Cloud only:** Stripe/Paddle keys, the support widget (Chatwoot), anything else the owner lists for a new cloud server.

The owner's first account and ownership (`app:grant-owner`) happens before the panel is reachable, so it is documentation, not a checklist item.

## The new admin pieces

- **Setup page** (Admin panel, a new section): a card per reference set (product catalog, channel types, "Did you know" tips, tracked shapefile layers) with how many exist, and one **Install reference data** action that seeds all four. Add-only and idempotent: it creates what is missing and leaves existing rows and admin edits alone. It does not create the test user the stock `DatabaseSeeder` makes. Restricted to owners.
- **Check for shapefile updates:** an action on the Shapefile Layers list that starts the same scan as `shapefile:scan`. Today only the daily schedule or the command can start one; this lets the owner do the first import from the panel. (Imports land in staging; the existing review and Activate flow stays.)

## Constraints

- Progress is per user; a completed step is persisted the first time its condition passes (plugin behaviour). `php artisan onboarding:reset` re-arms it.
- The plugin's journey is database rows. The checklist ships with the app (a data migration creates the flow, steps and conditions idempotently by key) and stays editable in the panel afterwards. Plugin migrations must be published and run.
- Conditions must be cheap and must not throw; a condition that errors counts as "not done".
- Owner = `users.is_owner`; mode = `config('wxnotify.mode')`.

## Left to the builder

Exact condition queries; the wording, icons and order of each step; the Setup page layout; how to read the scheduler and queue heartbeats; how the seeding action reports what it created; where a data migration ends and an editable row begins when the app later ships new steps; the tests; and whether the first shapefile import still needs a manual Activate (confirm in the code and say so in the step text).

## My calls (for the owner to check)

- A dedicated **Setup page** holds the install action, rather than adding the button to an existing page.
- The checklist is **shipped by migration** and editable afterwards.
- **"Check for shapefile updates"** is added as a button on the Shapefile Layers list.
- Cloud-only steps exist but their final list is the owner's to supply.
