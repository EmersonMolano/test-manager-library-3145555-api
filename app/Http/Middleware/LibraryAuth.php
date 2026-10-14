<?php

namespace App\Http\Middleware;

use App\Models\LibraryUser;
use Closure;
use Firebase\JWT\Key;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;

class LibraryAuth
{
    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return response()->json(['code' => 'INVALID_CREDENTIALS', 'message' => 'Las credenciales no son válidas.', 'details' => []], 401);
        }

        try {
            $payload = JWT::decode(substr($header, 7), new Key((string) config('app.jwt_secret'), 'HS256'));
            $user = LibraryUser::find((string) $payload->sub);
            if (! $user || $user->role !== (string) $payload->role) {
                throw new \RuntimeException('invalid subject');
            }
            $request->attributes->set('library_user', $user);
        } catch (\Throwable) {
            return response()->json(['code' => 'INVALID_CREDENTIALS', 'message' => 'Las credenciales no son válidas.', 'details' => []], 401);
        }

        return $next($request);
    }
}
