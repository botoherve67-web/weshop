<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('moyen_paiement')->nullable()->change();
            $table->string('alphapay_session_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['alphapay_session_id']);
            $table->dropColumn('alphapay_session_id');
            $table->enum('moyen_paiement', ['flooz', 'tmoney'])->nullable()->change();
        });
    }
};
