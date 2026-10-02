---
paths:
  - 'app/Jobs/DeliverWeatherAlert.php, app/Jobs/DeliverScheduledAlert.php'
---

# Jobs

## Str::limit(..., preserveWords: true) destroys formatted text — never use it for an email body
Laravel's Str::limit() with preserveWords: true runs `preg_replace('/[\n\r]+/', ' ', strip_tags($value))` before truncating (it's built for single-line SEO-style excerpts), collapsing every line break to a space. It only fires when the value already exceeds the limit, which is why this went unnoticed: short alert bodies never triggered it, but a long multi-line product (e.g. an AFD, easily 3000-6000 chars) came out as one flattened paragraph with no line breaks at all, truncated with a trailing "...". Both DeliverWeatherAlert and DeliverScheduledAlert now check the destination channel's transport (via ChannelTypeRegistry::find($channel->channel_type)?->transport() === ChannelTransport::Mail) and skip Str::limit entirely for email — it has no length constraint that would justify it, and any truncation there should be a deliberate design decision, not this default. If a new truncation path is added anywhere a body might reach an email channel, apply the same guard rather than calling Str::limit(..., preserveWords: true) unconditionally.

## DeliverScheduledAlert's report is optional queue state, and radar bytes never go on the queue
`DeliverScheduledAlert::$report` (the Full Daily Summary's structured payload) is a trailing promoted `readonly` constructor property. A job serialized before it existed comes back with it *uninitialized*, not null, because `SerializesModels`' native `unserialize()` bypasses the constructor — reading it directly throws. `handle()` reads it once via `$this->report ?? null` (isset semantics) and never dereferences the property again; keep that pattern for any property added to a queued job (see also `DeliverWeatherAlert::$locationId`). The payload must stay plain data (no models, `Carbon`, closures). The radar image is fetched inside `handle()` (`GetRadarSnapshot`) and handed to the in-process dispatcher through `$meta['daily_summary_radar']`, never stored in the report: binary bytes would bloat the queue payload and can't live in a text cache column un-encoded. Only a channel type whose `supportsRichContent()` is true (Email) gets the rich mail; every other channel gets the Brief text.
