<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates dedicated governance_locks table per RBAC-DEC-06.
     */
    public function up(): void
    {
        Schema::create('governance_locks', function (Blueprint $table) {
            $table->string('lock_name', 64)->primary();
            $table->string('owner', 255)->nullable();
            $table->timestamp('acquired_at')->nullable();
            $table->timestamps();
        });

        // Seed default lock row for Super Admin governance
        DB::table('governance_locks')->insert([
            'lock_name'   => 'super_admin_governance',
            'owner'       => null,
            'acquired_at' => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('governance_locks');
    }
};
