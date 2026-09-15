# Narrative Section Parser (Hazard/Source/Impact) — Design

## Context

The master plan's "Custom alert templates" feature lets a subscriber build their own alert message by picking which sections of a product to include: hazard, source, impact, affected locations, expiration, and motion vector for a warning. This was deliberately deferred twice already this session — once when Alert Templates v1 scoped down to just a subject line and message length, and again when CAP ingestion was built, since CAP's `<description>` still bundles hazard/source/impact as unsplit prose, identical to the raw text bulletin.

What's changed since then: `active_wwas` (built alongside CAP ingestion) now provides expiration (`expires`), motion vector (`motion_*`), and affected locations in structured form (`ugc_codes`) for free. CAP rows also already get call-to-action for free via `<instruction>`. So the actual remaining gap is narrower than the master plan's original framing suggested: **hazard, source, and impact** — three bullet fields that only ever exist as unstructured prose, in either the raw text bulletin or CAP's `<description>` — plus two more fields worth capturing alongside them since they use the same scanning mechanism: a human-readable **locations-impacted** line (distinct from `active_wwas.ugc_codes`' machine-readable zone codes — e.g. "Springfield, Right City" vs "NEC055"), and **call-to-action** for text-only rows, which have no equivalent to CAP's `<instruction>`.

**This spec covers the parser only** — a new action that extracts these five fields at ingest time, alongside `ExtractProductMetadata`/`ExtractCapMetadata`. It does not cover the template-builder UI or wiring these fields into `DeliverWeatherAlert`'s body rendering — both are separate, later pieces, matching the "parser first, consumer later" sequencing already used for CAP ingestion in this codebase.

## Scope

Scoped to VTEC-class products only (matches Alert Templates v1 and CAP ingestion). Non-VTEC products (AFDs, SPC/WPC Discussion/Outlook products) have no HAZARD/SOURCE/IMPACT bullet convention at all and are out of scope, same as everywhere else this session.

## Schema

Five new nullable columns on `ingested_products`:

| Column | Notes |
|---|---|
| `hazard` | text, nullable |
| `hazard_source` | text, nullable — the bulletin's own `SOURCE...` bullet (e.g. "Radar indicated"). Named `hazard_source`, not bare `source`, specifically to avoid any confusion with `active_wwas.source` (the unrelated text/cap provenance marker on a different table) — same word, different concept, different table; a distinct name closes the ambiguity outright rather than relying on a comment to prevent it. |
| `impact` | text, nullable |
| `locations_impacted` | text, nullable — human-readable, distinct from `ugc_codes` |
| `call_to_action` | text, nullable |

Populated only for future ingests — no retroactive backfill of existing `ingested_products` rows, matching how every other new-column addition has behaved this session (VTEC columns, CAP columns).

## Action: `ParseNarrativeSections`

```php
namespace App\Actions\Pipeline;

class ParseNarrativeSections
{
    /**
     * @return array{hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
    public function __invoke(string $narrativeText): array
    {
        // ...
    }
}
```

A single shared action, generic over its text input — it doesn't know or care whether the text came from a raw bulletin or CAP's `<description>`, since both use the same bullet-labeled prose format. Never guesses: any section it can't confidently locate comes back `null`, matching this codebase's established convention (`ExtractProductMetadata`'s own docblocks document several "known gap, not attempted" decisions rather than guessing).

**Wiring:**
- `ExtractProductMetadata` (text rows): calls `ParseNarrativeSections($text)`, uses all 5 returned fields as-is.
- `ExtractCapMetadata` (CAP rows): calls `ParseNarrativeSections($description)` for `hazard`/`hazard_source`/`impact`/`locations_impacted`, then **overrides** `call_to_action` with the CAP row's own `instruction` value directly — discarding whatever (if anything) `ParseNarrativeSections` found in `description` for that field, since CAP's `instruction` is already reliably structured and shouldn't be second-guessed by re-parsing prose.

## Parsing mechanics

**`hazard`/`hazard_source`/`impact`** — the reliable case, confirmed consistent across every real example seen this session (FFW, SV.W, both text and CAP forms): a fixed label (`HAZARD...`, `SOURCE...`, `IMPACT...`) followed by free text, running until the next recognized label, a blank line followed by another all-caps label, or the `&&` delimiter — whichever comes first. Regex-based, matching the existing `HAZARD...`/`SOURCE...`/`IMPACT...` `...`-suffix convention already referenced in this codebase's docblocks (`ExtractCapMetadata`'s own class comment already names these three labels).

**`locations_impacted`** — best-effort, *not* a solved problem. Confirmed this session that real bulletins phrase this differently across product types (a bare `*` bullet — "Some locations that will experience flash flooding include..." — in one real captured FFW example vs. a more directly-labeled "Locations impacted include..." form seen in another). The parser tries a small, explicitly-documented set of known real label variants; anything phrased differently legitimately returns `null`. This is a genuine, acknowledged coverage gap in the source data's own inconsistency, not a bug to chase down — document it plainly rather than pretend the field is reliably populated.

**`call_to_action`** (text-only path only — CAP rows always use `instruction` instead) — bounded by a `PRECAUTIONARY/PREPAREDNESS ACTIONS...`-style label (or a small set of known real variants, same honesty standard as `locations_impacted`) through the `&&` delimiter or end of text.

## Testing

Real captured fixtures, matching this session's established convention (not synthesized test data):
- A substantive warning with full `HAZARD...`/`SOURCE...`/`IMPACT...` bullets and a locations-impacted line — proves the reliable-path extraction.
- A cancellation with no bullets at all — proves graceful `null`-on-absence, not a crash or a wrong guess.
- A real example whose locations-impacted phrasing doesn't match any known label variant — proves the coverage gap is a documented `null`, not silently papered over.

## Out of scope

- The section-picker template-builder UI (subscriber-facing, a separate future piece).
- Wiring these fields into `DeliverWeatherAlert`'s body rendering.
- Retroactive backfill of existing `ingested_products` rows.
- Any narrative parsing for non-VTEC-class products.
