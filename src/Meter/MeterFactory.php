<?php

namespace WebReinvent\VaahSignoz\Meter;

use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use WebReinvent\VaahSignoz\Tracer\TracerFactory;

class MeterFactory
{
    protected static $meterProvider = null;
    protected static $meter = null;
    protected static $counters = [];   // [name] => counter instance
    protected static $histograms = []; // [name] => histogram instance
    protected static $gauges = [];     // [name] => gauge instance

    /* ----------------------------------------------------------------- */
    /*  MeterProvider                                                    */
    /* ----------------------------------------------------------------- */

    public static function getMeterProvider()
    {
        if (self::$meterProvider !== null) {
            return self::$meterProvider;
        }

        $resource = self::buildResource();
        $exporter = self::getMetricExporter();

        // Wrap exporter in ExportingReader (required by MeterProvider)
        $reader = new ExportingReader($exporter);

        // Use builder pattern if available (SDK 1.7+), otherwise fall back to direct constructor.
        if (method_exists(MeterProvider::class, 'builder')) {
            $meterProvider = MeterProvider::builder()
                ->setResource($resource)
                ->addReader($reader)
                ->build();
        } else {
            // 1.x API: MeterProvider takes (resource, ...$readers) — pass the reader, not exporter
            $meterProvider = new MeterProvider(
                $resource,
                $reader
            );
        }

        self::$meterProvider = $meterProvider;

        return self::$meterProvider;
    }

    public static function getMeter()
    {
        if (self::$meter !== null) {
            return self::$meter;
        }

        $meterProvider = self::getMeterProvider();
        $setup = TracerFactory::getSetupConfig();

        self::$meter = $meterProvider->getMeter(
            $setup['serviceName'],
            $setup['version']
        );

        return self::$meter;
    }

    /* ----------------------------------------------------------------- */
    /*  Metric Exporter                                                 */
    /* ----------------------------------------------------------------- */

    protected static function getMetricExporter()
    {
        $transport = self::getMetricTransport();

        return new MetricExporter($transport);
    }

    protected static function getMetricTransport()
    {
        $config = config('vaahsignoz.otel');
        $endpoint = str_replace('/v1/traces', '/v1/metrics', $config['endpoint'] ?? 'http://localhost:4318/v1/traces');

        return TracerFactory::createTransport($endpoint);
    }

    /* ----------------------------------------------------------------- */
    /*  Resource                                                         */
    /* ----------------------------------------------------------------- */

    protected static function buildResource(): ResourceInfo
    {
        $resource = ResourceInfoFactory::defaultResource();

        $otel = config('vaahsignoz.otel');
        // Version-agnostic: use TracerFactory's createResourceInfo helper
        $appInfo = TracerFactory::createResourceInfo([
            'service.name' => $otel['service_name'] ?? 'laravel-app',
            'service.version' => $otel['version'] ?? '0.0.0',
            'deployment.environment' => $otel['environment'] ?? 'local',
        ]);

        return $resource->merge($appInfo);
    }

    /* ----------------------------------------------------------------- */
    /*  Convenience methods — create / get metrics                       */
    /* ----------------------------------------------------------------- */

    /**
     * Create or retrieve a counter metric
     * Returns a no-op counter if metrics are disabled globally.
     *
     * SDK version compatibility:
     * - SDK 1.2-1.5: createCounter($name) only — no unit/description
     * - SDK 1.6+:    createCounter($name, $unit, $description)
     *
     * Returns a WrappedCounter that also abstracts add($value, $attributes) differences.
     */
    public static function counter(string $name, string $unit = '', string $description = '')
    {
        if (!config('vaahsignoz.metrics.enabled', true)) {
            return new NoOpCounter();
        }

        if (!isset(self::$counters[$name])) {
            $meter = self::getMeter();
            $counter = self::createMeterInstrument($meter, 'createCounter', $name, $unit, $description);
            self::$counters[$name] = new WrappedCounter($counter);
        }

        return self::$counters[$name];
    }

    /**
     * Create or retrieve a histogram metric
     * Returns a no-op histogram if metrics are disabled globally.
     *
     * SDK version compatibility:
     * - SDK 1.2-1.5: createHistogram($name) only
     * - SDK 1.6+:    createHistogram($name, $unit, $description)
     *
     * Returns a WrappedHistogram that also abstracts record($value, $attributes) differences.
     */
    public static function histogram(string $name, string $unit = '', string $description = '')
    {
        if (!config('vaahsignoz.metrics.enabled', true)) {
            return new NoOpHistogram();
        }

        if (!isset(self::$histograms[$name])) {
            $meter = self::getMeter();
            $histogram = self::createMeterInstrument($meter, 'createHistogram', $name, $unit, $description);
            self::$histograms[$name] = new WrappedHistogram($histogram);
        }

        return self::$histograms[$name];
    }

    /**
     * Create or retrieve an up-down counter (gauge-like)
     * Returns a no-op up-down counter if metrics are disabled globally.
     * NOTE: Named "gauge" for convenience but creates an UpDownCounter, not ObservableGauge.
     *
     * Returns a WrappedUpDownCounter that also abstracts add($value, $attributes) differences.
     */
    public static function gauge(string $name, string $unit = '', string $description = '')
    {
        if (!config('vaahsignoz.metrics.enabled', true)) {
            return new NoOpUpDownCounter();
        }

        if (!isset(self::$gauges[$name])) {
            $meter = self::getMeter();
            $counter = self::createMeterInstrument($meter, 'createUpDownCounter', $name, $unit, $description);
            self::$gauges[$name] = new WrappedUpDownCounter($counter);
        }

        return self::$gauges[$name];
    }

    /**
     * Create a metric instrument on the Meter, handling version differences.
     *
     * Older SDK (1.2-1.5): only accepts $name
     * Newer SDK (1.6+):    accepts $name, $unit, $description
     */
    protected static function createMeterInstrument($meter, string $method, string $name, string $unit, string $description)
    {
        // Use reflection to check if the method accepts more than 1 parameter
        try {
            $ref = new \ReflectionMethod($meter, $method);
            $paramCount = $ref->getNumberOfParameters();
        } catch (\Throwable $e) {
            $paramCount = 1;
        }

        if ($paramCount >= 3) {
            return $meter->$method($name, $unit, $description);
        }

        return $meter->$method($name);
    }

    /* ----------------------------------------------------------------- */
    /*  Shutdown                                                         */
    /* ----------------------------------------------------------------- */

    public static function shutdown(): void
    {
        if (self::$meterProvider !== null) {
            if (method_exists(self::$meterProvider, 'shutdown')) {
                self::$meterProvider->shutdown();
            } elseif (method_exists(self::$meterProvider, 'forceFlush')) {
                self::$meterProvider->forceFlush();
            }
        }
    }
}
