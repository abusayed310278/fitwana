<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meal_plan_recipe', function (Blueprint $table) {
            $table->dropForeign(['meal_plan_id']);
            $table->dropForeign(['recipe_id']);
        });

        DB::statement('ALTER TABLE meal_plan_recipe DROP PRIMARY KEY');

        Schema::table('meal_plan_recipe', function (Blueprint $table) {
            $table->foreign('meal_plan_id')->references('id')->on('meal_plans')->onDelete('cascade');
            $table->foreign('recipe_id')->references('id')->on('recipes')->onDelete('cascade');

            $table->primary(['meal_plan_id', 'day_of_week', 'meal_type'], 'meal_plan_day_meal_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meal_plan_recipe', function (Blueprint $table) {
            //
        });
    }
};
