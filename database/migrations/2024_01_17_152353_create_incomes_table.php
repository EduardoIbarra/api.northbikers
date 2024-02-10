<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIncomesTable extends Migration
{
    public function up()
    {
        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->string('customer');
            $table->string('email');
            $table->decimal('amount', 10, 2);
            $table->decimal('fee', 10, 2);
            $table->decimal('total', 10, 2);
            $table->decimal('to_be_transferred', 10, 2);
            $table->enum('status', ['unpaid', 'paid']);
            $table->string('stripe_checkout_id')->nullable();
            $table->string('stripe_webhook_email_notification')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('incomes');
    }
}
