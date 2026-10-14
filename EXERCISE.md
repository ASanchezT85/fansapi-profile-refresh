# Test task for Engineer @FansAPI (texto fiel del enunciado, leído el 2026-10-14)

Fuente: https://fansapi.notion.site/test-task-for-engineer-onyfansapi
Plazo de entrega: viernes 16 de octubre de 2026. Enviar a join@fansapi.com (hilo del correo "Application - Senior Full-Stack Engineer (Laravel + Inertia + React)"; CC join@onlyfansapi.com es válido).

Build a small Laravel 13 OnlyFans profile-fetch service and use it to reproduce and fix an upstream production failure (e.g. OnlyFans APIs having trouble). Show how you investigate, protect valid data and verify recovery.

TL;DR: Retrieve full profile information from onlyfans.com/madison420ivy without burning too much RAM and make it scalable with background queue workers (Horizon).

Six-seven hours, including setup. Top candidates finish within 2 hours. Expected: working code, evidence that you tested it, a clear explanation of decisions. AI, documentation and existing packages are allowed. You must understand and be able to explain everything submitted, including AI-generated code. Submit when time is up; explain anything unfinished, why other work was prioritized, and what remains broken.

## Setup and scope
Laravel 13, Laravel Scout for search, Redis and Horizon. A basic Laravel project is enough. UI can be very basic. Keep the application small: profiles, refresh jobs, a way to trigger a repeatable workload, enough logs to follow it. Dashboard, search and a full API are not required.

## The incident
The oldest waiting jobs keep getting older. Workers report completed jobs, but some profiles show stale or empty data. One busy account delays the others. No application deployment since the problem started.

Reproduce the data bug with a handler that reads likes from the top level of a JSON response, defaults a missing value to zero and marks every response as a successful refresh. The upstream starts returning likes inside a profile object instead. Some requests also return HTTP 429 without Retry-After (retry on a randomized delay) or HTTP 500 with an empty body.

Profile whose last valid likes value is 120,000. Old-format success response: `likes: 120000`, `revision: 10`. Changed response: `profile: { likes: 121000 }`, `revision: 11`. `revision` is a strictly increasing upstream version per profile; it lets you test out-of-order responses without relying on timing.

Record what the broken handler does for these cases. Show the failing test or reproduction BEFORE fixing it. A completed queue job is not proof the profile was refreshed.

## Required work
### 1. Protect the data (70% of the grade is "data retrieval works")
- Accept both response formats. Validate the response before changing stored data.
- A failed request or malformed response must not erase the last valid profile nor count as a successful refresh.
- Missing likes field = invalid; explicit numeric zero = valid. Reject negative or non-numeric likes.
- Duplicate or concurrent jobs must not create duplicate profiles. A late response with an older revision must not overwrite newer accepted data.
- Keep last attempt, last successful refresh and failure details distinguishable.
- Refresh profiles above 100,000 likes every 24 h, all others every 72 h. Exactly 100,000 belongs to the 72 h group. Avoid repeatedly scheduling work already pending.
- Focused tests for: response-format bug, failed requests, duplicate execution, older response arriving last. Run them and include results.

### 2. Keep the queue moving (15%)
- Handle rate limits, timeouts, temporary server errors and permanent failures differently, with a retry limit.
- Explain how worker timeout, job timeout and Redis `retry_after` relate. How to avoid two workers processing the same long-running attempt?
- Repeatable workload with at least two accounts. One produces a burst of jobs that hit rate limits while the other keeps getting valid responses. Small job counts and delays; record them.
- Demonstrate the failing account does not consume all capacity. Show successful refreshes continuing for the other account and recovery when the rate limit clears.
- Replay a job after its database write to check what happens if a worker dies before acknowledging. Explain what the local test does and does not prove about a real worker crash.
- Log enough to follow account, profile, job and attempt through failure and recovery. No secrets in logs.
- For the same workload before and after: successful refreshes, attempts per successful refresh, age of the oldest waiting job. Check the stored data too.

### 3. Explain the next scaling limit (15% with observability)
Planning scenario: 50 million jobs/day (~579 jobs/s average; says nothing about peak or capacity). Explain what to measure next: peak arrivals, job duration, concurrent work, retry amplification, DB and Redis pressure, upstream limits, largest accounts. Identify the likely bottleneck of your solution, the evidence to collect, and the first change. A small local workload is not proof of 50M/day.

## What to submit
Email join@fansapi.com a DOWNLOAD LINK to `Firstname_Lastname.zip` with: source, dependency lockfiles, setup instructions, local response fixtures, regression tests, short README.
- README: the failure, evidence, fix, before-and-after results, remaining limits, time spent, plus a short production plan (first 15 minutes: what to check and mitigate; how to roll out the fix; when to roll back; how to verify recovery).
- AI.md: tools used, what was checked, any incorrect suggestion caught, what was NOT verified.

## How they assess
Data retrieval works 70% · Queue recovery, retry behaviour, account isolation 15% · Observability, scaling judgment, explanation 15%. Then a 30-minute call: reproduce one failure and walk through the fix; investigate a new symptom; discuss an undocumented integration and a production incident.
