# Profile refresh service: incident reproduction and fix

Laravel 13 · Horizon · Redis 7 · Postgres 17 · Laravel Scout (database engine) · PHP 8.4 in Docker.

A small service that refreshes creator profiles from an upstream provider through Horizon workers.
It reproduces the production incident from the statement (format change + 429/500 being stored as
"successful" refreshes), fixes it, and measures the same workload before and after.

No real OnlyFans endpoint is called anywhere. Tests use local fixtures (`tests/Fixtures/upstream`) and
HTTP fakes; the workload uses a local fake upstream served by the `web` container.

### Why fixtures instead of onlyfans.com/madison420ivy

- **The incident has to be reproducible on demand.** The failure cases (429 without Retry-After, 500 with an
  empty body, the format switch, a late response with an older `revision`) cannot be triggered against a
  live service when you need them, and a regression test that depends on someone else's uptime is not a
  regression test. The statement asks for "local response fixtures" for exactly this reason.
- **The incident's payload is a model, not OnlyFans' real format.** `likes`, `revision` and
  `profile.likes` come from the statement; the fix is written against that contract.
- **onlyfans.com has no public API.** Its internal endpoints need a logged-in session and signed request
  headers, and scraping it breaks its terms. That is the problem providers like FansAPI exist to solve, so
  I did not try to work around it.
- **The real source plugs into the existing port.** `ProfileUpstream` is the only thing the application
  knows about the provider. Going live means adding one adapter (e.g. one that calls FansAPI's "Get
  Profile Details" endpoint for a username), mapping its response into the payload `ProfileSnapshot`
  validates, putting the API key in the environment, and recording one real response as a new fixture.
  Retry, cooldown, revision and validation logic do not change. Things I would check against the real
  provider first: its actual 429/Retry-After behaviour and rate-limit scope, whether it exposes a version
  I can use as `revision` (if not, a fetched-at timestamp is the fallback, with weaker ordering
  guarantees), and that 401/403 pause the account instead of being retried.

---

## 1. Run it

Requirements: Docker (with Compose). Nothing else on the host.

```bash
cp .env.example .env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan test          # 89 tests, uses the separate fansapi_testing DB
docker compose up -d horizon web                       # 4 Horizon workers + UI/fake upstream on :8000
docker compose exec horizon php artisan refresh:workload --handler=legacy   # "before"
docker compose exec horizon php artisan refresh:workload --handler=fixed    # "after"
docker compose exec horizon php artisan refresh:crash-demo                  # ~95 s, kills a worker
```

UI: http://localhost:8000 (profiles + Scout search). Horizon dashboard: http://localhost:8000/horizon.
Quality gates: `docker compose run --rm app composer check` (Pint, PHPStan level 9, tests).
The `scheduler` service (`php artisan schedule:work`, runs `refresh:schedule` every minute) is optional;
the workload schedules by itself, so leave it stopped while measuring.

---

## 2. The failure

The legacy handler ([`LegacyBrokenHandler`](app/Infrastructure/Legacy/LegacyBrokenHandler.php), kept on purpose)
reads `likes` from the top level, defaults a missing value to 0, never looks at the HTTP status and always
reports success. When the upstream moved `likes` into a `profile` object and started returning 429/500,
every job still "completed", and every profile it touched was overwritten.

Recorded behaviour ([`LegacyBrokenHandlerTest`](tests/Feature/Refresh/LegacyBrokenHandlerTest.php),
starting from likes 120,000 at revision 10):

| Upstream response                                  | Stored by legacy             | Reported |
|----------------------------------------------------|------------------------------|----------|
| old format `{likes:120000, revision:10}`           | likes 120,000, revision 10 ✔ | success  |
| new format `{profile:{likes:121000}, revision:11}` | **likes 0**, revision 11     | success  |
| HTTP 429, no Retry-After                           | **likes 0, revision NULL**   | success  |
| HTTP 500, empty body                               | **likes 0, revision NULL**   | success  |

Reproduction before the fix (separate commit `fe5fbd9`): the regression suite
[`RefreshRegressionTest`](tests/Feature/Refresh/RefreshRegressionTest.php) runs against whatever handler
is bound; against the legacy one it fails **13 tests, 6 pass**
([`docs/evidence/01-reproduction-red.txt`](docs/evidence/01-reproduction-red.txt)). The 6 passes are the 4
characterisation tests, the old format, and explicit `likes: 0`, which passes only by accident (the
legacy default happens to be 0). After the fix the same file, **unchanged**, passes
([`docs/evidence/02-fix-green.txt`](docs/evidence/02-fix-green.txt)).

Why "the oldest waiting jobs keep getting older" and "one busy account delays the others": there is no
per-account handling of 429, so a rate-limited account's jobs keep hitting the upstream; retries (if
any) come back at the same moment; and a FIFO queue lets one account's backlog sit in front of everyone.
And because 429 and 500 were counted as success, the dashboards said everything was fine.

