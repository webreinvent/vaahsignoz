<?php

namespace WebReinvent\VaahSignoz\Helpers;

class InstrumentationHelper
{
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
}
