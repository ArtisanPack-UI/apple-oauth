<?php

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'apple_configurations', function ( Blueprint $table ): void {
            $table->id();

            // Fixed sentinel that constrains the table to a single credential
            // row so DatabaseDriver::save() can atomically upsert without a
            // read-then-write race under concurrent writers.
            $table->string( 'singleton' )->default( 'default' );

            $table->string( 'client_id' )->nullable();
            $table->string( 'team_id' )->nullable();
            $table->string( 'key_id' )->nullable();
            $table->text( 'private_key' )->nullable();
            $table->string( 'redirect_uri' )->nullable();
            $table->text( 'client_secret' )->nullable();
            $table->timestamps();

            $table->unique( 'singleton' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'apple_configurations' );
    }
};
