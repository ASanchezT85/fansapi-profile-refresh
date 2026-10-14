<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Port\ProfileUpstream;
use App\Application\Port\RefreshQueue;
use App\Application\Refresh\RefreshCommand;
use App\Application\Refresh\RefreshProfile;
use App\Application\Refresh\TrackProfile;
use App\Infrastructure\Http\FansApiProfileUpstream;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Runs ONE real refresh through the fixed pipeline, against the real provider, in this process only:
 * validation, revision-guarded write, failure classification and structured logs are the same code the
 * workers run. Nothing is queued: a retry decision is printed instead of handed to the workers (which
 * keep using the local fake upstream). Costs one provider credit per run.
 *
 * Needs FANSAPI_KEY in .env (your own key; it is sent only as a Bearer header and never logged).
 */
final class RefreshLiveCommand extends Command
{
    protected $signature = 'refresh:live
        {handle=madison420ivy : Public profile username}
        {--account=fansapi-live : Local account id the profile is tracked under}
        {--record : Save the provider response as tests/Fixtures/upstream/fansapi-live-{handle}.json}';

    protected $description = 'Refresh one profile from the real OnlyFans API (FansAPI) through the fixed pipeline';

    public function handle(TrackProfile $track): int
    {
        if (config()->string('services.fansapi.key') === '') {
            $this->error('FANSAPI_KEY is empty. Put your own OnlyFans API key in .env to call the real provider.');

            return self::FAILURE;
        }

        $handle = $this->stringValue($this->argument('handle'));
        $account = $this->stringValue($this->option('account'));

        $rawBody = null;
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$rawBody): void {
            $rawBody = $event->response->body();
        });

        app()->bind(ProfileUpstream::class, FansApiProfileUpstream::class);
        app()->instance(RefreshQueue::class, new class($this) implements RefreshQueue
        {
            public function __construct(private readonly Command $console) {}

            public function enqueue(RefreshCommand $command, int $delaySeconds = 0): void
            {
                $this->console->warn(sprintf('Retry decided (attempt %d in %ds); not queued, this is a one-shot live run.', $command->attempt, $delaySeconds));
            }
        });

        $id = $track->execute($account, $handle);
        $outcome = app(RefreshProfile::class)->execute(new RefreshCommand($id));

        $this->line(sprintf('Outcome: %s%s', $outcome->status->value, $outcome->reason === null ? '' : ' ('.$outcome->reason->value.')'));
        $this->line('Stored: '.json_encode(DB::table('profiles')->where('id', $id)->first([
            'account_id', 'handle', 'likes', 'revision', 'last_successful_refresh_at', 'last_attempt_at',
            'last_failure_reason', 'last_failure_detail', 'next_refresh_at',
        ]), JSON_PRETTY_PRINT));

        if ($this->option('record') === true) {
            $this->record($handle, $rawBody);
        }

        return $outcome->isSuccessfulRefresh() ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Saves the real response as a fixture, without the account's credit balance.
     */
    private function record(string $handle, ?string $rawBody): void
    {
        $decoded = $rawBody === null ? null : json_decode($rawBody, true);
        if (! is_array($decoded)) {
            $this->warn('Nothing to record: the provider did not return a JSON object.');

            return;
        }

        if (is_array($decoded['_meta'] ?? null)) {
            unset($decoded['_meta']['_credits']);
        }

        $path = sprintf('tests/Fixtures/upstream/fansapi-live-%s.json', $handle);
        file_put_contents(base_path($path), json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        $this->info('Recorded '.$path);
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
