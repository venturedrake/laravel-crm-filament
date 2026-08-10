<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('laravel-crm.db_table_prefix');

        if (Schema::hasTable($prefix . 'invoice_payments')) {
            return;
        }

        Schema::create($prefix . 'invoice_payments', function (Blueprint $table) use ($prefix) {
            $table->bigIncrements('id');
            $table->string('external_id')->nullable();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->foreign('invoice_id')->references('id')->on($prefix . 'invoices');
            $table->integer('amount')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->unsignedBigInteger('user_created_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('laravel-crm.db_table_prefix') . 'invoice_payments');
    }
};
