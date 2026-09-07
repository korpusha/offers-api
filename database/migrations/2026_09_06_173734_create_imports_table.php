<?php

use App\Enums\ImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('external_import_id', 128);
            $table->timestamp('sent_at');
            $table->string('status', 20)->default(ImportStatus::Pending->value);
            $table->unsignedInteger('total_offers')->default(0);
            $table->unsignedInteger('processed_offers')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'external_import_id']);
            $table->index(['supplier_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
    }
};
