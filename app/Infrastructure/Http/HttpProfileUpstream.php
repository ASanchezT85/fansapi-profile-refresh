<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Port\ProfileUpstream;
use App\Application\Port\UpstreamFailure;
use App\Domain\Profile\AccountId;
use App\Domain\Profile\InvalidProfilePayload;
use App\Domain\Profile\ProfileHandle;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use JsonException;

/**
 * HTTP adapter for the upstream. Only translates: status codes and transport errors become
 * UpstreamFailure, the body becomes an array. No retries here (that is RetryPolicy's job) and
 * nothing secret in exception messages (the token travels in a header, never in the URL).
 */
final readonly class HttpProfileUpstream implements ProfileUpstream
{
    private const int JSON_MAX_DEPTH = 32;

    private const string CURL_TIMEOUT = 'cURL error 28';

    public function __construct(
        private Factory $http,
        private string $baseUrl,
        private string $token,
        private float $timeoutSeconds,
        private float $connectTimeoutSeconds,
        private int $maxBodyBytes,
    ) {}

    public function fetch(AccountId $account, ProfileHandle $handle): array
    {
        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->withToken($this->token)
                ->withHeaders(['X-Account-Id' => $account->value])
                ->acceptJson()
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->get('profiles/'.rawurlencode($handle->value));
        } catch (ConnectionException $e) {
            throw str_contains($e->getMessage(), self::CURL_TIMEOUT)
                ? UpstreamFailure::timeout()
                : UpstreamFailure::connectionFailed();
        }

        $status = $response->status();
        $body = $response->body();

        if ($status === 429) {
            throw UpstreamFailure::rateLimited($this->retryAfterSeconds($response->header('Retry-After')));
        }
        if ($status >= 500) {
            throw UpstreamFailure::serverError($status, strlen($body));
        }
        if ($status === 404 || $status === 410) {
            throw UpstreamFailure::notFound($status);
        }
        if ($status < 200 || $status >= 300) {
            throw UpstreamFailure::rejected($status);
        }
        if (strlen($body) > $this->maxBodyBytes) {
            throw InvalidProfilePayload::tooLarge(strlen($body));
        }

        try {
            $data = json_decode($body, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidProfilePayload::notAnObject();
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidProfilePayload::notAnObject();
        }

        return $data;
    }

    /**
     * Retry-After is either delay-seconds or an HTTP-date (RFC 9110). Absent or unparsable = null.
     */
    private function retryAfterSeconds(string $header): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0, $at - time());
    }
}
