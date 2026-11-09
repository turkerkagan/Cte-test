<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->string('login_eyebrow')->default('Kurum içi yönetim sistemi')->after('accent_color');
            $table->string('login_heading')->default('Bilgiye hızlı, yetkiye göre erişim.')->after('login_eyebrow');
            $table->text('login_description')->nullable()->after('login_heading');
            $table->string('login_security_note')->default('Veriler kurum sunucusundan dışarı çıkmaz.')->after('login_description');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table): void {
            $table->dropColumn([
                'login_eyebrow',
                'login_heading',
                'login_description',
                'login_security_note',
            ]);
        });
    }
};