---

## 3. The fix

### Architecture (hexagonal, enforced by a test)

```
app/Domain/Profile        pure PHP: ProfileSnapshot, Likes, Revision, RefreshSchedule, FailureReason
app/Application           use cases (RefreshProfile, ScheduleDueRefreshes, TrackProfile), RetryPolicy,
                          ports: ProfileUpstream, ProfileRepository, RefreshQueue, AccountCooldown
app/Infrastructure        adapters: HTTP upstream, Postgres repository, Redis cooldown, Laravel queue,
                          the Horizon job (thin), console commands, fake upstream, legacy handler, UI
app/Providers/RefreshServiceProvider.php   the only place that wires ports to adapters
```

[`ArchitectureTest`](tests/Unit/ArchitectureTest.php) fails if Domain imports anything outside Domain, or if
Application imports Laravel (only Domain, Application, PSR interfaces and PHP core are allowed). The queue
job only builds a command and calls the use case.

### Data protection rules

- **Both formats.** [`ProfileSnapshot::fromPayload`](app/Domain/Profile/ProfileSnapshot.php): if `profile`
  exists it is authoritative, otherwise top-level `likes`. `revision` is required.
- **Validate before writing.** Missing `likes` is invalid; explicit `0` is valid; negative, fractional,
  boolean, null and non-numeric are rejected (digit strings like `"121000"` are accepted). The database
  also refuses negatives (`CHECK` constraints) as a last line of defence.
- **Failures never erase data and never count as success.** Only a validated snapshot that was actually
  stored is `Refreshed`. `last_attempt_at`, `last_successful_refresh_at` and the failure details
  (`last_failure_at`, `last_failure_reason`, `last_failure_detail`, `consecutive_failures`) are separate
  columns; `refresh_attempts` logs one row per upstream call.
- **Older revision arriving last.** One atomic statement:
  `UPDATE profiles SET ... WHERE id = ? AND (revision IS NULL OR revision < ?)`. Under concurrent writers
  Postgres re-evaluates the `WHERE` on the latest committed row, so an older response cannot win even if
  it commits later. 0 rows updated = `Superseded`: recorded as an attempt, not as a success.
- **Duplicates.** Unique index on `(account_id, handle)`; tracking is `INSERT ... ON CONFLICT DO NOTHING`.
  Jobs carry ids only and never create rows. A replayed job hits the revision guard (`Superseded`).
- **Schedule.** [`RefreshSchedule`](app/Domain/Profile/RefreshSchedule.php): above 100,000 likes → 24 h,
  otherwise 72 h; exactly 100,000 → 72 h (tested at 99,999 / 100,000 / 100,001).
- **No double scheduling.** The scheduler claims due profiles and marks them pending in one statement
  (`UPDATE ... WHERE id IN (SELECT ... FOR UPDATE SKIP LOCKED) RETURNING id`), so running it twice or on two
  hosts queues each profile once. A pending mark older than 15 min is reclaimed (its job was lost).

### Queue behaviour

| Failure | Classified as | What happens |
|---|---|---|
| 429 without Retry-After | `rate_limited` | random delay in [d, 2d], d = 2 s × 2^(attempt−1), capped 60 s; **the whole account cools down** for that time |
| 429 with Retry-After | `rate_limited` | the upstream's value (seconds or HTTP-date), capped |
| timeout / connection error | `timeout` / `connection_failed` | exponential backoff with jitter |
| 5xx | `server_error` | exponential backoff with jitter |
| 2xx with invalid body | `invalid_payload` | not retried now (retrying will not change it); next try in 15 min |
| 404 / 410 | `not_found` | not retried; next try in 24 h |
| other 4xx | `rejected` | not retried; next try in 24 h |

Retry limit: 5 upstream attempts per cycle ([`config/refresh.php`](config/refresh.php)), then the cycle
ends, last valid data stays, and the profile is retried later. Retries are **new jobs** queued by the
application ([`RetryPolicy`](app/Application/Refresh/RetryPolicy.php), seeded randomness so tests are
deterministic). The job's own `$tries = 3` only covers queue redelivery (worker crash, timeout,
unexpected exception), so the two counters never mix.

