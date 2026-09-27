---
paths:
  - 'app/Actions/Pipeline/**'
---

# Pipeline

## Product replay
A replay copy (IngestedProduct.replay_of_id set) is created by ReplayIngestedProduct through IngestProductText with is_test=true and wmo_header_key=null: intake dedup (IngestOncePerWmoHeader) is the only reader of ingested_products.wmo_header_key and does not exclude test rows, so a copy carrying the key would make it drop a later real product. IngestedProductObserver sends any replay copy straight to MatchWeatherAlertRules and never to SyncActiveWwa, so replays never write active_wwas; routing to test accounts only relies on MatchWeatherAlertRules' is_test -> is_test_account filter, which must not be loosened. Replaying is refused in production inside the action itself, not just hidden in the UI.
