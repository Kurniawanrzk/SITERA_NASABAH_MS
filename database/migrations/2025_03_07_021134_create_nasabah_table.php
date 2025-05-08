<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nasabah', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');          
            $table->string('nik')->unique()->nullable();
            $table->string('nama')->nullable();
            $table->text('alamat')->nullable();
            $table->string('nomor_wa')->nullable();
            $table->string('nomor_rekening')->nullable();
            $table->string("nama_bank", 50);
            $table->string('nama_pemilik_rekening')->nullable();
            $table->string('jenis_rekening')->nullable();
            $table->enum('reward_level', ['bronze', 'silver', 'gold'])->default('bronze');
            $table->decimal('total_sampah', 10, 2)->default(0);
            $table->decimal('saldo', 10, 2)->default(0);
            $table->uuid('bsu_id');
            $table->integer("poin");
            $table->integer("total_poin");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nasabah');
    }
};
