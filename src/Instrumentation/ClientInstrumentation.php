<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use OpenTelemetry\API\Trace\StatusCode;
use WebReinvent\VaahSignoz\Exceptions\VaahSignozException;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Context;

class ClientInstrumentation
{
    public function boot()
    {
        try {
            Event::listen(RequestSending::class, [$this, 'onRequestSending']);
            Event::listen(ResponseReceived::class, [$this, 'onResponseReceived']);
        } catch (\Throwable $e) {
            throw new VaahSignozException('Failed to boot client instrumentation.', 0, $e);
        }
    }

    public function onRequestSending(RequestSending $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('http.client.request')->startSpan();

        // Inject trace context headers into outgoing HTTP request
        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier, null, Context::getCurrent());
        foreach ($carrier as $key => $value) {
            $event->request->withHeader($key, $value);
        }

        $request = $event->request;

        $span->setAttribute('http.url', $event->request->url());
        $span->setAttribute('http.method', $event->request->method());
        $span->setAttribute('http.scheme', $request->getUri()->getScheme());
        $span->setAttribute('http.host', $request->getUri()->getHost());
        $span->setAttribute('http.target', $request->getUri()->getPath());
        $span->setAttribute('net.peer.name', $request->getUri()->getHost());
        $span->setAttribute('net.peer.port', $request->getUri()->getPort());
        $span->setAttribute('http.user_agent', $request->getHeaderLine('User-Agent'));
        $span->setAttribute('http.request_id', $request->getHeaderLine('X-Request-ID'));
        $span->end();
    }

    public function onResponseReceived(ResponseReceived $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('http.client.response')->startSpan();
        $span->setAttribute('http.url', $event->request->url());
        $span->setAttribute('http.status_code', $event->response->status());

        $statusCode = $event->response->status();

        if ($statusCode >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR, 'Server error');
            $span->setAttribute('http.status_severity', 'error');
        } elseif ($statusCode >= 400) {
            // OpenTelemetry does not have a "warning" status, so use unset and add an attribute
            $span->setStatus(StatusCode::STATUS_UNSET, 'Client warning');
            $span->setAttribute('http.status_severity', 'warning');
        } else {
            $span->setStatus(StatusCode::STATUS_OK, 'OK');
            $span->setAttribute('http.status_severity', 'ok');
        }

        $span->end();
    }
}
