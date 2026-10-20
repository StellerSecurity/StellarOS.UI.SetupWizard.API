<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('provisional_account_claims', function (Blueprint $table) {
            $table->string('provisional_user_id', 128)->primary();
            $table->string('token_hash', 64);
            $table->string('target_username', 254)->nullable();
            $table->string('target_user_id', 128)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('provisional_account_claims'); }
};
