<?php

namespace Webreinvent\VaahSignoz\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webreinvent\VaahSignoz\Tracer\TracerFactory;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;

class RequestInstrumentation
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $tracer = TracerFactory::getTracer();

        $span = $tracer->spanBuilder($request->method() . ' ' . $request->path())
            ->setAttribute('http.method', $request->method())
            ->setAttribute('http.url', $request->fullUrl())
            ->setAttribute('http.scheme', $request->getScheme())
            ->setAttribute('http.host', $request->getHost())
            ->setAttribute('http.target', $request->getRequestUri())
            ->startSpan();

        try {
            /** @var Response $response */
            $response = $next($request);

            $span->setAttribute('http.status_code', $response->getStatusCode());

            return $response;
        } catch (\Throwable $e) {
            $span->setAttribute('exception.type', get_class($e));
            $span->setAttribute('exception.message', $e->getMessage());
            $span->setAttribute('exception.file', $e->getFile());
            $span->setAttribute('exception.line', $e->getLine());
            if (class_exists(StatusCode::class)) {
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            }
            throw $e;
        } finally {
            $span->end();
        }
    }
}
