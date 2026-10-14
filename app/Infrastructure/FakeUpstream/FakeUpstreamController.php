<?php

declare(strict_types=1);

namespace App\Infrastructure\FakeUpstream;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class FakeUpstreamController
{
    public function __invoke(Request $request, FakeUpstream $upstream, string $handle): Response
    {
        if ($request->bearerToken() !== config()->string('services.upstream.token')) {
            return new Response('{"error":"unauthorized"}', 401, ['Content-Type' => 'application/json']);
        }

        [$status, $body, $headers] = $upstream->respond((string) $request->header('X-Account-Id', ''), $handle);

        return new Response($body, $status, $headers);
    }
}
