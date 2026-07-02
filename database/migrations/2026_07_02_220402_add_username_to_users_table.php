<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique()->after('id');
            $table->boolean('username_locked')->default(false)->after('username');
        });

        User::whereNull('username')->chunk(100, function ($users) {
            foreach ($users as $user) {
                $base = strtolower(explode('@', $user->email)[0]);
                $base = preg_replace('/[^a-z0-9_-]/', '', $base);
                if (strlen($base) < 3) {
                    $base = 'user' . $base;
                }
                $username = $base;
                $counter = 2;
                while (User::where('username', $username)->exists()) {
                    $username = $base . $counter;
                    $counter++;
                }
                $user->username = $username;
                $user->username_locked = true;
                $user->save();
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'username_locked']);
        });
    }
};
