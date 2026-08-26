<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        // کاربران اولیه — رمز از متغیر محیطی، وگرنه پیش‌فرض توسعه.
        //
        // ?: عمدی است نه ??: خطِ خالیِ «SEED_PASSWORD=» در .env مقدار «» برمی‌گرداند
        // نه null، پس با ?? هر چهار حساب رمز خالی می‌گرفتند — و چون فرم ورود
        // رمز را اجباری می‌داند، هیچ‌کدام دیگر نمی‌توانستند وارد شوند. نصبِ تازه
        // بی‌سروصدا قفل می‌شد.
        $password = env('SEED_PASSWORD') ?: 'hana@1405';

        // یک حساب برای هر نقش. حساب متقاضی هم همین‌جا ساخته می‌شود چون ثبت‌نام
        // عمومی وجود ندارد و حساب متقاضی را مدیر سامانه می‌سازد.
        //
        // کد ملی از تسک ۷۴۰ **نام کاربری ورود** است، پس ثابت و معلوم می‌ماند؛
        // همین چهار مقدار در مهاجرت add_national_id_to_users_table هم هست تا
        // دیتابیس موجود بعد از مهاجرت قفل نشود. هر چهار کد مصنوعی‌اند و فقط
        // رقم کنترلشان درست است — هیچ کد ملی واقعی وارد پروژه نمی‌شود (قانون ۱۲۹).
        $users = [
            ['مدیر سامانه', 'admin@hana.local', '0011111119', 'admin'],
            ['کارشناس بررسی', 'expert@hana.local', '0022222227', 'expert'],
            ['کارشناس داده', 'data@hana.local', '0033333335', 'data'],
            ['متقاضی نمونه', 'applicant@hana.local', '0044444443', 'applicant'],
        ];

        foreach ($users as [$name, $email, $nationalId, $role]) {
            $user = User::firstOrNew(['email' => $email]);

            // national_id و role و is_active عمداً fillable نیستند.
            $user->name = $name;
            $user->national_id = $nationalId;
            $user->role = $role;
            $user->is_active = true;
            $user->password = Hash::make($password);
            $user->save();
        }
    }
}
