<?php
namespace Tests\Feature;

use App\Helpers\AccountNumberHelper;
use App\Http\Controllers\V1\LoginController;
use App\Services\ProvisionalAccountClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use StellarSecurity\SubscriptionLaravel\SubscriptionService;
use StellarSecurity\UserApiLaravel\UserService;

final class AccountClaimHttpTest extends \Tests\TestCase
{
    private string $proof;
    private array $patches = [];
    private bool $failPatch = false;
    private bool $wrongOwner = false;
    private bool $existing = false;
    private array $created = [];
    protected function setUp(): void {
        parent::setUp();
        (require database_path('migrations/2026_10_20_000001_create_provisional_account_claims.php'))->up();
        config(['stellar-user.base_url'=>'https://users.invalid/api/', 'stellar-subscription.base_url'=>'https://subscriptions.invalid/api/']);
        config(['stellar-user.username_env'=>'STELLAR_TEST_USER','stellar-user.password_env'=>'STELLAR_TEST_PASSWORD']);
        \Illuminate\Support\Env::getRepository()->set('STELLAR_TEST_USER','test-only');
        \Illuminate\Support\Env::getRepository()->set('STELLAR_TEST_PASSWORD','test-only');
        $this->proof=app(ProvisionalAccountClaim::class)->issue('123');
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url=parse_url($request->url(), PHP_URL_PATH);
            if ($url==='/api/v1/usercontroller/user/123') return Http::response(['user'=>['id'=>123,'email'=>AccountNumberHelper::$keyEmail.'-test']]);
            if ($url==='/api/v1/usercontroller/createuser') {
                $this->created=$request->data();
                return Http::response($this->existing?['response_code'=>409]:['response_code'=>200,'user'=>['id'=>456]]);
            }
            if ($url==='/api/v1/usercontroller/login') return Http::response(['response_code'=>200,'user'=>['id'=>456]]);
            if ($url==='/api/v1/subscriptioncontroller/user/subscriptions') return Http::response([['id'=>$request['type'],'user_id'=>$this->wrongOwner?999:123]]);
            if ($url==='/api/v1/subscriptioncontroller/patch') {
                $this->patches[]=$request->data();
                return Http::response(['response_code'=>$this->failPatch?500:200]);
            }
            throw new \RuntimeException('Unexpected HTTP request in isolated test');
        });
    }
    protected function tearDown(): void { Schema::dropIfExists('provisional_account_claims'); parent::tearDown(); }
    private function runClaim(array $overrides=[]): \Illuminate\Http\JsonResponse {
        $controller=new LoginController(new UserService(),new SubscriptionService());
        return $controller->create(Request::create('/auth/create','POST',array_merge([
            'username'=>'person@example.com','password'=>'test-password','provisional_user_id'=>'123',
            'provisional_claim_token'=>$this->proof,'token'=>'untrusted-token','role'=>'admin',
        ],$overrides)));
    }
    public function test_real_services_transfer_once_without_forwarding_untrusted_fields(): void {
        $this->assertSame(200,$this->runClaim()->status());
        $this->assertCount(2,$this->patches);
        foreach($this->patches as $patch) $this->assertSame(456,$patch['user_id']);
        $this->assertSame(['username','password','token'],array_keys($this->created));
        $this->assertNotSame('untrusted-token',$this->created['token']);
        $this->assertSame(403,$this->runClaim()->status());
        $this->assertCount(2,$this->patches);
    }
    public function test_wrong_proof_sends_no_http_request(): void {
        $this->assertSame(403,$this->runClaim(['provisional_claim_token'=>str_repeat('0',64)])->status());
        Http::assertNothingSent();
    }
    public function test_partial_failure_can_resume_only_same_destination(): void {
        $this->failPatch=true;
        $this->assertSame(503,$this->runClaim()->status());
        $this->assertSame(403,$this->runClaim(['username'=>'other@example.com'])->status());
        $this->failPatch=false; $this->existing=true;
        $this->assertSame(200,$this->runClaim()->status());
    }
    public function test_wrong_subscription_owner_is_never_patched(): void {
        $this->wrongOwner=true;
        $this->assertSame(503,$this->runClaim()->status());
        $this->assertSame([],$this->patches);
    }
}
