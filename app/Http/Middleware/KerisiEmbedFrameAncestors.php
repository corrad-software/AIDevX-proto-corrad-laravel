<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Allow the KERISI admin SPA to load inside an iframe on trusted parent origins (MYFIS2, etc.).
 */
class KerisiEmbedFrameAncestors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $origins = config('kerisi.embed_iframe_origins', []);
        if (! is_array($origins) || $origins === []) {
            return $response;
        }

        $allowed = ["'self'"];
        foreach ($origins as $origin) {
            $origin = trim((string) $origin);
            if ($origin === '') {
                continue;
            }
            $parts = parse_url($origin);
            if ($parts === false || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
                continue;
            }
            $built = strtolower((string) $parts['scheme']).'://'.$parts['host'];
            if (isset($parts['port'])) {
                $built .= ':'.(int) $parts['port'];
            }
            $allowed[] = $built;
        }

        if (count($allowed) <= 1) {
            return $response;
        }

        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors '.implode(' ', $allowed)
        );

        return $response;
    }
}
