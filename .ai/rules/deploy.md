---
paths:
  - 'deploy/**'
---

# Deploy

## Self-hosted mail defaults to the bundled Mailpit; keep its variable names apart
deploy/single-server ships MAIL_MAILER=mailpit and a mailpit service (page on 127.0.0.1 only, SMTP never published; the page has no login and shows reset links). config/mail.php has a separate `mailpit` mailer so MailConfiguredCondition (Onboarding "Set up outgoing email") can exclude it by name; real mail is MAIL_MAILER=smtp. The compose file's MAILPIT_PORT/MAILPIT_BIND_ADDRESS reach the app through env_file, so the app-side mailer reads MAILPIT_SMTP_HOST/MAILPIT_SMTP_PORT instead: reusing MAILPIT_PORT broke SMTP when the page port was changed. The four-server compose still defaults to smtp and has no Mailpit.
