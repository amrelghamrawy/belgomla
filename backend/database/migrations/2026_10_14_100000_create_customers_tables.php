<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 20); // E.164 (BR-CU04)
            $table->string('password')->nullable(); // null until activated (BR-CU08)
            $table->rememberToken();
            $table->string('status', 30);
            $table->timestamp('phone_verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->timestamp('blocked_at')->nullable();
            $table->string('blocked_reason', 300)->nullable();
            $table->string('status_before_block', 30)->nullable(); // restored on unblock (BR-CU05)
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_known CHECK (status IN ('pending_verification', 'invited', 'pending_approval', 'active', 'blocked'))");

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('label', 50);
            $table->string('governorate', 50);
            $table->string('city', 80);
            $table->string('address_line', 300);
            $table->string('landmark', 200)->nullable();
            $table->string('phone', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        // BR-CU03: at most one default among a customer's active addresses.
        DB::statement("CREATE UNIQUE INDEX customer_addresses_one_default ON customer_addresses (customer_id) WHERE is_default AND status = 'active'");

        Schema::create('customer_otps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('phone', 20);
            $table->string('purpose', 20);
            $table->string('code_hash'); // never the plain code (BR-CU07)
            $table->string('channel', 20);
            $table->timestamp('expires_at');
            $table->smallInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'phone', 'purpose', 'id']);
        });

        Schema::create('customer_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->smallInteger('sent_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete(); // BR-CU06
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained('skus')->cascadeOnDelete();
            $table->integer('quantity'); // selling units; prices are resolved on read (BR-PR06)
            $table->timestamps();

            $table->unique(['cart_id', 'sku_id']);
        });

        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_positive CHECK (quantity >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('customer_invitations');
        Schema::dropIfExists('customer_otps');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customers');
    }
};
