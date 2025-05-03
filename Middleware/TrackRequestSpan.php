<?php

namespace WebReinvent\VaahSignoz\Middleware;

use Closure;
use WebReinvent\VaahSignoz\Helpers\Telemetry;

class TrackRequestSpan
{
    public function handle($request, Closure $next)
    {
        $span = Telemetry::startRequestSpan($request);
        try {
            $response = $next($request);

            // Set status code and uri attributes
            $span->setAttribute('http.status_code', $response->getStatusCode());
            $span->setAttribute('http.route', $request->route()?->getName());

            return $response;
        } catch (\Exception $e) {
            $span->setAttribute('exception.type', get_class($e));
            $span->setAttribute('exception.message', $e->getMessage());
            $span->setAttribute('exception.stacktrace', $e->getTraceAsString());
            throw $e;
        } finally {
            $span->end();
        }
    }
}
