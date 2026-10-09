<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bảng nối `credit_card_report_cards` — thẻ nào nằm trong báo cáo nào.
 *
 * ---------------------------------------------------------------------------
 * VÌ SAO TÁCH BẢNG, KHÔNG NHÉT CHUỖI ID
 * ---------------------------------------------------------------------------
 * Một cột JSON / chuỗi "1,2,3" không kiểm chứng được: không biết thẻ có thật
 * không, có thuộc đúng user không, và thẻ bị xoá thì id mồ côi nằm lại trong
 * chuỗi. Bảng nối cho phép:
 *   - `UNIQUE (report_id, user_card_id)` chặn thẻ trùng trong cùng báo cáo;
 *   - `cascadeOnDelete` trên cả hai phía: xoá báo cáo thì dòng nối theo đi,
 *     xoá cứng thẻ thì báo cáo tự bỏ thẻ đó ra — không để lại rác.
 *
 * Hai FK đều CÙNG connection `creditcard` nên là FK nội-bộ hợp lệ, KHÔNG phải
 * cross-database. `user_id` của báo cáo vẫn là logical reference ở bảng cha.
 */
return new class extends Migration
{
    protected $connection = 'creditcard';

    public function up(): void
    {
        Schema::connection($this->connection)->create('credit_card_report_cards', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_id')
                ->constrained('credit_card_reports')
                ->cascadeOnDelete();

            $table->foreignId('user_card_id')
                ->constrained('credit_card_user_cards')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['report_id', 'user_card_id'], 'credit_card_report_cards_unique');
            $table->index('user_card_id', 'credit_card_report_cards_card_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('credit_card_report_cards');
    }
};