**Account isolation.** A 429 sets `refresh:cooldown:{account}` in Redis with a TTL. Any job of that account
that starts while the key exists is re-queued with the remaining time plus jitter **without calling the
upstream and without spending an attempt** (`refresh.deferred` in the logs). A rate-limited account
therefore costs workers milliseconds per job instead of a full request, and stops hammering the upstream;
other accounts keep their capacity. Expiry of the key is the "limit cleared" signal.

### Timeouts and "two workers on the same attempt"

```
HTTP 5 s (+2 s connect) < job $timeout 30 s < per-profile lock 40 s < Redis retry_after 90 s
```

- Redis does not know a job is still running: after `retry_after` seconds a reserved job is handed to
  another worker. If a job could run longer than `retry_after`, two workers would process the same
  attempt. So the job must be killed first: the job `$timeout` (enforced with `pcntl`) is well below
  `retry_after`, and the HTTP timeout is well below the job timeout so a slow upstream fails cleanly.
- Note on the usual phrasing "worker `--timeout` < job `$timeout` < `retry_after`": in Laravel the job's
  `$timeout` **overrides** the worker's `--timeout` (`Worker::timeoutForJob`); the worker value only applies
  to jobs without their own. What must hold is *effective timeout < retry_after*. Here both are 30 s.
- Belt and braces: `WithoutOverlapping('profile:{id}')->dontRelease()` (one worker per profile; a second
  delivery while the first runs is dropped) with expiry 40 s, so a crashed worker cannot lock a profile
  forever, and the revision-guarded write makes any overlap that still happens harmless.
- [`RefreshProfileJobTest`](tests/Feature/Queue/RefreshProfileJobTest.php) asserts the chain from config.

---

## 4. Evidence: same workload, before and after

`refresh:workload` (Horizon, 4 workers, real Redis/Postgres, fake upstream over HTTP). Same seed both runs:

- `acct-busy`: 30 profiles; upstream answers **only 429 (no Retry-After) for 8 s**, then allows 5 req/s.
- `acct-calm`: 10 profiles; **every 6th request is HTTP 500 with an empty body**.
- Every profile starts with valid data at revision 10 and is due; the upstream answers in the new format
  with 40 ms latency; likes are a pure function of (handle, revision), so every stored row is checked.

"Verified" = stored likes equal what the upstream served for the stored revision, and the revision is new.
"Oldest waiting job" is measured from the start of the refresh cycle and kept across retries (a retry that
gets a fresh `createdAt` would otherwise hide the growing delay).

**Before** (legacy handler, [`03-workload-before-legacy.txt`](docs/evidence/03-workload-before-legacy.txt)):

| account   | profiles | reported ok | verified ok | corrupted | upstream requests    | requests / verified ok | oldest waiting job | all verified |
|-----------|---------:|------------:|------------:|----------:|----------------------|------------------------|-------------------:|--------------|
| acct-busy | 30       | 30          | **0**       | **30**    | 30 (429: 30, 500: 0) | n/a (0 verified)       | 4 s                | never        |
| acct-calm | 10       | 10          | **0**       | **10**    | 10 (429: 0, 500: 1)  | n/a (0 verified)       | 4 s                | never        |

The queue drained in 6 s and every job "succeeded": exactly the incident. Busy rows ended as
`likes=0, revision=NULL`; calm rows as `likes=0, revision=11`.

**After** (fixed handler, [`04-workload-after-fixed.txt`](docs/evidence/04-workload-after-fixed.txt)):

| account   | profiles | reported ok | verified ok | corrupted | upstream requests    | requests / verified ok | oldest waiting job | all verified |
|-----------|---------:|------------:|------------:|----------:|----------------------|------------------------|-------------------:|--------------|
| acct-busy | 30       | 30          | **30**      | 0         | 40 (429: 10, 500: 0) | 1.33                   | 33 s               | 34 s         |
| acct-calm | 10       | 10          | **10**      | 0         | 11 (429: 0, 500: 1)  | 1.10                   | 3 s                | **4 s**      |

Reading the timeline: the calm account finished at t=4 s, **while** the busy account was still inside its
429 storm (0/30 until t=12 s); its one 500 was retried with backoff. The busy account made only 10
requests that got 429 (the cooldown deferred the rest instead of calling the upstream), then recovered
once the storm cleared and finished at t=34 s at the allowed 5 req/s. The oldest waiting job for the busy
account grew to 33 s and then dropped to 0: bounded and recovering, not growing forever.

