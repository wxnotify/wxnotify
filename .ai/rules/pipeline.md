---
paths:
  - 'app/Actions/Pipeline/**'
  - 'app/Actions/Weather/MatchWeatherAlertRules.php'
  - 'app/Observers/IngestedProductObserver.php'
---

# Pipeline

## Product replay
A replay copy (IngestedProduct.replay_of_id set) is created by ReplayIngestedProduct through IngestProductText with is_test=true and wmo_header_key=null: intake dedup (IngestOncePerWmoHeader) is the only reader of ingested_products.wmo_header_key and does not exclude test rows, so a copy carrying the key would make it drop a later real product. IngestedProductObserver sends any replay copy straight to MatchWeatherAlertRules and never to SyncActiveWwa, so replays never write active_wwas; routing to test accounts only relies on MatchWeatherAlertRules' is_test -> is_test_account filter, which must not be loosened. Replaying is refused in production inside the action itself, not just hidden in the UI.

## Area Forecast Discussion is an ordinary Weather Alerts product, not its own category
There is no forecast_discussion alert category any more: AFD is a catalog product (family `forecast_discussion`, category Other Forecasts, Outlooks, Discussions, and Reports) that a Weather Alerts rule attaches like any other, and MatchWeatherAlertRules matches it through the same product-attachment path as every other product. A rule attaching only AFD never matches another product from the same office, because the rule's own product list is the filter -- never reintroduce a WFO-only check that skips it. AFD is `has_ugc = false`, so only Nationwide and WFO matching modes can ever fire for it (Location/Polygon/County-Zone need product UGC codes). `AlertRuleForm::weatherAlertsFields()` must keep `forecast_discussion` in its family allow-list or AFD silently disappears from the picker.
