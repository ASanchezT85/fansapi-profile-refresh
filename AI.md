# AI usage

## Tools

- **Claude Code** (Anthropic; Claude Opus 5.5 model) in the Claude desktop app, driving a terminal and
  Docker on Windows 11. It wrote the code, tests, Docker setup, commands and the first draft of the
  README and this file, from a written brief by Alexander (scope, hexagonal architecture, commit
  sequence, honesty rules).
- No other AI tools. Packages: Laravel 13, Horizon, Scout, Larastan, Pint, PHPUnit (all in `composer.lock`).

## How the work was checked (by running it, not by reading it)

- Every claim in the README comes from a command that was actually run; raw outputs are in `docs/evidence/`.
- The regression suite was run red against the legacy handler (13 failed, 6 passed) and committed before
  any fix; after the fix the same file, unchanged (`git diff` checked), passes.
- Full suite: 89 tests, 302 assertions, against real Postgres and real Redis containers.
- PHPStan (Larastan) level 9 with no errors and no baseline; Pint clean.
- The before/after workload ran against live Horizon workers, Redis and Postgres; every stored row was
  checked against the value the fake upstream served.
- The crash scenario was run for real (worker `SIGKILL`ed, Redis redelivery after `retry_after`), not only
  simulated in a unit test.
- Framework behaviour the design depends on was checked in the vendored source, not assumed:
  `Worker::timeoutForJob` (job `$timeout` overrides the worker's), `RedisQueue::pendingJobs/delayedJobs`
  and the `createdAt` payload field, `Http::failedConnection()` going through Guzzle's real error path.

## Incorrect suggestions or mistakes caught

1. **Timeout ordering.** The brief stated "worker `--timeout` < job `$timeout` < `retry_after`". Reading
   `Worker::timeoutForJob` shows the job's `$timeout` replaces the worker's, so that ordering is not the
   constraint; the constraint is *effective timeout < retry_after*. README section 3 explains it.
2. **Tests ran against the development database.** `compose.yaml` first used `env_file: .env`, which puts
   `DB_DATABASE=fansapi` into the real environment; Laravel reads `$_SERVER` before the values PHPUnit's
   `<env force="true">` sets, so `RefreshDatabase` was migrating the dev DB. Caught from a
   `QueryException` message showing `Database: fansapi`. Fixed by letting Laravel read `.env` itself, and
   `tests/TestCase.php` now refuses to run unless the database name ends in `_testing`. No real data was
   lost (the dev DB was empty at that point).
3. **The first crash demo did not crash.** The job compared the cache value with `===` to an int, but the
   Redis cache store returns numbers as strings (`"1"`), so the kill never fired. Confirmed with tinker,
   then fixed. The same review found that `Cache::pull()` would let *any* job consume the one-shot flag;
   replaced with `get()` + `forget()` (only the process whose DEL succeeds crashes).
4. **The first "before" workload measured the wrong thing.** The legacy handler did not send the account
   header, so the fake upstream counted its calls under no account (0 requests, revisions restarting at 1).
   Noticed from the numbers, fixed by sending the header (legacy production code used the account's
   credential too), and re-run; the committed report is the second run.
5. **`floatDiffInSeconds()` does not exist in Carbon 3.** Generated code used it; PHPStan caught it before
   it ever ran.
6. Smaller ones caught by tests: two `Http::fake()` calls in one test (the first stub wins, so the second
   case was silently not tested; split into two tests); `jsonb` reorders object keys (assertion made
   order-insensitive); a non-UUID job id in a test against a `uuid` column.

## What was NOT verified

- **The real upstream.** No request was ever made to onlyfans.com or any real API. The response formats,
  429/500 behaviour and per-account limits are the ones in the statement, reproduced by fixtures and a
  local fake. Real headers, real Retry-After formats and real rate-limit windows were not observed.
- **Scale.** 4 workers, 40 profiles, one Docker host. Nothing here measures 50 M jobs/day; the scaling
  section is reasoning plus a list of what to measure.
- **Crash variants**: a kill in the middle of the SQL statement, Redis restart/failover (losing reserved
  jobs), Postgres failover, network partitions between workers and Redis, multi-host clock skew.
- **Concurrency beyond 4 workers** on one Postgres; the revision guard relies on standard Postgres
  `READ COMMITTED` re-check semantics, which I did not stress with a dedicated race test.
- **The production plan** (pause supervisor, flag flip, damage queries) was not rehearsed on a staging
  system; `horizon:pause-supervisor` / `horizon:terminate` were only checked to exist.
- **Other hosts**: only run on Windows 11 + Docker Desktop. Not tried on macOS or Linux hosts.
- The HTML page was checked by tests and one request, not in several browsers.

## Alexander's review (to be completed by Alexander before submitting)

- [ ] Read `app/Domain` and `app/Application` line by line; can explain every rule without notes.
- [ ] Re-ran `composer check`, the two workloads and the crash demo on this machine.
- [ ] Walked through the incident: legacy test → red commit → fix → same test green.
- [ ] Changes made after review: _…_
- [ ] Time spent reviewing: _…_
