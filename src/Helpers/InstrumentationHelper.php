<?php

namespace WebReinvent\VaahSignoz\Helpers;

class InstrumentationHelper
{
    /**
     * Static property to store the current exception ID for correlation
     */
    protected static $currentExceptionId = null;
    
    /**
     * Static property to store the current trace ID for correlation
     */
    protected static $currentTraceId = null;
    
    /**
     * Static property to store the current span ID for correlation
     */
    protected static $currentSpanId = null;
    
    /**
     * Get the host identifier - prefer domain name over hostname
     *
     * @param \Illuminate\Http\Request|null $request
     * @return string
     */
    public static function getHostIdentifier($request = null)
    {
        // First try to get the domain name from the request
        if ($request && method_exists($request, 'getHost') && $request->getHost()) {
            return $request->getHost();
        }
        
        // Try to get from the global request if available
        if (function_exists('request') && request() && method_exists(request(), 'getHost') && request()->getHost()) {
            return request()->getHost();
        }
        
        // Fall back to environment variable if set
        if (function_exists('env') && env('APP_URL')) {
            $parsedUrl = parse_url(env('APP_URL'));
            if (isset($parsedUrl['host'])) {
                return $parsedUrl['host'];
            }
        }
        
        // Last resort: use the server hostname
        return gethostname();
    }
    
    /**
     * Set the current exception ID for correlation
     *
     * @param string $exceptionId
     * @return void
     */
    public static function setCurrentExceptionId($exceptionId)
    {
        self::$currentExceptionId = $exceptionId;
    }
    
    /**
     * Get the current exception ID for correlation
     *
     * @return string|null
     */
    public static function getCurrentExceptionId()
    {
        return self::$currentExceptionId;
    }
    
    /**
     * Set the current trace ID for correlation
     *
     * @param string $traceId
     * @return void
     */
    public static function setCurrentTraceId($traceId)
    {
        // Ensure the trace ID is properly formatted for SignOz
        // SignOz expects trace IDs to be 32-character hex strings
        if (strlen($traceId) > 32) {
            $traceId = substr($traceId, 0, 32);
        } elseif (strlen($traceId) < 32) {
            // Pad with zeros if needed
            $traceId = str_pad($traceId, 32, '0', STR_PAD_LEFT);
        }
        
        self::$currentTraceId = $traceId;
    }
    
    /**
     * Get the current trace ID for correlation
     *
     * @return string|null
     */
    public static function getCurrentTraceId()
    {
        return self::$currentTraceId;
    }
    
    /**
     * Set the current span ID for correlation
     *
     * @param string $spanId
     * @return void
     */
    public static function setCurrentSpanId($spanId)
    {
        // Ensure the span ID is properly formatted for SignOz
        // SignOz expects span IDs to be 16-character hex strings
        if (strlen($spanId) > 16) {
            $spanId = substr($spanId, 0, 16);
        } elseif (strlen($spanId) < 16) {
            // Pad with zeros if needed
            $spanId = str_pad($spanId, 16, '0', STR_PAD_LEFT);
        }
        
        self::$currentSpanId = $spanId;
    }
    
    /**
     * Get the current span ID for correlation
     *
     * @return string|null
     */
    public static function getCurrentSpanId()
    {
        return self::$currentSpanId;
    }
    
    /**
     * Clear all correlation IDs
     *
     * @return void
     */
    public static function clearCorrelationIds()
    {
        self::$currentExceptionId = null;
        self::$currentTraceId = null;
        self::$currentSpanId = null;
    }
    
    /**
     * Get all correlation IDs as an array
     * 
     * @return array
     */
    public static function getAllCorrelationIds()
    {
        return [
            'exception_id' => self::$currentExceptionId,
            'trace_id' => self::$currentTraceId,
            'span_id' => self::$currentSpanId
        ];
    }
}
