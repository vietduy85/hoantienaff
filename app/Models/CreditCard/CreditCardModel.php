<?php

namespace App\Models\CreditCard;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base class cho toàn bộ model của module Thẻ tín dụng.
 *
 * Mọi model kế thừa đều ghim connection `creditcard` (database `hoantien_creditcard`)
 * nên không phải khai báo lại ở từng class, và không có đường nào vô tình rơi về
 * database chính `hoantienaff`.
 *
 * KHÔNG có cross-database foreign key: cột `user_id` trong các bảng Thẻ tín dụng là
 * logical reference tới `hoantienaff.users.id`.
 *
 * LƯU Ý VỀ ĐẶT TÊN: KHÔNG được tạo lại class `App\Models\CreditCard` (file
 * `app/Models/CreditCard.php` là skeleton Giai đoạn 1 cũ, đã deprecated).
 * Class và namespace trùng tên về mặt kỹ thuật vẫn autoload được, nhưng rất dễ
 * sinh lỗi "Cannot use relative name" khi trộn `use App\Models\CreditCard;` với
 * `CreditCard\Bank::class` trong cùng file. Mọi code mới phải dùng import đầy đủ.
 */
abstract class CreditCardModel extends Model
{
    /**
     * Connection của module Thẻ tín dụng.
     *
     * Giá trị này được fallback về database chính khi `DB_CREDITCARD_DATABASE`
     * chưa được khai báo — nhờ đó test với sqlite `:memory:` không cần env riêng.
     */
    protected $connection = 'creditcard';

    /**
     * Tên connection của database CHÍNH, dùng cho quan hệ cross-database.
     *
     * CẦN THIẾT cho mọi quan hệ cross-database (vd `UserCard::user()`).
     *
     * Lý do: `Model::newRelatedInstance()` trong Laravel tự copy connection của
     * model cha sang model liên quan KHI model liên quan chưa có connection, và
     * nó luôn làm `new $class` (nên truyền vào một instance sẵn có cũng vô ích).
     * Vì `User` không khai báo `$connection`, nên
     *
     *     $this->belongsTo(User::class, 'user_id')
     *
     * sẽ sinh `select * from users` trên connection `creditcard` và luôn ném
     * "no such table: users". Quan hệ cross-database phải gọi
     * `setConnection($this->mainDatabaseConnectionName())` trên `getRelated()`
     * sau khi dựng relation (điều kiện `where` đã dựng theo tên bảng nên đổi
     * connection ở bước này là an toàn).
     */
    protected function mainDatabaseConnectionName(): string
    {
        $user = new User;

        return $user->getConnectionName() ?: (string) config('database.default');
    }
}
