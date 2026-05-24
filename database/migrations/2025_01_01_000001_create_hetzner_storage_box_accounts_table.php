<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hetzner_storage_box_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->unique();
            $table->unsignedBigInteger('hetzner_box_id')->unique();
            $table->string('username');          // e.g. u123456
            $table->string('hostname');          // e.g. u123456.your-storagebox.de
            $table->string('box_type');          // e.g. bx11
            $table->string('location');          // e.g. fsn1
            $table->string('status')->default('active'); // active | suspended | terminated
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();

            // No password column — passwords are one-time use and never persisted.

            $table->foreign('service_id')
                ->references('id')
                ->on('services')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hetzner_storage_box_accounts');
    }
};
