---
paths:
  - 'app/Actions/Pipeline/**'
  - 'app/Actions/Weather/MatchWeatherAlertRules.php'
  - 'app/Observers/IngestedProductObserver.php'
---

# Pipeline

## Product replay
A replay copy (IngestedProduct.replay_of_id set) is created by ReplayIngestedProduct through IngestProductText with is_test=true and wmo_header_key=null: intake dedup (IngestOncePerWmoHeader) is the only reader of ingested_products.wmo_header_key and does not exclude test rows, so a copy carrying the key would make it drop a later real product. IngestedProductObserver sends any replay copy straight to MatchWeatherAlertRules and never to SyncActiveWwa, so replays never write active_wwas; routing to test accounts only relies on MatchWeatherAlertRules' is_test -> is_test_account filter, which must not be loosened. Replaying is refused in production inside the action itself, not just hidden in the UI.

## A Forecast Discussion rule means the AFD product specifically, not everything from that office
AlertCategory::ForecastDiscussion has no product picker in the wizard (a subscriber only chooses a WFO), which used to be implemented as "no product filter at all" -- every product ingested from that office's CCCC matched, including TAFs, synopses, hydrologic obs and work summaries, not just the Area Forecast Discussion. The category is only ever included in the matched-category set when the ingested product's resolved product (IngestedProduct::product_id -> Product::code) is exactly 'AFD'; matchesGeography()'s WFO-only check is correct precisely because the category filter already guarantees the product. If Forecast Discussion is ever extended to cover more than one product code, widen the check accordingly rather than dropping it back to WFO-only.
