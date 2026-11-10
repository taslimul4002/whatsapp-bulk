<?php

namespace Database\Seeders;

use App\Models\Translation;
use App\Support\Locales;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TranslationSeeder extends Seeder
{
    use WithoutModelEvents;

    private const Translations = [
        'common.close_navigation' => ['en' => 'Close navigation', 'ar' => 'إغلاق التنقل'],
        'common.close_menu' => ['en' => 'Close menu', 'ar' => 'إغلاق القائمة'],
        'common.open_menu' => ['en' => 'Open menu', 'ar' => 'فتح القائمة'],
        'common.save' => ['en' => 'Save', 'ar' => 'حفظ'],
        'common.create' => ['en' => 'Create', 'ar' => 'إنشاء'],
        'common.export_json' => ['en' => 'Export JSON', 'ar' => 'تصدير JSON'],
        'common.language' => ['en' => 'Language', 'ar' => 'اللغة'],
        'common.switch_language' => ['en' => 'Switch language', 'ar' => 'تغيير اللغة'],
        'nav.workspace' => ['en' => 'Workspace', 'ar' => 'مساحة العمل'],
        'nav.main_navigation' => ['en' => 'Main navigation', 'ar' => 'التنقل الرئيسي'],
        'nav.dashboard' => ['en' => 'Dashboard', 'ar' => 'لوحة التحكم'],
        'nav.users' => ['en' => 'Users', 'ar' => 'المستخدمون'],
        'nav.workspaces' => ['en' => 'Workspaces', 'ar' => 'مساحات العمل'],
        'nav.profile' => ['en' => 'Profile', 'ar' => 'الملف الشخصي'],
        'nav.settings' => ['en' => 'Settings', 'ar' => 'الإعدادات'],
        'nav.translations' => ['en' => 'Translations', 'ar' => 'الترجمات'],
        'nav.sign_out' => ['en' => 'Sign out', 'ar' => 'تسجيل الخروج'],
        'dashboard.title' => ['en' => 'Dashboard', 'ar' => 'لوحة التحكم'],
        'dashboard.description' => ['en' => 'Your workspace overview.', 'ar' => 'نظرة عامة على مساحة عملك.'],
        'dashboard.getting_started' => ['en' => 'Getting started', 'ar' => 'البدء'],
        'dashboard.foundation_ready' => ['en' => 'Your application foundation is ready. More features will appear here as they are added.', 'ar' => 'أصبح أساس تطبيقك جاهزًا. ستظهر هنا المزيد من الميزات عند إضافتها.'],
        'workspaces.title' => ['en' => 'Workspaces', 'ar' => 'مساحات العمل'],
        'workspaces.description' => ['en' => 'Workspaces available to your account.', 'ar' => 'مساحات العمل المتاحة لحسابك.'],
        'workspaces.create' => ['en' => 'Create workspace', 'ar' => 'إنشاء مساحة عمل'],
        'workspaces.name' => ['en' => 'Workspace name', 'ar' => 'اسم مساحة العمل'],
        'workspaces.available' => ['en' => 'Available workspaces', 'ar' => 'مساحات العمل المتاحة'],
        'workspaces.none' => ['en' => 'No workspaces available.', 'ar' => 'لا توجد مساحات عمل متاحة.'],
        'profile.title' => ['en' => 'Profile', 'ar' => 'الملف الشخصي'],
        'profile.description' => ['en' => 'Manage your account details and password.', 'ar' => 'إدارة تفاصيل حسابك وكلمة المرور.'],
        'profile.account_details' => ['en' => 'Account details', 'ar' => 'تفاصيل الحساب'],
        'profile.name' => ['en' => 'Name', 'ar' => 'الاسم'],
        'profile.email' => ['en' => 'Email', 'ar' => 'البريد الإلكتروني'],
        'profile.save' => ['en' => 'Save profile', 'ar' => 'حفظ الملف الشخصي'],
        'profile.change_password' => ['en' => 'Change password', 'ar' => 'تغيير كلمة المرور'],
        'profile.current_password' => ['en' => 'Current password', 'ar' => 'كلمة المرور الحالية'],
        'profile.new_password' => ['en' => 'New password', 'ar' => 'كلمة المرور الجديدة'],
        'profile.confirm_password' => ['en' => 'Confirm new password', 'ar' => 'تأكيد كلمة المرور الجديدة'],
        'profile.update_password' => ['en' => 'Update password', 'ar' => 'تحديث كلمة المرور'],
        'settings.title' => ['en' => 'Settings', 'ar' => 'الإعدادات'],
        'settings.description' => ['en' => 'Personalize your experience on this device.', 'ar' => 'خصص تجربتك على هذا الجهاز.'],
        'settings.appearance' => ['en' => 'Appearance', 'ar' => 'المظهر'],
        'settings.dark_mode' => ['en' => 'Dark mode', 'ar' => 'الوضع الداكن'],
        'users.title' => ['en' => 'Users', 'ar' => 'المستخدمون'],
        'users.description' => ['en' => 'Create accounts and assign workspace access and permissions.', 'ar' => 'أنشئ الحسابات وعيّن صلاحيات الوصول إلى مساحات العمل والأذونات.'],
        'users.accounts' => ['en' => 'Accounts', 'ar' => 'الحسابات'],
        'users.new' => ['en' => 'New user', 'ar' => 'مستخدم جديد'],
        'users.create' => ['en' => 'Create user', 'ar' => 'إنشاء مستخدم'],
        'users.edit' => ['en' => 'Edit :name', 'ar' => 'تعديل :name'],
        'users.account_type' => ['en' => 'Account type', 'ar' => 'نوع الحساب'],
        'users.user' => ['en' => 'User', 'ar' => 'مستخدم'],
        'users.administrator' => ['en' => 'Global administrator', 'ar' => 'مدير عام'],
        'users.optional_password' => ['en' => 'New password (optional)', 'ar' => 'كلمة مرور جديدة (اختيارية)'],
        'users.password' => ['en' => 'Password', 'ar' => 'كلمة المرور'],
        'users.confirm_password' => ['en' => 'Confirm password', 'ar' => 'تأكيد كلمة المرور'],
        'users.permissions' => ['en' => 'Page permissions', 'ar' => 'أذونات الصفحات'],
        'users.permissions_help' => ['en' => 'Administrators always have full access. Permissions apply to ordinary users.', 'ar' => 'يتمتع المديرون دائمًا بإمكانية الوصول الكامل. تنطبق الأذونات على المستخدمين العاديين.'],
        'users.view_dashboard' => ['en' => 'View dashboard', 'ar' => 'عرض لوحة التحكم'],
        'users.view_workspaces' => ['en' => 'View assigned workspaces', 'ar' => 'عرض مساحات العمل المعيّنة'],
        'users.membership' => ['en' => 'Workspace membership', 'ar' => 'عضوية مساحة العمل'],
        'users.create_workspace_first' => ['en' => 'Create a workspace first.', 'ar' => 'أنشئ مساحة عمل أولًا.'],
        'users.save' => ['en' => 'Save user', 'ar' => 'حفظ المستخدم'],
        'auth.sign_in' => ['en' => 'Sign in', 'ar' => 'تسجيل الدخول'],
        'auth.welcome_back' => ['en' => 'Welcome back', 'ar' => 'مرحبًا بعودتك'],
        'auth.continue' => ['en' => 'Sign in to your account to continue.', 'ar' => 'سجل الدخول إلى حسابك للمتابعة.'],
        'auth.remember_me' => ['en' => 'Remember me', 'ar' => 'تذكرني'],
        'translations.title' => ['en' => 'Translations', 'ar' => 'الترجمات'],
        'translations.description' => ['en' => 'Manage English and Arabic text used throughout the application.', 'ar' => 'إدارة النصوص الإنجليزية والعربية المستخدمة في التطبيق.'],
        'translations.create' => ['en' => 'Add translation', 'ar' => 'إضافة ترجمة'],
        'translations.key' => ['en' => 'Translation key', 'ar' => 'مفتاح الترجمة'],
        'translations.english' => ['en' => 'English', 'ar' => 'الإنجليزية'],
        'translations.arabic' => ['en' => 'Arabic', 'ar' => 'العربية'],
        'translations.save' => ['en' => 'Save translation', 'ar' => 'حفظ الترجمة'],
        'translations.empty' => ['en' => 'No translations have been added yet.', 'ar' => 'لم تتم إضافة أي ترجمات بعد.'],
        'translations.list' => ['en' => 'Translation entries', 'ar' => 'قائمة الترجمات'],
        'translations.export_help' => ['en' => 'Download the active language as a JSON file.', 'ar' => 'نزّل اللغة الحالية كملف JSON.'],
        'flash.workspace_created' => ['en' => 'Workspace created.', 'ar' => 'تم إنشاء مساحة العمل.'],
        'flash.user_created' => ['en' => 'User created.', 'ar' => 'تم إنشاء المستخدم.'],
        'flash.user_updated' => ['en' => 'User updated.', 'ar' => 'تم تحديث المستخدم.'],
        'flash.profile_updated' => ['en' => 'Profile updated.', 'ar' => 'تم تحديث الملف الشخصي.'],
        'flash.password_updated' => ['en' => 'Password updated.', 'ar' => 'تم تحديث كلمة المرور.'],
        'flash.translations_created' => ['en' => 'Translations created.', 'ar' => 'تم إنشاء الترجمات.'],
        'flash.translations_updated' => ['en' => 'Translations updated.', 'ar' => 'تم تحديث الترجمات.'],
        'auth.email' => ['en' => 'Email', 'ar' => 'البريد الإلكتروني'],
        'auth.password' => ['en' => 'Password', 'ar' => 'كلمة المرور'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::Translations as $key => $values) {
            foreach (Locales::Supported as $locale) {
                Translation::query()->firstOrCreate(
                    ['locale' => $locale, 'key' => $key],
                    ['value' => $values[$locale]],
                );
            }
        }
    }
}
