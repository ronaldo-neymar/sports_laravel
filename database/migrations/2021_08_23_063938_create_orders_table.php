<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->string('images');
            $table->float('price');
            $table->float('quantity');
            $table->string('fullname');
            $table->string('email');
            $table->double('phone_number');
            $table->integer('province_id');
            $table->integer('districk_id');
            $table->integer('ward_id');
            $table->string('address');
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('orders');
    }
}
