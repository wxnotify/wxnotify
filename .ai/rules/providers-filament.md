---
paths:
  - app/Providers/Filament/DashboardPanelProvider.php
---

# Providers Filament

## Dashboard navigation group order is explicit
The group order is set by `->navigationGroups([...])` in DashboardPanelProvider: Live Weather, Alerting, Tools, Support (Dashboard is ungrouped and first). A new `$navigationGroup` label must be added there or it sorts last.

## The Chatwoot support widget never loads while staff impersonate
DashboardPanelProvider::shouldShowSupportWidget() hides the widget for guests, in selfhost mode, when the Chatwoot base URL or website token is blank, and while ImpersonateManager::isImpersonating(). During impersonation auth()->user() is the subscriber, so the widget would identify the admin as that subscriber: a message would reach Slack under the subscriber's name and their earlier chat would be readable. Keep the guard; SupportWidgetTest pins it with a real impersonation (mocking ImpersonateManager is not enough, the page's banner calls other methods on it). The widget also sends the subscriber's tier as a Chatwoot custom attribute.
