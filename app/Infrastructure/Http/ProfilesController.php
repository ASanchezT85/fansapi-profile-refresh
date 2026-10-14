<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Persistence\ProfileRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The whole UI: one read-only page listing profiles, searchable through Scout.
 */
final class ProfilesController
{
    private const int PER_PAGE = 50;

    public function __invoke(Request $request): View
    {
        $query = trim($request->string('q')->toString());

        $profiles = $query === ''
            ? ProfileRecord::query()->orderBy('account_id')->orderBy('handle')->paginate(self::PER_PAGE)
            : ProfileRecord::search($query)->paginate(self::PER_PAGE);

        return view('profiles', ['profiles' => $profiles->withQueryString(), 'query' => $query]);
    }
}
