<?php

namespace App\Http\Controllers\V1;

use App\Helpers\AccountNumberHelper;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use StellarSecurity\SubscriptionLaravel\Enums\SubscriptionType;
use StellarSecurity\SubscriptionLaravel\SubscriptionService;
use StellarSecurity\UserApiLaravel\UserService;

class LoginController extends Controller
{

    private string $token = "StellarOS.UI.SetupWizard.API";

    public function __construct(public UserService $userService, public SubscriptionService $subscriptionService)
    {

    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function auth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $response = $this->userService->auth([
            'username' => $data['username'],
            'password' => $data['password'],
            'token'    => $this->token,
        ]);

        $body = $response->object();

        // If the upstream API fails (non-2xx), we just proxy the response_code/message
        if ($response->failed()) {
            return response()->json($body, $body->response_code ?? 400);
        }

        return response()->json($body);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:8', 'max:1024'],
            'provisional_user_id' => ['required', 'string', 'max:128'],
            'provisional_claim_token' => ['required', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);
        $id = $data['provisional_user_id'];
        $claims = app(\App\Services\ProvisionalAccountClaim::class);
        // No shared client header, user ID or caller-provided upstream token is proof.
        $lock = \Illuminate\Support\Facades\Cache::lock('account-claim:'.hash('sha256', $id), 300);
        if (!$lock->get()) return response()->json(['response_code' => 409], 409);
        try {
            if (!$claims->authorize($id, $data['provisional_claim_token'], $data['username'])) {
                return response()->json(['response_code' => 403, 'response_message' => 'Activation proof required.'], 403);
            }
            $userResponse = $this->userService->user($id);
            $user = $userResponse->object();
            if (!$this->upstreamSucceeded($userResponse) || !isset($user->user->id)
                || (string) $user->user->id !== $id
                || !str_starts_with($user->user->email ?? '', AccountNumberHelper::$keyEmail . '-')) {
                return response()->json(['response_code' => 403], 403);
            }
            $credentials = ['username' => $data['username'], 'password' => $data['password'], 'token' => $this->token];
            $result = $this->userService->create($credentials);
            // A previous attempt may have created the destination before a transfer
            // failed. Authentication is mandatory before resuming that same account.
            if (!$this->upstreamSucceeded($result) || empty($result->object()?->user?->id)) {
                $result = $this->userService->auth($credentials);
            }
            $auth = $result->object();
            if (!$this->upstreamSucceeded($result) || empty($auth?->user?->id)) {
                return response()->json(['response_code' => 400, 'response_message' => 'Account creation or authentication failed.'], 400);
            }
            if (!$claims->bindTarget($id, (string) $auth->user->id)) {
                return response()->json(['response_code' => 403], 403);
            }
            foreach ([SubscriptionType::VPN->value, SubscriptionType::ANTIVIRUS->value] as $type) {
                $response = $this->subscriptionService->findUserSubscriptions($id, $type);
                if (!$this->upstreamSucceeded($response)) throw new \RuntimeException('Subscription lookup failed');
                $subscriptions = $response->object();
                if (!is_array($subscriptions)) throw new \RuntimeException('Unexpected subscription response');
                foreach ($subscriptions as $subscription) {
                    if (!is_object($subscription) || !isset($subscription->user_id)
                        || (string) $subscription->user_id !== $id) {
                        throw new \RuntimeException('Unexpected subscription owner');
                    }
                    $subscription->user_id = $auth->user->id;
                    if (!$this->upstreamSucceeded($this->subscriptionService->patch((array) $subscription))) {
                        throw new \RuntimeException('Subscription transfer failed');
                    }
                }
            }
            $claims->complete($id);
            return response()->json($auth);
        } catch (\Throwable $error) {
            // Never log request bodies, credentials or capabilities.
            return response()->json(['response_code' => 503, 'response_message' => 'Please retry with the same account.'], 503);
        } finally {
            $lock->release();
        }
    }

    private function upstreamSucceeded(\Illuminate\Http\Client\Response $response): bool
    {
        if (!$response->successful()) return false;
        $body = $response->object();
        // Several Stellar services report application failures inside HTTP 200.
        if (is_object($body) && isset($body->response_code)) {
            return is_numeric($body->response_code)
                && (int) $body->response_code >= 200 && (int) $body->response_code < 300;
        }
        return true;
    }

    public function sendresetpasswordlink(Request $request): JsonResponse
    {

        $email = $request->input('email');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        $confirmation_code = Str::password(6, false, true, false, false);
        $resetpassword = $this->userService->sendresetpasswordlink($email, $confirmation_code)->object();

        if($resetpassword->response_code !== 200) {
            return response()->json(['response_code' => $resetpassword->response_code, 'response_message' => $resetpassword->response_message]);
        }

        return response()->json(['response_code' => 200, 'response_message' => 'OK. Reset password link sent to your email.']);
    }


    public function verifypasswordcode(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $confirmation_code = $request->input('confirmation_code');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        if($confirmation_code === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No confirmation code was provided']);
        }

        $resp = $this->userService
            ->checkresetpasswordconfirmationcode($email, $confirmation_code);

        $verify = $resp->object();

        return response()->json($verify);

    }

    public function resetpasswordupdate(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $confirmation_code = $request->input('confirmation_code');
        $new_password = $request->input('new_password');

        if($email === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No email was provided']);
        }

        if($new_password === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'New password not was provided']);
        }

        if($confirmation_code === null) {
            return response()->json(['response_code' => 400, 'response_message' => 'No confirmation code was provided']);
        }

        $verifyandupdate = $this->userService->verifyresetpasswordconfirmationcode($email, $confirmation_code, $new_password)->object();

        return response()->json($verifyandupdate);

    }


}
