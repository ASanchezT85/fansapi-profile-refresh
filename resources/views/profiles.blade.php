<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profiles</title>
    <style>
        body { font: 14px/1.4 system-ui, sans-serif; margin: 24px; color: #1f2328; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid #d0d7de; padding: 6px 8px; text-align: left; white-space: nowrap; }
        th { background: #f6f8fa; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: #656d76; }
        .bad { color: #cf222e; }
    </style>
</head>
<body>
<h1>Profiles</h1>
<form method="get">
    <label>Search handle or account <input type="search" name="q" value="{{ $query }}"></label>
    <button type="submit">Search</button>
</form>
<p class="muted">{{ $profiles->total() }} profile(s). Last attempt, last success and last failure are tracked separately.</p>
<table>
    <thead>
    <tr>
        <th>Account</th><th>Handle</th><th class="num">Likes</th><th class="num">Revision</th>
        <th>Last success</th><th>Last attempt</th><th>Last failure</th><th>Next refresh</th><th>Pending since</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($profiles as $profile)
        <tr>
            <td>{{ $profile->account_id }}</td>
            <td>{{ $profile->handle }}</td>
            <td class="num">{{ $profile->likes === null ? '-' : number_format($profile->likes) }}</td>
            <td class="num">{{ $profile->revision ?? '-' }}</td>
            <td>{{ $profile->last_successful_refresh_at?->toDateTimeString() ?? 'never' }}</td>
            <td>{{ $profile->last_attempt_at?->toDateTimeString() ?? '-' }}</td>
            <td @class(['bad' => $profile->last_failure_at !== null && ($profile->last_successful_refresh_at === null || $profile->last_failure_at > $profile->last_successful_refresh_at)])>
                @if ($profile->last_failure_at)
                    {{ $profile->last_failure_reason }} at {{ $profile->last_failure_at->toDateTimeString() }}
                    (x{{ $profile->consecutive_failures }}) <span class="muted">{{ $profile->last_failure_detail }}</span>
                @else
                    -
                @endif
            </td>
            <td>{{ $profile->next_refresh_at?->toDateTimeString() ?? 'due now' }}</td>
            <td>{{ $profile->refresh_pending_since?->toDateTimeString() ?? '-' }}</td>
        </tr>
    @empty
        <tr><td colspan="9" class="muted">No profiles. Run <code>php artisan refresh:workload</code> or <code>php artisan profiles:track</code>.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $profiles->links() }}
</body>
</html>
