<?php

namespace WebReinvent\VaahSignoz\Instrumentation;

use Illuminate\Support\Facades\Event;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
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

        $span->setAttribute('http.url', $event->request->url());
        $span->setAttribute('http.method', $event->request->method());
        $span->end();
    }

    public function onResponseReceived(ResponseReceived $event)
    {
        $tracer = TracerFactory::getTracer();
        $span = $tracer->spanBuilder('http.client.response')->startSpan();
        $span->setAttribute('http.url', $event->request->url());
        $span->setAttribute('http.status_code', $event->response->status());
        $span->end();
    }
}
