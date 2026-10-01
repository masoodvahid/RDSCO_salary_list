<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Comments on a project's whole monthly list (row notes stay in `notes`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            // Printed at the bottom of the list only when someone ticks it.
            $table->boolean('in_print')->default(false);
            $table->timestamps();

            $table->index(['sheet_project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_comments');
    }
};