**Worker dies after the DB write, before the ack.**

- Replay test ([`RefreshProfileJobTest`](tests/Feature/Queue/RefreshProfileJobTest.php)): runs the same job
  twice with the same upstream response. Result: one row, revision 11 stored once,
  `last_successful_refresh_at` unchanged by the replay, attempts logged as `refreshed` then `superseded`.
  *Proves*: the handler is idempotent under replay. *Does not prove*: that the queue actually redelivers,
  how long it takes, or what a half-finished process leaves behind (locks, open transactions).
- Real crash ([`05-crash-after-write-redelivery.txt`](docs/evidence/05-crash-after-write-redelivery.txt),
  `refresh:crash-demo`): the Horizon worker is `SIGKILL`ed right after the write. The job sat in Redis'
  reserved set for 90 s (`retry_after`), was redelivered to another worker (`delivery: 2`, same job uuid),
  and was recorded as `superseded`; `last_success` did not move; one profile row. *Still not proven*: a
  kill *during* the SQL statement (Postgres rolls back an uncommitted statement, but I did not inject
  that), Redis itself crashing or failing over (reserved jobs can be lost without AOF/replication; the
  scheduler's 15 min pending reclaim is the backstop), multi-host clock skew.

---

## 5. Observability

- Structured JSON logs in `storage/logs/refresh.log`, one event per line: `refresh.refreshed`,
  `refresh.superseded`, `refresh.retry_scheduled`, `refresh.deferred`, `refresh.failed`,
  `refresh.scheduled`, each with `account`, `profile_id`, `handle`, `job_id`, `attempt`, `delivery`,
  plus `reason`, `http_status`, `revision`, `delay_s`, `duration_ms` where relevant.
  [`06-structured-logs-sample.txt`](docs/evidence/06-structured-logs-sample.txt) follows one profile through
  429 → retry → 3 deferrals → 429 → refreshed on attempt 3. The upstream token is never logged (asserted
  in a test; the sample file also checks the log files contain it 0 times).
- `refresh_attempts`: one row per upstream call (outcome, status, revision, duration, attempt, delivery),
  which is what "attempts per successful refresh" is computed from.
- Profile state is queryable (and visible in the UI): last attempt vs last success vs last failure, pending.
- Horizon dashboard for throughput, wait time and failed jobs.

---

## 6. Next scaling limit: 50 M jobs/day

50 M/day is 579 jobs/s **on average**; it says nothing about peaks or capacity. Nothing in a 40-profile local
run proves this design holds there. What I would measure, and what I expect to break first:

**Measure first**
- Arrival rate per minute (peak vs average; scheduler claims are bursty by design) and per account.
- Job duration p50/p99, dominated by upstream latency → required concurrency by Little's law:
  579/s × 0.2 s ≈ 116 busy workers on average, several times that at peak or when latency degrades.
- Retry amplification: upstream requests per verified refresh, overall and per account (here 1.10–1.33;
  the legacy behaviour hid this completely). Deferrals per refresh (queue round-trips that do no work).
- Postgres: statements per refresh (today: 1 select + 1 conditional update + 1 insert, ~3 → ~1,700/s
  average), WAL volume, autovacuum on `profiles` (update-heavy), `refresh_attempts` growth (50 M+ rows/day).
- Redis: ops/s and memory (per job ≈ push, pop, reserved zset add/remove, lock set/del, cooldown pttl,
  flag get), and the size of the delayed set.
- Upstream: per-account quotas and the largest accounts. **The real ceiling is the sum of account quotas**,
  not worker count; adding workers past that only creates 429s.

**Likely bottleneck of this solution**
1. **Deferral churn for big rate-limited accounts**: every deferred job is a full queue round-trip, repeated
   every cooldown period. An account with 1 M queued profiles in a long cooldown means millions of
   no-op pops, which load Redis and workers in proportion to the backlog, not to useful work.
2. **`refresh_attempts` writes** in the hot path (an extra insert and index update per call; unbounded table).

**Evidence to collect**: deferred/refreshed ratio per account from the logs; Redis `INFO commandstats` and
delayed-set size; `pg_stat_statements` top statements by total time; table/index size growth and
autovacuum lag; Horizon wait time per queue.

**First change** (if the evidence points at 1): move rate limiting from execution to **admission**: a
per-account token bucket in Redis consulted by the scheduler, so it only claims what each account can
actually spend now, and a cooling-down account's profiles stay in Postgres (cheap) instead of bouncing in
Redis. If the evidence points at 2 instead: make `refresh_attempts` daily-partitioned with short retention
(or sampled), and keep counters as metrics.

---

## 7. Production plan

**First 15 minutes**
1. Stop the damage, not the service: confirm that jobs "complete" while data degrades
   (`SELECT count(*) FROM profiles WHERE likes = 0 AND updated_at > now() - interval '1 hour'`, and
   Horizon completed counts vs that). If the legacy handler is running, pause the refresh supervisor
   (`php artisan horizon:pause-supervisor`): stale data is better than erased data.
2. Look at upstream responses by status and by account (429 rate, 500 rate, response shape) to confirm
   the format change and find the busy account.
3. Size the damage: list profiles overwritten since the change started; they must be re-fetched (backup
   restore only if the upstream cannot serve current values).

**Rollout**
1. Run migrations. *Caveat*: this repo renames `refreshed_at` for clarity; in production I would do
   expand/contract (add the new columns, dual-write, drop later) so old workers keep running.
2. Deploy; `php artisan horizon:terminate` for a graceful worker restart (finishes current jobs).
3. Flip `refresh:handler` to `fixed` (it is already the default) and resume the supervisor.
4. Mark the damaged profiles due (`next_refresh_at = NULL`, `refresh_pending_since = NULL`); the revision
   guard guarantees the newest upstream value wins.
5. Better than this global flag: a per-account canary, which I did not build.

**When to roll back**
- `invalid_payload` spikes on accounts that look healthy (my validation rejecting data the upstream
  legitimately sends, e.g. likes as floats), or verified refresh rate drops below the pre-incident baseline,
  or DB errors from the new statements.
- Roll back means: pause refreshes and redeploy the previous *fixed* build. **Never back to the legacy
  handler**: it is known to erase data. The `legacy` flag value exists only to reproduce the incident.

**How to verify recovery**
- Verified refreshes per minute back to the expected rate (`refresh_attempts` outcome `refreshed`).
- Attempts per verified refresh near 1 and falling; 429 rate per account falling.
- Oldest pending age (`now() - min(refresh_pending_since)`) decreasing per account.
- Count of `likes = 0` rows refreshed during the incident going to 0; spot-check a few against the upstream.

---

## 8. Remaining limits and what is not done

- **Cooldown escalation is per job attempt, not per account**, and nothing caps an account *before* it gets
  429s (no proactive quota). Under a long storm, deferred jobs keep bouncing (see scaling section).
- **A slow-but-not-429 account can still hold workers** up to the HTTP timeout: no per-account concurrency
  cap (`Redis::funnel` per account would be the next step).
- The attempt log insert and the profile update are separate statements: a crash between them loses one
  `refresh_attempts` row (metrics undercount by one); profile data is unaffected.
- A job that exhausts its 3 queue deliveries (e.g. DB down) leaves the profile pending until the 15 min
  reclaim. A cycle longer than 15 min could be scheduled twice: wasteful, harmless (revision guard).
- An upstream bug that serves *different* data under the *same* revision would be ignored (by design).
- The handler flag is global; no per-account canary. The migration rename is not zero-downtime.
- Local proof only: 4 workers, 40 profiles, one host, Docker Desktop on Windows. Not proof of capacity.

## 9. Time spent

About 1 h 30 min of wall-clock time with Claude Code (git timestamps: scaffold at 10:14, last code commit
11:18, docs after that, all on 2026-10-14, UTC−4), plus Alexander's time reviewing and rehearsing the
code (to be added by him). How AI was used, and what was not verified: [`AI.md`](AI.md).

## 10. Where things are

| Path | What |
|---|---|
| `app/Domain/Profile` | domain rules (validation, schedule, failure types) |
| `app/Application/Refresh` | use cases and RetryPolicy |
| `app/Application/Port` | ports (interfaces) and their DTOs |
| `app/Infrastructure/*` | adapters, job, commands, fake upstream, legacy handler, UI |
| `tests/Unit` | domain, RetryPolicy, architecture rule (no DB) |
| `tests/Feature` | regression contract, legacy characterisation, use case, adapters, job replay, UI |
| `tests/Fixtures/upstream` | local upstream responses |
| `docs/evidence` | raw outputs quoted in this README |
