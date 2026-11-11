# SQL Server workflow concurrency QC

This opt-in CLI harness is separate from PHPUnit and never uses RefreshDatabase.
It runs the current workspace application code against **simpel-dev only**.
Credentials come from the local `.env`; the database name and DB URL are
overridden before application bootstrap, config cache is isolated, and `DB_NAME()`
is checked before writes. Production database switching is rejected.

Prepare the staging schema with the application's approved migrations first.
Do not run `migrate:fresh`, restore, or the SQLite PHPUnit suite against staging.
The migration preparation used for this investigation is recorded in the incident
report; normal application migrations must target `simpel-dev` under the same
identity guard, never the `.env` default production connection.

```sh
php tests/Concurrency/sqlserver-workflow.php --database=simpel-dev --allow-staging-writes
```

The CLI requires explicit write opt-in. Missing flags and `--database=sivera` are
covered by unit tests and refused before application/database bootstrap.

The harness creates uniquely labelled synthetic users, workflow configuration,
documents and local temporary files. It does not read or use stored document
contents from staging or production. PDF generation is a test double; normal
database notifications and application transitions run against staging SQL Server.
Fixture configuration deliberately bypasses model events when creating workflow
steps and role pivots to avoid adding unrelated immutable security events.

For each scenario two PHP processes establish independent SQL Server sessions.
A holds the same document update lock that the application uses; B attempts its
action while the lock is held. The coordinator must observe B blocked by A via
`sys.dm_exec_requests` before releasing A. DMV visibility for these sessions is
required. This tests a controlled A-before-B interleaving, not random load/stress.

Ten scenarios cover approval/revision races, quorum, duplicate submissions,
upload/submission races in both orders, and repeated signer returns. Assertions
check outcomes, source/snapshot hashes, active-ticket uniqueness, old decisions,
and verification rounds. Result `status=200` means a direct method call succeeded;
exception codes are normalized to Laravel HTTP statuses. It is not a wire HTTP or
browser test. Middleware/HTTP authorization has separate PHPUnit coverage.

Fixture cleanup is scoped by newly created IDs. The harness checks row counts
and SHA-256 fingerprints of 16 tables before/after cleanup. It refuses automatic
cleanup if unexpected signing/OTP evidence exists. JSON results and diagnostic
logs remain in the private temporary run directory printed by the CLI. Receipt
tokens stay in private manifests and must not be copied into shared reports.

This runs short transactions and session-local timeouts/deadlock priority on the
staging DB. It changes no global SQL Server settings, production web server,
production storage, or production application deployment.
