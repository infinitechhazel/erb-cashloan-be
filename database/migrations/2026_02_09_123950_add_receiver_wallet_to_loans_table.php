<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->string('receiver_wallet_name')->nullable()->after('disbursement_date');
            $table->string('receiver_wallet_number')->nullable()->after('receiver_wallet_name');
            $table->string('receiver_wallet_email')->nullable()->after('receiver_wallet_number');
            $table->string('receiver_wallet_proof')->nullable()->after('receiver_wallet_email');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'receiver_wallet_name',
                'receiver_wallet_number',
                'receiver_wallet_email',
                'receiver_wallet_proof',
            ]);
        });
    }
};
