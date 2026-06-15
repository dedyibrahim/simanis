<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDataClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('data_clients', function (Blueprint $table) {
           $table->string("id_client")->primary();

           $table->string("no_identitas")->unique();
           $table->string("nama_client")->nullable();
           $table->string("jenis_client")->nullable();
           $table->text("alamat_client")->nullable();

           $table->string('pembuat_client',15);
           $table->foreign('pembuat_client')->references('id_user')->on('users');

           $table->string("nama_folder");
           $table->string("contact_number")->nullable();
           $table->string("email")->nullable();
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
        Schema::dropIfExists('data_clients');
    }
}
