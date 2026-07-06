<?php

namespace WebReinvent\VaahSignoz\Meter;

use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
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

        // Use builder pattern if available (2.x), otherwise fall back to direct constructor (1.x)
        if (method_exists(MeterProvider::class, 'builder')) {
            $meterProvider = MeterProvider::builder()
                ->setResource($resource)
                ->addReader($reader)
                ->build();
        } else {
            // 1.x API: MeterProvider takes (resource, exporter, exportInterval)
            $meterProvider = new MeterProvider(
                $resource,
                $exporter,
                exportInterval: 10
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

        $client = self::createClient($config);
        $httpFactory = new HttpFactory();

        return new PsrTransport(
            $client,
            $httpFactory,
            $httpFactory,
            $endpoint,
            'application/x-protobuf',
            [],
            [],
            100,
            3
        );
    }

    /**
     * Fallback client creation when shared client isn't available
     */
    protected static function createClient(array $config): Client
    {
        return new Client([
            'timeout' => $config['http_timeout'] ?? 3.0,
            'connect_timeout' => $config['http_connect_timeout'] ?? 3.0,
        ]);
    }

    /* ----------------------------------------------------------------- */
    /*  Resource                                                         */
    /* ----------------------------------------------------------------- */

    protected static function buildResource(): ResourceInfo
    {
        $resource = ResourceInfoFactory::defaultResource();

        $otel = config('vaahsignoz.otel');
        $appInfo = ResourceInfo::create(Attributes::create([
            'service.name' => $otel['service_name'] ?? 'laravel-app',
            'service.version' => $otel['version'] ?? '0.0.0',
            'deployment.environment' => $otel['environment'] ?? 'local',
        ]));

        return $resource->merge($appInfo);
    }

    /* ----------------------------------------------------------------- */
    /*  Convenience methods — create / get metrics                       */
    /* ----------------------------------------------------------------- */

    /**
     * Create or retrieve a counter metric
     */
    public static function counter(string $name, string $unit = '', string $description = '')
    {
        if (!isset(self::$counters[$name])) {
            $meter = self::getMeter();
            self::$counters[$name] = $meter->createCounter($name, $unit, $description);
        }

        return self::$counters[$name];
    }

    /**
     * Create or retrieve a histogram metric
     */
    public static function histogram(string $name, string $unit = '', string $description = '')
    {
        if (!isset(self::$histograms[$name])) {
            $meter = self::getMeter();
            self::$histograms[$name] = $meter->createHistogram($name, $unit, $description);
        }

        return self::$histograms[$name];
    }

    /**
     * Create or retrieve an up-down counter (gauge-like)
     */
    public static function gauge(string $name, string $unit = '', string $description = '')
    {
        if (!isset(self::$gauges[$name])) {
            $meter = self::getMeter();
            self::$gauges[$name] = $meter->createUpDownCounter($name, $unit, $description);
        }

        return self::$gauges[$name];
    }

    /* ----------------------------------------------------------------- */
    /*  Shutdown                                                         */
    /* ----------------------------------------------------------------- */

    public static function shutdown(): void
    {
        if (self::$meterProvider !== null) {
            self::$meterProvider->shutdown();
        }
    }
}
