<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Cors
{
    public function handle(Request $request, Closure $next)
    {
        $origin = (string) env('CORS_ORIGIN', 'http://localhost:5173');
        if ($request->isMethod('OPTIONS')) {
            return response('', 204)->withHeaders([
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            ]);
        }
        $response = $next($request);
        return $response->withHeaders([
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Accept',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        ]);
    }
}
