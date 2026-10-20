<?php
namespace Tests\Feature;

use App\Services\ProvisionalAccountClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AccountClaimTest extends Tests\TestCase {
    private ProvisionalAccountClaim $claims;
    protected function setUp(): void {
        parent::setUp();
        $migration = require database_path('migrations/2026_10_20_000001_create_provisional_account_claims.php');
        $migration->up();
        $this->claims = new ProvisionalAccountClaim();
    }
    protected function tearDown(): void {
        Schema::dropIfExists('provisional_account_claims');
        parent::tearDown();
    }
    public function test_secret_is_random_and_only_hash_is_stored(): void {
        $a=$this->claims->issue('a'); $b=$this->claims->issue('b');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$a);
        $this->assertNotSame($a,$b);
        $this->assertSame(hash('sha256',$a),DB::table('provisional_account_claims')->where('provisional_user_id','a')->value('token_hash'));
    }
    public function test_missing_wrong_cross_user_and_expired_proofs_fail(): void {
        $token=$this->claims->issue('a'); $this->claims->issue('b');
        foreach (['',str_repeat('0',64),$token.'x'] as $bad) $this->assertFalse($this->claims->authorize('a',$bad,'x@example.com'));
        $this->assertFalse($this->claims->authorize('b',$token,'x@example.com'));
        $this->assertFalse($this->claims->authorize('unknown',$token,'x@example.com'));
        DB::table('provisional_account_claims')->where('provisional_user_id','a')->update(['expires_at'=>now()]);
        $this->assertFalse($this->claims->authorize('a',$token,'x@example.com'));
    }
    public function test_retry_cannot_redirect_remaining_subscriptions(): void {
        $token=$this->claims->issue('a');
        $this->assertTrue($this->claims->authorize('a',$token,'x@example.com'));
        $this->assertTrue($this->claims->authorize('a',$token,'x@example.com'));
        $this->assertFalse($this->claims->authorize('a',$token,'y@example.com'));
        $this->assertTrue($this->claims->bindTarget('a','target1'));
        $this->assertTrue($this->claims->bindTarget('a','target1'));
        $this->assertFalse($this->claims->bindTarget('a','target2'));
        $this->claims->complete('a');
        $this->assertFalse($this->claims->authorize('a',$token,'x@example.com'));
        $this->assertFalse($this->claims->bindTarget('a','target1'));
    }
    public function test_target_cannot_bind_before_authorization(): void {
        $this->claims->issue('a');
        $this->assertFalse($this->claims->bindTarget('a','target'));
    }
}
