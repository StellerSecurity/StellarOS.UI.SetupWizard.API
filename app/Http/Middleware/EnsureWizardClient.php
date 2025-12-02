<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWizardClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.stellar_wizard.key');
        $provided = $request->header('X-Stellar-Wizard-Key');

        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            return response()->json([
                'response_code'    => 401,
                'response_message' => 'Unauthorized wizard client',
            ], 401);
        }

        return $next($request);
    }
}
