<?php
namespace WebReinvent\VaahSignoz\Middleware;

use Closure;
use WebReinvent\VaahSignoz\src\Services\OpenTelemetryService;

class SigNozTraceMiddleware
{
    public function handle($request, Closure $next)
    {
        $tracer = OpenTelemetryService::init();

        $span = $tracer->spanBuilder('HTTP ' . $request->method())->startSpan();
        $scope = $span->activate();

        $response = $next($request);

        $span->end();
        $scope->detach();

        return $response;
    }
}
