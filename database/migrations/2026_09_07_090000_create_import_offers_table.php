<?php

use App\Enums\ImportOfferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 128);
            $table->json('payload');
            $table->string('status', 20)->default(ImportOfferStatus::Pending->value);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['import_id', 'status', 'id'], 'import_offers_queue_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_offers');
    }
};
