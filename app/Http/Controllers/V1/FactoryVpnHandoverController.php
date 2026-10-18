<?php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Services\FactoryVpnHandover;
use Illuminate\Http\Request;

final class FactoryVpnHandoverController extends Controller
{
    public function __invoke(Request $request, FactoryVpnHandover $service) {
        // The unguessable per-entitlement capability is required, not an APK-wide API key.
        $data = $request->validate([
            'os_subscription_id' => 'required|string|max:36',
            'token' => 'required|string|size:64|regex:/^[a-f0-9]+$/',
        ]);
        try {
            $response = $service->activate($data);
            if ($response->successful() && $response->json('completed') === true) {
                return response()->json(['completed' => true]);
            }
            $status = in_array($response->status(), [403, 404, 409, 422], true) ? $response->status() : 503;
            return response()->json(['completed' => false], $status);
        } catch (\Throwable $e) {
            // Never log request payloads/capability tokens.
            return response()->json(['completed' => false], 503);
        }
    }
}
