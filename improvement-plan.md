# VaahSignoz — SDK Compatibility Improvements Plan

## Goal
Make `webreinvent/vaahsignoz` compatible across `open-telemetry/sdk ^1.2` through `^1.14.0` by replacing fragile direct API calls with version-agnostic patterns.

## Problem
OpenTelemetry PHP SDK has undergone significant API changes between versions 1.2 and 1.14:
- Constructor signatures change (e.g., `PsrTransport`, `TracerProvider`, `MeterProvider`)
- Builder patterns replace direct constructors
- Method signatures evolve (e.g., `Counter::add($value)` → `add($value, $attributes)`)
- Class renames and namespace moves

## Fixes Implemented

### 1. PsrTransport — Replace direct instantiation with factory

**Before:** Direct `new PsrTransport($client, $httpFactory, ...)` with 10 positional parameters — constructor signature varies across SDK versions.

**After:** Use `PsrTransportFactory::discover()->create($endpoint, ContentTypes::PROTOBUF)` — factory pattern is stable across versions.

**Files:** `src/Tracer/TracerFactory.php`

---

### 2. TracerProvider — Use builder pattern

**Before:** `new TracerProvider($processor, $sampler, $resource)` — constructor signature changed in SDK 1.7+ to use builder pattern.

**After:** `TracerProvider::builder()->addSpanProcessor()->setResource()->setSampler()->build()` with `method_exists` guard for fallback.

**Files:** `src/Tracer/TracerFactory.php`

---

### 3. ResourceInfo::create() — Remove Attributes wrapper

**Before:** `ResourceInfo::create(Attributes::create($attributes))` — older SDK required `Attributes` object, newer SDK accepts plain array.

**After:** `ResourceInfo::create($attributes)` — plain array works across all versions.

**Files:** `src/Tracer/TracerFactory.php`, `src/Meter/MeterFactory.php`

---

### 4. Meter::createCounter/createHistogram/createUpDownCounter — Handle signature differences

**Before:** `$meter->createCounter($name, $unit, $description)` — older SDK only accepts `$name`.

**After:** Reflection-based check — if method accepts ≥3 params, pass all 3; otherwise pass only `$name`.

**Files:** `src/Meter/MeterFactory.php`

---

### 5. Counter::add($value, $attributes) — Handle signature differences

**Before:** `->add(1, ['key' => 'value'])` — older SDK only accepts `$value`.

**After:** Wrapper classes (`WrappedCounter`, `WrappedHistogram`, `WrappedUpDownCounter`) that use reflection to detect if the underlying method accepts an attributes parameter.

**Files:** `src/Meter/WrappedCounter.php`, `src/Meter/WrappedHistogram.php`, `src/Meter/WrappedUpDownCounter.php`

---

### 6. UpDownCounter::add($value, $attributes) — Same as #5

**After:** `WrappedUpDownCounter` with reflection-based attribute detection.

**Files:** `src/Meter/WrappedUpDownCounter.php`

---

### 7. Histogram::record($value, $attributes) — Same as #5

**After:** `WrappedHistogram` with reflection-based attribute detection.

**Files:** `src/Meter/WrappedHistogram.php`

---

### 8. StatusCode — Guard against namespace changes

**Before:** Direct `use OpenTelemetry\API\Trace\StatusCode` — class may be moved or missing in some SDK versions.

**After:** `InstrumentationHelper::setSpanStatus($span, 'error', $message)` — central helper that guards with `class_exists` and uses string-based status codes.

**Files:** `src/Helpers/InstrumentationHelper.php` (new helper method)
**Refactored:** All instrumentation files (removed `StatusCode` import, use helper instead)

---

### 9. TraceContextPropagator::inject() — Handle signature differences

**Before:** `->inject($carrier, null, Context::getCurrent())` — older SDK only accepts 2 arguments.

**After:** Reflection-based check — pass 3 args if method accepts ≥3, otherwise pass 1.

**Files:** `src/Instrumentation/ClientInstrumentation.php`

---

### 10. TracerProvider::shutdown() / MeterProvider::shutdown() — Guard method existence

**Before:** `->shutdown()` — some versions use `forceFlush()`.

**After:** `method_exists($provider, 'shutdown') ? shutdown() : forceFlush()`.

**Files:** `src/Tracer/TracerFactory.php`, `src/Meter/MeterFactory.php`

---

### 11. MeterProvider fallback — Fix wrong argument

**Before:** `new MeterProvider($resource, $exporter, exportInterval: 10)` — passed raw exporter instead of reader.

**After:** `new MeterProvider($resource, $reader)` — passes the `ExportingReader` wrapped around the exporter.

**Files:** `src/Meter/MeterFactory.php`

---

### 12. BatchSpanProcessorBuilder — Version-agnostic batch processor creation

**Before:** Direct `new BatchSpanProcessorBuilder($exporter)->setScheduleDelay()` — class removed in SDK 1.7+, method names vary.

**After:** `BatchSpanProcessor::builder($exporter)` with `method_exists` guards for all config methods. Falls back to `BatchSpanProcessorBuilder` for older SDK.

**Files:** `src/Tracer/TracerFactory.php` (already implemented in previous fix)

---

## Summary by File

| File | Changes |
|------|---------|
| `TracerFactory.php` | Use PsrTransportFactory, TracerProvider builder, ResourceInfo::create(array), shutdown guard |
| `MeterFactory.php` | ResourceInfo::create(array), Meter::create* reflection, wrapped metrics, MeterProvider fallback fix, shutdown guard |
| `WrappedCounter.php` | **New** — Reflection-based counter wrapper |
| `WrappedHistogram.php` | **New** — Reflection-based histogram wrapper |
| `WrappedUpDownCounter.php` | **New** — Reflection-based up-down counter wrapper |
| `InstrumentationHelper.php` | Add `setSpanStatus()` helper |
| `ClientInstrumentation.php` | Replace StatusCode, inject() reflection |
| `ExceptionInstrumentation.php` | Replace StatusCode |
| `QueueInstrumentation.php` | Replace StatusCode |
| `PhpErrorInstrumentation.php` | Replace StatusCode |
| `DatabaseErrorInstrumentation.php` | Replace StatusCode |
| `TransactionInstrumentation.php` | Replace StatusCode |
| `QueryInstrumentation.php` | Replace StatusCode |
| `RequestInstrumentation.php` | Replace StatusCode |

## Testing Strategy

1. **`composer validate`** on the package
2. **PHP syntax check** — all files pass `php -l`
3. **Runtime test** — Deploy with SDK 1.2, 1.5, 1.7, 1.9, and 1.14 to verify no fatal errors
4. **Span verification** — Verify spans are exported correctly in SigNoz
5. **Metric verification** — Verify counters, histograms, and gauges work correctly
