<?php

namespace App\Http\Middleware;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful as SanctumEnsureFrontendRequestsAreStateful;
use Laravel\Sanctum\Sanctum;

/**
 * Same as Sanctum, but when Referer/Origin are missing (some same-origin fetch calls),
 * treat the request as first-party if the HTTP Host matches configured stateful domains.
 */
class EnsureFrontendRequestsAreStateful extends SanctumEnsureFrontendRequestsAreStateful
{
    public static function fromFrontend($request): bool
    {
        $domain = $request->headers->get('referer') ?: $request->headers->get('origin');

        if (is_null($domain)) {
            $host = $request->getHttpHost();
            $domain = Str::endsWith($host, '/') ? $host : "{$host}/";
            $stateful = array_filter(config('sanctum.stateful', []));

            return Str::is(Collection::make($stateful)->map(function ($uri) use ($request) {
                $uri = $uri === Sanctum::$currentRequestHostPlaceholder ? $request->getHttpHost() : $uri;

                return trim($uri).'/*';
            })->all(), $domain);
        }

        $domain = Str::replaceFirst('https://', '', $domain);
        $domain = Str::replaceFirst('http://', '', $domain);
        $domain = Str::endsWith($domain, '/') ? $domain : "{$domain}/";

        $stateful = array_filter(config('sanctum.stateful', []));

        return Str::is(Collection::make($stateful)->map(function ($uri) use ($request) {
            $uri = $uri === Sanctum::$currentRequestHostPlaceholder ? $request->getHttpHost() : $uri;

            return trim($uri).'/*';
        })->all(), $domain);
    }
}
