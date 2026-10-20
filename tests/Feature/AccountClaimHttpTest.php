<?php
namespace Tests\Feature;

use App\Helpers\AccountNumberHelper;
use Illuminate\Support\Facades\Http;

final class AccountClaimHttpTest extends \Tests\TestCase
{
    private bool $loginFails = false;
    private bool $protected = true;
    private bool $down = false;
    private bool $forbiddenStatus = false;
    protected function setUp(): void {
        parent::setUp();
        // Deliberately unusable DB: the UI API must not open a database connection.
        config(['database.default'=>'no-database', 'cache.default'=>'array',
            'account_claims.base_url'=>'https://subscriptions.invalid/api/v1/',
            'account_claims.username'=>'test','account_claims.password'=>'test',
            'stellar-user.base_url'=>'https://users.invalid/api/',
            'stellar-subscription.base_url'=>'https://subscriptions.invalid/api/',
            'stellar-user.username_env'=>'TEST_USER','stellar-user.password_env'=>'TEST_PASS',
            'stellar_vpn.username'=>'test','stellar_vpn.password'=>'test',
            'services.stellar_wizard.key'=>'legacy-test']);
        \Illuminate\Support\Env::getRepository()->set('TEST_USER','test');
        \Illuminate\Support\Env::getRepository()->set('TEST_PASS','test');
        Http::preventStrayRequests();
        Http::fake(function($r) {
            $path=parse_url($r->url(),PHP_URL_PATH);
            if(str_contains($path,'/account-claims/')) {
                if($this->down) return Http::response([],503);
                return match(basename($path)) {
                    'ready'=>Http::response(['ready'=>true]),
                    'status'=>$this->forbiddenStatus ? Http::response([],403) : Http::response(['protected'=>$this->protected]),
                    'authorize'=>Http::response(['authorized'=>true]),
                    'legacy-transfer'=>Http::response(['completed'=>true]),
                    'transfer'=>Http::response(['completed'=>true]),
                    default=>throw new \RuntimeException('Unexpected claim request'),
                };
            }
            if($path==='/api/v1/usercontroller/user/123') return Http::response(['user'=>['id'=>123,'email'=>AccountNumberHelper::$keyEmail.'-test']]);
            if($path==='/api/v1/usercontroller/login') return Http::response(['response_code'=>401],401);
            if($path==='/api/v1/usercontroller/createuser' && $this->loginFails) return Http::response(['response_code'=>409],409);
            if($path==='/api/v1/usercontroller/createuser') return Http::response(['response_code'=>200,'user'=>['id'=>456]]);
            if($path==='/api/v1/subscriptioncontroller/user/subscriptions') return Http::response([['id'=>'sub-'.$r['type'],'user_id'=>123]]);
            if($path==='/api/v1/subscriptioncontroller/patch') return Http::response(['response_code'=>200]);
            throw new \RuntimeException('Unexpected upstream request');
        });
    }
    private function payload(): array {
        return ['username'=>'person@example.com','password'=>'test-password','provisional_user_id'=>'123','provisional_claim_token'=>str_repeat('a',64)];
    }
    public function test_v2_transfers_via_subscription_service_without_ui_database(): void {
        $this->postJson('/api/v2/logincontroller/create',$this->payload())->assertOk();
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/account-claims/transfer') && $r['target_user_id']==='456');
        Http::assertNotSent(fn($r)=>str_ends_with($r->url(),'/subscriptioncontroller/patch'));
    }
    public function test_old_client_without_proof_keeps_legacy_flow(): void {
        $this->protected=false;
        $data=$this->payload(); unset($data['provisional_claim_token']);
        $this->withHeader('X-Stellar-Wizard-Key','legacy-test')->postJson('/api/v1/logincontroller/create',$data)->assertOk();
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/account-claims/legacy-transfer'));
        Http::assertNotSent(fn($r)=>str_ends_with($r->url(),'/subscriptioncontroller/patch'));
    }
    public function test_v1_cannot_downgrade_new_activation(): void {
        $this->withHeader('X-Stellar-Wizard-Key','legacy-test')->postJson('/api/v1/logincontroller/create',$this->payload())->assertForbidden();
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'usercontroller'));
    }
    public function test_service_failure_never_allows_legacy_transfer(): void {
        $this->down=true;
        $this->withHeader('X-Stellar-Wizard-Key','legacy-test')->postJson('/api/v1/logincontroller/create',$this->payload())->assertStatus(503);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'usercontroller'));
    }
    public function test_v2_activation_checks_upstream_before_creating_accounts(): void {
        $this->down=true;
        $this->postJson('/api/v2/activationcontroller/activate',['subscription_id'=>'test'])->assertStatus(503);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'usercontroller'));
    }
    public function test_v2_missing_proof_sends_nothing(): void {
        $data=$this->payload();unset($data['provisional_claim_token']);
        $this->postJson('/api/v2/logincontroller/create',$data)->assertStatus(422);
        Http::assertNothingSent();
    }
    public function test_forbidden_status_is_not_treated_as_unprotected(): void {
        $this->forbiddenStatus=true;
        $this->withHeader('X-Stellar-Wizard-Key','legacy-test')->postJson('/api/v1/logincontroller/create',$this->payload())->assertStatus(503);
    }
    public function test_failed_destination_can_be_corrected_without_transfer(): void {
        $this->loginFails=true;
        $this->postJson('/api/v2/logincontroller/create',$this->payload())->assertStatus(400);
        Http::assertNotSent(fn($r)=>str_ends_with($r->url(),'/account-claims/transfer'));
        $this->loginFails=false;
        $data=$this->payload();$data['username']='corrected@example.com';
        $this->postJson('/api/v2/logincontroller/create',$data)->assertOk();
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/account-claims/transfer') && $r['username']==='corrected@example.com');
    }
}
