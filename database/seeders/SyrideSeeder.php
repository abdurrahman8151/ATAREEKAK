<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\RideStatus;
use App\Enums\StaffRole;
use App\Models\Employee;
use App\Models\Profile;
use App\Models\User;
use App\Models\UserScore;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SyrideSeeder extends Seeder
{
    private const VERIFIED_DRIVERS    = 250;
    private const VERIFIED_PASSENGERS = 400;
    private const PENDING_USERS       = 200;
    private const UNVERIFIED_USERS    = 150;
    private const ADMINS              = 3;
    private const SUPPORT_AGENTS      = 5;
    private const CONVERSATIONS       = 200;

    private ?Wallet   $sycashWallet   = null;
    private ?Employee $sycashEmployee = null;
    private string    $placeholderDoc = '';

    private array $cities = [
        'دمشق'      => ['lat' => 33.5138, 'lng' => 36.3128],
        'حلب'       => ['lat' => 36.2021, 'lng' => 37.1343],
        'حمص'       => ['lat' => 34.7324, 'lng' => 36.7137],
        'حماة'      => ['lat' => 35.1318, 'lng' => 36.7512],
        'اللاذقية'  => ['lat' => 35.5317, 'lng' => 35.7917],
        'طرطوس'     => ['lat' => 34.8934, 'lng' => 35.8872],
        'درعا'      => ['lat' => 32.6223, 'lng' => 36.1001],
        'ريف دمشق'  => ['lat' => 33.5102, 'lng' => 36.2910],
        'إدلب'      => ['lat' => 35.9306, 'lng' => 36.6340],
        'السويداء'  => ['lat' => 32.7069, 'lng' => 36.5660],
        'القنيطرة'  => ['lat' => 33.1270, 'lng' => 35.8245],
        'دير الزور' => ['lat' => 35.3365, 'lng' => 40.1411],
        'الرقة'     => ['lat' => 35.9508, 'lng' => 39.0173],
        'الحسكة'    => ['lat' => 36.4949, 'lng' => 40.7400],
    ];

    private array $driverFirstNames = [
        'أحمد','محمد','علي','حسن','يوسف','عمر','خالد','طارق',
        'سامر','نادر','باسل','وائل','رامي','فادي','زياد','مهند',
        'كمال','جمال','نبيل','حاتم','أيمن','ماهر','عصام','رفيق',
        'ثائر','عزيز','لؤي','منذر','بسام','غياث',
    ];
    private array $passengerFirstNames = [
        'سارة','مريم','هلا','ريم','دانا','لينا','نور','رنا',
        'ميساء','شيرين','أميرة','لمى','سنا','رولا','هناء',
        'عمر','حسام','ماجد','وليد','سعيد','فراس','صالح','نزار',
        'حيدر','إياد','منير','راشد','أسامة','صخر','ديب',
    ];
    private array $lastNames = [
        'الأحمد','العلي','الحسن','الخطيب','العمر','الزهراوي',
        'الصالح','الرشيد','الكردي','الحلبي','الدمشقي','الحمصي',
        'الطرابلسي','العاصي','البيطار','الجندي','القاسم','الشيخ',
        'الموسى','اليوسف','الزعبي','الحريري','السيد','الحمدان',
    ];
    private array $carTypes = [
        'كيا سبورتاج','هيونداي توسان','تويوتا كورولا','هوندا سيفيك',
        'نيسان صني','ميتسوبيشي لانسر','شيفروليه سبارك','فولكس واجن جولف',
        'سوزوكي سويفت','رينو لوغان','تويوتا كامري','هيونداي إلنترا',
    ];
    private array $carColors = [
        'أبيض','أسود','فضي','رمادي','أزرق','أحمر','بيج','بني','أخضر غامق',
    ];

    // FIX: complaint types and statuses now match the ComplaintType / ComplaintStatus enums
    private array $complaintTypes = [
        'driver_behavior', 'passenger_behavior', 'financial_issue',
        'technical_issue', 'other',
    ];
    private array $complaintTitles = [
        'driver_behavior'    => 'شكوى على سلوك السائق',
        'passenger_behavior' => 'شكوى على سلوك الراكب',
        'financial_issue'    => 'مشكلة في عملية الدفع',
        'technical_issue'    => 'خلل تقني في التطبيق',
        'other'              => 'شكوى عامة',
    ];
    private array $complaintDescriptions = [
        'driver_behavior'    => 'السائق لم يكن محترماً خلال الرحلة وتصرف بطريقة غير لائقة تجاه الركاب.',
        'passenger_behavior' => 'الراكب تصرف بشكل غير لائق وأزعج بقية المسافرين طوال الرحلة.',
        'financial_issue'    => 'تم خصم المبلغ من محفظتي الإلكترونية لكن الرحلة لم تُسجَّل كمكتملة.',
        'technical_issue'    => 'التطبيق يتوقف عن العمل بشكل متكرر عند محاولة تأكيد الحجز.',
        'other'              => 'لديّ استفسار عام أود مناقشته مع فريق الدعم الفني.',
    ];
    // FIX: statuses now match ComplaintStatus enum values (pending / in_review / escalated / resolved / closed)
    private array $complaintStatuses = ['pending', 'in_review', 'resolved', 'closed'];

    private array $messageTemplates = [
        'مرحباً، هل الرحلة لا تزال متاحة؟',
        'نعم، الرحلة متاحة. هل تريد الحجز؟',
        'ما هو الموعد الدقيق للانطلاق؟',
        'سننطلق في الساعة التاسعة صباحاً تقريباً.',
        'هل يوجد مكان للحقائب الكبيرة؟',
        'نعم، هناك مساحة كافية في الصندوق الخلفي.',
        'شكراً جزيلاً، سأقوم بالحجز الآن.',
        'أهلاً وسهلاً، في خدمتك دائماً.',
        'كيف يمكنني الوصول إلى نقطة الانطلاق؟',
        'سأشارك موقعي الدقيق قبل الانطلاق بنصف ساعة.',
        'هل الرحلة مباشرة بدون توقف؟',
        'نعم، مباشرة من المدينة إلى الوجهة دون توقف.',
        'تم تأكيد حجزك بنجاح، أراك قريباً.',
        'شكراً على الخدمة الممتازة.',
        'كانت رحلة رائعة، سأتعامل معك مستقبلاً.',
        'هل يمكنك الانتظار لمدة خمس دقائق عند النقطة؟',
        'بالتأكيد، لا مشكلة على الإطلاق.',
        'تم استلام الدفع الإلكتروني بنجاح.',
        'هل السيارة مكيفة؟',
        'نعم، المكيف يعمل بشكل ممتاز طوال الرحلة.',
    ];

    private array $profileCommentTemplates = [
        'سائق محترف وملتزم بالمواعيد دائماً، أنصح به.',
        'تجربة ممتازة، أنصح بالتعامل معه بشدة.',
        'السيارة نظيفة ومرتبة والسائق مؤدب جداً.',
        'رحلة مريحة وبسعر معقول، شكراً جزيلاً.',
        'سائق هادئ ومحترف، الرحلة كانت آمنة تماماً.',
        'كان متعاوناً جداً خلال الرحلة وساعدني بحقائبي.',
        'الرحلة كانت آمنة وسريعة، سأتعامل معه مجدداً.',
        'أوصي بهذا السائق لجميع المسافرين دون تردد.',
        'لم أتوقع هذا المستوى العالي من الاحترافية.',
        'سيارة نظيفة وسائق أمين ومحترم، ممتاز.',
    ];

    private array $notificationTemplates = [
        ['title' => 'مرحباً بك في سيرايد',     'message' => 'تم تفعيل حسابك بنجاح. ابدأ رحلتك الأولى الآن!',                             'type' => 'general'],
        ['title' => 'تم قبول طلب التحقق',       'message' => 'تهانينا! تم قبول وثائقك والتحقق من هويتك بنجاح.',                           'type' => 'general'],
        ['title' => 'تم تأكيد حجزك',            'message' => 'تم تأكيد حجزك بنجاح. نتمنى لك رحلة آمنة وممتعة.',                           'type' => 'general'],
        ['title' => 'رحلة جديدة مضافة',          'message' => 'تمت إضافة رحلة جديدة تتطابق مع تفضيلاتك. تحقق الآن!',                      'type' => 'general'],
        ['title' => 'عرض خاص لك',               'message' => 'احصل على خصم 10% على رحلتك القادمة باستخدام رمز SYRIDE10.',                  'type' => 'general'],
        ['title' => 'تحديث سياسة الاستخدام',     'message' => 'تم تحديث سياسة الاستخدام والخصوصية. يرجى الاطلاع عليها قبل الاستمرار.',     'type' => 'system'],
        ['title' => 'تنبيه: رسوم معلقة',         'message' => 'لديك رسوم رحلات نقدية معلقة، يرجى تسويتها في أقرب وقت ممكن.',              'type' => 'general'],
        ['title' => 'تحديث التطبيق متاح',        'message' => 'إصدار جديد من التطبيق متاح الآن. قم بالتحديث للاستمتاع بمزايا جديدة.',      'type' => 'system'],
    ];

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function run(): void
    {
        config(['database.connections.mysql.read' => config('database.connections.mysql.write')]);
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::reconnect('mysql');
        $this->command->info('🚀  SyRide System Seeder');
        $this->command->line('────────────────────────────────────────────');

        $this->preparePlaceholderDocument();

        if ($this->shouldTruncate()) {
            $this->truncateTables();
        }

        $this->seedStaff();
        $this->resolveSycashWallet();

        [$drivers, $passengers] = $this->seedUsers();

        $this->seedRidesAndBookings($drivers, $passengers);
        $this->seedCancellationPenalties();

        $this->seedPushTokens($drivers, $passengers);
        $this->seedWalletRequests($drivers, $passengers);
        $this->seedComplaints($drivers, $passengers);
        $this->seedConversations($drivers, $passengers);
        $this->seedProfileComments($drivers, $passengers);
        $this->seedRatings($drivers, $passengers);
        $this->seedNotifications($drivers, $passengers);

        $this->printSummary();
    }

    // =========================================================================
    // TRUNCATE
    // =========================================================================

    private function shouldTruncate(): bool
    {
        return User::count() > 0
            && $this->command->confirm(
                'Users already exist. Truncate user/ride/wallet data and re-seed?',
                false
            );
    }

    private function truncateTables(): void
    {
        $this->command->info('Truncating tables (preserving employees)…');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        foreach ([
                     'user_notifications', 'notifications',
                     'user_ratings',
                     'wallet_requests',
                     'push_notification_tokens',
                     'profile_comments',
                     'messages', 'conversation_participants', 'conversations',
                     'complaint_attachments', 'complaints',
                     'wallet_transactions', 'wallets', 'bookings', 'rides',
                     'score_transactions', 'user_scores', 'photos', 'profiles', 'users',
                 ] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $this->command->info('  ✓ Tables cleared');
    }

    // =========================================================================
    // STAFF
    // =========================================================================

    private function seedStaff(): void
    {
        $this->command->info('Seeding staff…');

        $this->ensureEmployee('system_admin', 'sys@syride.com',    'SystemAdmin2024!',  StaffRole::SYSTEM_ADMIN,  'System',  'Administrator');
        $this->ensureEmployee('sycash',       'sycash@syride.com', 'SyCash2024!',       StaffRole::SYCASH,        'SyCash',  'Administrator');

        for ($i = 1; $i <= self::ADMINS; $i++) {
            $this->ensureEmployee(
                "admin_{$i}", "admin{$i}@syride.com", "Admin{$i}@2024",
                StaffRole::ADMIN, 'مدير', "النظام {$i}"
            );
        }

        for ($i = 1; $i <= self::SUPPORT_AGENTS; $i++) {
            $this->ensureEmployee(
                "agent_{$i}", "agent{$i}@syride.com", "Agent{$i}@2024",
                StaffRole::SUPPORT_AGENT, 'وكيل', "الدعم {$i}"
            );
        }

        $this->command->info('  ✓ ' . Employee::count() . ' employees ready');
    }

    private function ensureEmployee(
        string    $username,
        string    $email,
        string    $password,
        StaffRole $role,
        string    $firstName,
        string    $lastName
    ): Employee {
        return Employee::firstOrCreate(
            ['username' => $username],
            [
                'email'         => $email,
                'password'      => Hash::make($password),
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'role'          => $role,
                'is_active'     => true,
                'token_version' => 1,
            ]
        );
    }

    // =========================================================================
    // SYCASH WALLET
    // =========================================================================

    private function resolveSycashWallet(): void
    {
        $this->sycashEmployee = Employee::where('username', 'sycash')->firstOrFail();

        $this->sycashWallet = Wallet::firstOrCreate(
            ['phone_number' => '+963999000001'],
            [
                'user_id'        => null,
                'wallet_number'  => 'SYR-ESCROW-001',
                'balance'        => 0,
                'cash_ride_debt' => 0,
            ]
        );

        $this->command->info('  ✓ SyCash escrow wallet ready (ID ' . $this->sycashWallet->id . ')');
    }

    // =========================================================================
    // USERS
    // =========================================================================

    private function seedUsers(): array
    {
        $this->command->info('Seeding 1 000 users…');
        $bar = $this->command->getOutput()->createProgressBar(1000);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->start();

        $drivers    = [];
        $passengers = [];

        for ($i = 0; $i < self::VERIFIED_DRIVERS; $i++) {
            $bar->setMessage("driver {$i}");
            $drivers[] = $this->createVerifiedDriver($i);
            $bar->advance();
        }

        for ($i = 0; $i < self::VERIFIED_PASSENGERS; $i++) {
            $bar->setMessage("passenger {$i}");
            $passengers[] = $this->createVerifiedPassenger($i);
            $bar->advance();
        }

        for ($i = 0; $i < self::PENDING_USERS; $i++) {
            $bar->setMessage("pending {$i}");
            $this->createPendingUser($i);
            $bar->advance();
        }

        for ($i = 0; $i < self::UNVERIFIED_USERS; $i++) {
            $bar->setMessage("unverified {$i}");
            $this->createUnverifiedUser($i);
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('  ✓ 1 000 users seeded');

        return [$drivers, $passengers];
    }

    private function createVerifiedDriver(int $idx): User
    {
        $city = array_keys($this->cities)[$idx % count($this->cities)];

        $user = User::create([
            'first_name'            => $this->driverFirstNames[$idx % count($this->driverFirstNames)],
            'last_name'             => $this->lastNames[$idx % count($this->lastNames)],
            'email'                 => "driver_{$idx}@syride.test",
            'password'              => Hash::make('Password@123'),
            'gender'                => $idx % 5 === 0 ? 'F' : 'M',
            'address'               => $city,
            'status'                => 1,
            'is_verified_driver'    => true,
            'is_verified_passenger' => false,
            'verification_status'   => 'approved',
            'national_id'           => 'SYR-D-' . str_pad($idx + 1, 8, '0', STR_PAD_LEFT),
            'email_verified_at'     => now()->subDays(rand(10, 365)),
        ]);

        // FIX: delete observer-created profile(s) then create one complete row
        Profile::where('user_id', $user->id)->delete();

        Profile::create([
            'user_id'         => $user->id,
            'full_name'       => $user->first_name . ' ' . $user->last_name,
            'profile_photo'   => 'profiles/profile_photo/default-profile-photo.jpg',
            'description'     => "سائق محترف من {$city} — خبرة أكثر من " . rand(1, 10) . " سنوات",
            'type_of_car'     => $this->carTypes[$idx % count($this->carTypes)],
            'color_of_car'    => $this->carColors[$idx % count($this->carColors)],
            'number_of_seats' => rand(4, 6),
            'number_of_rides' => 0,
            'radio'           => (bool) rand(0, 1),
            'smoking'         => false,
            'gender'          => $idx % 5 === 0 ? 'F' : 'M',
            'address'         => $city,
        ]);

        $this->createDocuments($user->id, ['face_id', 'back_id', 'license']);

        $score = rand(60, 96);
        $this->createScore($user->id, $score, rand(5, 50));

        $wallet = $this->createWallet($user, '+96394' . str_pad($idx + 1000000, 7, '0', STR_PAD_LEFT));
        $this->adminFundWallet($wallet, rand(80000, 600000));

        return $user->refresh();
    }

    private function createVerifiedPassenger(int $idx): User
    {
        $city = array_keys($this->cities)[$idx % count($this->cities)];

        $user = User::create([
            'first_name'            => $this->passengerFirstNames[$idx % count($this->passengerFirstNames)],
            'last_name'             => $this->lastNames[$idx % count($this->lastNames)],
            'email'                 => "passenger_{$idx}@syride.test",
            'password'              => Hash::make('Password@123'),
            'gender'                => $idx % 3 === 0 ? 'F' : 'M',
            'address'               => $city,
            'status'                => 1,
            'is_verified_driver'    => false,
            'is_verified_passenger' => true,
            'verification_status'   => 'approved',
            'national_id'           => 'SYR-P-' . str_pad($idx + 1, 8, '0', STR_PAD_LEFT),
            'email_verified_at'     => now()->subDays(rand(5, 300)),
        ]);

        // FIX: delete observer-created profile(s) then create one complete row
        Profile::where('user_id', $user->id)->delete();

        Profile::create([
            'user_id'         => $user->id,
            'full_name'       => $user->first_name . ' ' . $user->last_name,
            'profile_photo'   => 'profiles/profile_photo/default-profile-photo.jpg',
            'description'     => null,
            'number_of_rides' => 0,
            'gender'          => $idx % 3 === 0 ? 'F' : 'M',
            'address'         => $city,
        ]);

        $this->createDocuments($user->id, ['face_id', 'back_id']);

        $score = rand(55, 92);
        $this->createScore($user->id, $score, rand(2, 30));

        $wallet = $this->createWallet($user, '+96395' . str_pad($idx + 1000000, 7, '0', STR_PAD_LEFT));
        $this->adminFundWallet($wallet, rand(25000, 250000));

        return $user->refresh();
    }

    private function createPendingUser(int $idx): User
    {
        $city     = array_keys($this->cities)[$idx % count($this->cities)];
        $isDriver = $idx % 2 === 0;

        $user = User::create([
            'first_name'            => 'مستخدم',
            'last_name'             => $this->lastNames[$idx % count($this->lastNames)],
            'email'                 => "pending_{$idx}@syride.test",
            'password'              => Hash::make('Password@123'),
            'gender'                => 'M',
            'address'               => $city,
            'status'                => 1,
            'is_verified_driver'    => false,
            'is_verified_passenger' => false,
            'verification_status'   => 'pending',
            'email_verified_at'     => now()->subDays(rand(1, 30)),
        ]);

        // FIX: delete observer-created profile(s) then create one complete row
        Profile::where('user_id', $user->id)->delete();

        Profile::create([
            'user_id'         => $user->id,
            'full_name'       => $user->first_name . ' ' . $user->last_name,
            'profile_photo'   => 'profiles/profile_photo/default-profile-photo.jpg',
            'number_of_rides' => 0,
            'gender'          => 'M',
            'address'         => $city,
        ]);

        $this->createDocuments(
            $user->id,
            $isDriver ? ['face_id', 'back_id', 'license'] : ['face_id', 'back_id']
        );

        $this->createScore($user->id, rand(50, 80), 0);

        return $user;
    }

    private function createUnverifiedUser(int $idx): User
    {
        $city = array_keys($this->cities)[$idx % count($this->cities)];

        $user = User::create([
            'first_name'            => 'جديد',
            'last_name'             => $this->lastNames[$idx % count($this->lastNames)],
            'email'                 => "new_{$idx}@syride.test",
            'password'              => Hash::make('Password@123'),
            'gender'                => 'M',
            'address'               => $city,
            'status'                => 1,
            'is_verified_driver'    => false,
            'is_verified_passenger' => false,
            'verification_status'   => 'none',
            'email_verified_at'     => now()->subDays(rand(1, 10)),
        ]);

        // FIX: delete observer-created profile(s) then create one complete row
        Profile::where('user_id', $user->id)->delete();

        Profile::create([
            'user_id'         => $user->id,
            'full_name'       => $user->first_name . ' ' . $user->last_name,
            'profile_photo'   => 'profiles/profile_photo/default-profile-photo.jpg',
            'number_of_rides' => 0,
            'gender'          => 'M',
            'address'         => $city,
        ]);

        $this->createScore($user->id, 70, 0);

        return $user;
    }

    // =========================================================================
    // RIDES & BOOKINGS
    // =========================================================================

    private function seedRidesAndBookings(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding rides (each driver creates 1–4)…');
        $bar = $this->command->getOutput()->createProgressBar(count($drivers));
        $bar->start();

        $rideIndex = [];

        foreach ($drivers as $driver) {
            for ($n = 0; $n < rand(1, 4); $n++) {
                $ride = $this->createRide($driver);
                if ($ride) {
                    $rideIndex[$ride['id']] = $ride;
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('  ✓ ' . count($rideIndex) . ' rides created');

        $this->command->info('Seeding bookings (each passenger books 0–5 rides)…');
        $rides = array_values($rideIndex);
        $bar   = $this->command->getOutput()->createProgressBar(count($passengers));
        $bar->start();

        foreach ($passengers as $passenger) {
            $count = rand(0, 5);
            for ($n = 0; $n < $count; $n++) {
                if (empty($rides)) break;
                $ride = $rides[rand(0, count($rides) - 1)];

                $currentSeats = DB::connection('mysql')
                    ->table('rides')
                    ->useWritePdo()                            // ← force primary read
                    ->where('id', $ride['id'])
                    ->value('available_seats');
                if ($currentSeats < 1) continue;
                $ride['available_seats'] = $currentSeats;

                $this->createBooking($passenger, $ride);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('  ✓ Bookings created');
    }

    private function createRide(User $driver): ?array
    {
        $cityNames  = array_keys($this->cities);
        $originName = $cityNames[array_rand($cityNames)];
        do {
            $destName = $cityNames[array_rand($cityNames)];
        } while ($destName === $originName);

        $origin = $this->cities[$originName];
        $dest   = $this->cities[$destName];

        $seats        = rand(1, 4);
        $pricePerSeat = rand(3000, 25000);

        $feeAmount = (int) round($pricePerSeat * $seats * 0.05);
        $payment   = rand(0, 2) === 0 ? 'e-pay' : 'cash';

        if ($payment === 'e-pay') {
            $driver->refresh();
            $w = $driver->wallet;
            if (! $w || $w->balance < $feeAmount) {
                $payment = 'cash';
            }
        }

        $scenario  = rand(0, 9);
        $departure = match (true) {
            $scenario <= 3 => now()->subDays(rand(2,  60))->setHour(rand(6, 21)),
            $scenario <= 5 => now()->addDays(rand(1,  14))->setHour(rand(6, 21)),
            $scenario <= 7 => now()->addDays(rand(1,   5))->setHour(rand(6, 21)),
            $scenario == 8 => now()->subHours(rand(1,  5)),
            default        => now()->addDays(rand(15, 30))->setHour(rand(6, 21)),
        };

        $status = match (true) {
            $departure->isPast() && $scenario <= 3 => RideStatus::FINISHED->value,
            $departure->isPast() && $scenario == 8 => RideStatus::AWAITING_CONFIRMATION->value,
            $scenario == 6                         => RideStatus::CANCELLED->value,
            default                                => RideStatus::ACTIVE->value,
        };

        $finishedAt        = null;
        $driverConfirmedAt = null;

        if ($status === RideStatus::FINISHED->value) {
            $finishedAt        = $departure->copy()->addHours(rand(1, 4));
            $driverConfirmedAt = $finishedAt->copy()->addMinutes(rand(5, 60));
        } elseif ($status === RideStatus::AWAITING_CONFIRMATION->value) {
            $finishedAt        = $departure->copy()->addHours(rand(1, 3));
            $driverConfirmedAt = $finishedAt->copy()->addMinutes(rand(5, 30));
        }

        $cashDeferred = $payment === 'cash' && rand(0, 4) === 0;

        try {
            $rideId = DB::table('rides')->insertGetId([
                'driver_id'            => $driver->id,
                'pickup_location'      => DB::raw("ST_GeomFromText('POINT({$origin['lng']} {$origin['lat']})', 4326)"),
                'destination_location' => DB::raw("ST_GeomFromText('POINT({$dest['lng']} {$dest['lat']})', 4326)"),
                'pickup_lat'           => $origin['lat'],
                'pickup_lng'           => $origin['lng'],
                'destination_lat'      => $dest['lat'],
                'destination_lng'      => $dest['lng'],
                'pickup_address'       => $originName,
                'destination_address'  => $destName,
                'departure_time'       => $departure->toDateTimeString(),
                'available_seats'      => $seats,
                'price_per_seat'       => $pricePerSeat,
                'vehicle_type'         => $this->carTypes[array_rand($this->carTypes)],
                'payment_method'       => $payment,
                'booking_type'         => rand(0, 1) === 0 ? 'direct' : 'request',
                'communication_number' => '09' . str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                'status'               => $status,
                'cash_creation_fee'    => $payment === 'cash' ? $feeAmount : null,
                'cash_fee_deferred'    => $cashDeferred,
                'notes'                => null,
                'distance'             => rand(30000, 450000),
                'duration'             => rand(1800, 18000),
                'route_geometry'       => json_encode([
                    'type'        => 'LineString',
                    'coordinates' => [
                        [$origin['lng'], $origin['lat']],
                        [$dest['lng'],   $dest['lat']],
                    ],
                ]),
                'finished_at'         => $finishedAt?->toDateTimeString(),
                'driver_confirmed_at' => $driverConfirmedAt?->toDateTimeString(),
                'created_at'          => $departure->copy()->subDays(rand(1, 7))->toDateTimeString(),
                'updated_at'          => $departure->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            $this->command->warn('  ⚠ Ride insert failed: ' . $e->getMessage());
            return null;
        }

        if ($payment === 'e-pay' && $feeAmount > 0 && $driver->wallet) {
            $this->walletDebit(
                wallet:      $driver->wallet->fresh(),
                amount:      $feeAmount,
                type:        'ride_creation_fee',
                description: "رسوم إنشاء رحلة (5%) — رحلة #{$rideId}",
                txId:        "FEE-{$rideId}-" . rand(1000, 9999),
                ref:         "ride:{$rideId}",
                userId:      $driver->id,
            );
            $this->walletCredit(
                wallet:      $this->sycashWallet,
                amount:      $feeAmount,
                type:        'ride_creation_fee_received',
                description: "رسوم إنشاء رحلة #{$rideId} من السائق #{$driver->id}",
                txId:        "FEERCV-{$rideId}-" . rand(1000, 9999),
                ref:         "ride:{$rideId}",
                userId:      null,
            );
        }

        if ($payment === 'cash' && $cashDeferred && $feeAmount > 0 && $driver->wallet) {
            $driver->wallet->fresh()->increment('cash_ride_debt', $feeAmount);
        }

        return [
            'id'              => $rideId,
            'driver_id'       => $driver->id,
            'status'          => $status,
            'payment_method'  => $payment,
            'price_per_seat'  => $pricePerSeat,
            'available_seats' => $seats,
            'departure_time'  => $departure,
        ];
    }

    private function createBooking(User $passenger, array $ride): void
    {
        if ($ride['status'] === RideStatus::CANCELLED->value) return;
        if ($ride['available_seats'] < 1)                     return;
        if ($ride['driver_id'] === $passenger->id)            return;

        $seats     = rand(1, min(2, $ride['available_seats']));
        $totalCost = $seats * $ride['price_per_seat'];

        if ($ride['payment_method'] === 'e-pay') {
            $passenger->refresh();
            $w = $passenger->wallet;
            if (! $w || $w->balance < $totalCost) return;
        }

        [$bookingStatus, $completedAt, $passengerConfirmedAt] = match ($ride['status']) {
            RideStatus::FINISHED->value => [
                BookingStatus::COMPLETED->value,
                $ride['departure_time']->copy()->addHours(rand(1, 4)),
                $ride['departure_time']->copy()->addHours(rand(2, 6)),
            ],
            RideStatus::AWAITING_CONFIRMATION->value => [
                BookingStatus::CONFIRMED->value,
                null,
                null,
            ],
            default => [
                rand(0, 1) === 0 ? BookingStatus::CONFIRMED->value : BookingStatus::PENDING->value,
                null,
                null,
            ],
        };

        $bookingId = DB::table('bookings')->insertGetId([
            'user_id'                => $passenger->id,
            'ride_id'                => $ride['id'],
            'seats'                  => $seats,
            'status'                 => $bookingStatus,
            'communication_number'   => '09' . str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'passenger_confirmed_at' => $passengerConfirmedAt?->toDateTimeString(),
            'completed_at'           => $completedAt?->toDateTimeString(),
            'created_at'             => $ride['departure_time']->copy()->subDays(rand(1, 5))->toDateTimeString(),
            'updated_at'             => $ride['departure_time']->toDateTimeString(),
        ]);

        DB::table('rides')
            ->where('id', $ride['id'])
            ->where('available_seats', '>=', $seats)   // ← guard
            ->decrement('available_seats', $seats);

        if (
            $ride['payment_method'] === 'e-pay'
            && in_array($bookingStatus, [BookingStatus::CONFIRMED->value, BookingStatus::COMPLETED->value])
        ) {
            $passenger->refresh();
            if ($passenger->wallet && $passenger->wallet->balance >= $totalCost) {

                $this->walletDebit(
                    wallet:      $passenger->wallet->fresh(),
                    amount:      $totalCost,
                    type:        'ride_payment',
                    description: "دفع رحلة #{$ride['id']} (أمانة)",
                    txId:        "PAY-{$bookingId}-" . rand(1000, 9999),
                    ref:         "booking:{$bookingId}",
                    userId:      $passenger->id,
                );
                $this->walletCredit(
                    wallet:      $this->sycashWallet,
                    amount:      $totalCost,
                    type:        'escrow_hold',
                    description: "أمانة حجز #{$bookingId}",
                    txId:        "ESC-{$bookingId}-" . rand(1000, 9999),
                    ref:         "booking:{$bookingId}",
                    userId:      null,
                );

                if ($bookingStatus === BookingStatus::COMPLETED->value) {
                    $driver = User::with('wallet')->find($ride['driver_id']);
                    if ($driver && $driver->wallet) {
                        $this->walletDebit(
                            wallet:      $this->sycashWallet->fresh(),
                            amount:      $totalCost,
                            type:        'escrow_release',
                            description: "إفراج أمانة حجز #{$bookingId} للسائق",
                            txId:        "ESCREL-{$bookingId}-" . rand(1000, 9999),
                            ref:         "booking:{$bookingId}",
                            userId:      null,
                        );
                        $this->walletCredit(
                            wallet:      $driver->wallet->fresh(),
                            amount:      $totalCost,
                            type:        'ride_earning',
                            description: "أرباح رحلة #{$ride['id']}",
                            txId:        "EARN-{$bookingId}-" . rand(1000, 9999),
                            ref:         "booking:{$bookingId}",
                            userId:      $driver->id,
                        );
                    }
                }
            }
        }

        if ($bookingStatus === BookingStatus::COMPLETED->value) {
            $this->applyScore($passenger->id,     'ride_completed', +10, "اكتملت الرحلة #{$ride['id']}");
            $this->applyScore($ride['driver_id'], 'ride_completed', +10, "اكتملت الرحلة #{$ride['id']}");
        }
    }

    // =========================================================================
    // NEW TABLE SEEDERS
    // =========================================================================

    private function seedPushTokens(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding push notification tokens…');

        $rows = [];
        foreach (array_merge($drivers, $passengers) as $user) {
            $count = rand(1, 2);
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'user_id'     => $user->id,
                    'token'       => Str::random(64),
                    'device_type' => rand(0, 1) ? 'android' : 'ios',
                    'is_active'   => rand(0, 4) > 0,
                    'created_at'  => now()->subDays(rand(1, 180))->toDateTimeString(),
                    'updated_at'  => now()->subDays(rand(0, 30))->toDateTimeString(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('push_notification_tokens')->insert($chunk);
        }

        $this->command->info('  ✓ ' . count($rows) . ' push tokens inserted');
    }

    private function seedWalletRequests(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding wallet requests…');

        // processed_by FK points to users.id — use first verified drivers as stand-in processors
        $processorIds = collect($drivers)->take(5)->pluck('id')->toArray();

        $rows = [];
        foreach (array_merge($drivers, $passengers) as $user) {
            $count = rand(0, 3);
            for ($i = 0; $i < $count; $i++) {
                $type   = rand(0, 1) ? 'charge' : 'withdraw';
                $status = ['pending', 'approved', 'rejected'][rand(0, 2)];

                $processedBy = (! empty($processorIds) && in_array($status, ['approved', 'rejected']))
                    ? $processorIds[array_rand($processorIds)]
                    : null;
                $processedAt = $processedBy
                    ? now()->subDays(rand(0, 15))->toDateTimeString()
                    : null;

                $rows[] = [
                    'user_id'      => $user->id,
                    'wallet_id'    => $user->wallet_id,
                    'type'         => $type,
                    'amount'       => rand(5000, 150000),
                    'status'       => $status,
                    'user_notes'   => $type === 'charge'
                        ? 'أرجو شحن المحفظة بالمبلغ المحدد.'
                        : 'طلب سحب رصيد من المحفظة.',
                    'admin_notes'  => $processedBy
                        ? ($status === 'approved' ? 'تمت الموافقة وتنفيذ العملية.' : 'تم الرفض لعدم استيفاء الشروط.')
                        : null,
                    'processed_by' => $processedBy,
                    'processed_at' => $processedAt,
                    'created_at'   => now()->subDays(rand(1, 60))->toDateTimeString(),
                    'updated_at'   => now()->subDays(rand(0, 15))->toDateTimeString(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('wallet_requests')->insert($chunk);
        }

        $this->command->info('  ✓ ' . count($rows) . ' wallet requests inserted');
    }

    private function seedComplaints(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding complaints…');

        $agentIds = Employee::where('role', StaffRole::SUPPORT_AGENT->value)
            ->pluck('id')
            ->toArray();

        $complaintCount = 0;

        foreach (array_merge($drivers, $passengers) as $user) {
            if (rand(0, 3) !== 0) continue;

            $type   = $this->complaintTypes[array_rand($this->complaintTypes)];
            $status = $this->complaintStatuses[array_rand($this->complaintStatuses)];

            $assignedTo = (! empty($agentIds) && rand(0, 1))
                ? $agentIds[array_rand($agentIds)]
                : null;

            $isResolved      = in_array($status, ['resolved', 'closed']);
            $resolvedAt      = $isResolved ? now()->subDays(rand(1, 30))->toDateTimeString() : null;
            $resolutionNotes = $isResolved
                ? 'تم حل الشكوى بنجاح من قبل الفريق المختص وإبلاغ المستخدم بالنتيجة.'
                : null;

            $complaintId = DB::table('complaints')->insertGetId([
                'user_id'          => $user->id,
                'assigned_to'      => $assignedTo,
                'title'            => $this->complaintTitles[$type],
                'description'      => $this->complaintDescriptions[$type],
                'type'             => $type,
                'status'           => $status,
                'resolution_notes' => $resolutionNotes,
                'resolved_at'      => $resolvedAt,
                'created_at'       => now()->subDays(rand(1, 90))->toDateTimeString(),
                'updated_at'       => now()->subDays(rand(0, 30))->toDateTimeString(),
            ]);

            if (rand(0, 2) === 0) {
                $this->createComplaintAttachment($complaintId, $user->id);
            }

            $complaintCount++;
        }

        $this->command->info("  ✓ {$complaintCount} complaints inserted");
    }

    private function createComplaintAttachment(int $complaintId, int $userId): void
    {
        $path = "complaints/{$complaintId}/{$userId}_evidence.jpg";
        Storage::disk('public')->put($path, $this->placeholderDoc);

        DB::table('complaint_attachments')->insert([
            'complaint_id'  => $complaintId,
            'path'          => $path,
            'original_name' => 'complaint_photo.jpg',
            'mime_type'     => 'image/jpeg',
            'size'          => strlen($this->placeholderDoc),
            'created_at'    => now()->subDays(rand(1, 30))->toDateTimeString(),
            'updated_at'    => now()->subDays(rand(0, 10))->toDateTimeString(),
        ]);
    }

    private function seedConversations(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding conversations & messages…');

        $driverPool    = $drivers;
        $passengerPool = $passengers;
        shuffle($driverPool);
        shuffle($passengerPool);

        $limit = min(self::CONVERSATIONS, count($driverPool), count($passengerPool));

        $convCount = 0;
        $msgCount  = 0;

        for ($i = 0; $i < $limit; $i++) {
            $driver    = $driverPool[$i];
            $passenger = $passengerPool[$i];

            $createdAt = now()->subDays(rand(1, 120));

            $convId = DB::table('conversations')->insertGetId([
                'title'      => null,
                'type'       => 'private',
                'metadata'   => null,
                'created_at' => $createdAt->toDateTimeString(),
                'updated_at' => $createdAt->copy()->addDays(rand(0, 10))->toDateTimeString(),
            ]);

            foreach ([$driver->id, $passenger->id] as $uid) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $convId,
                    'user_id'         => $uid,
                    'role'            => 'member',
                    'joined_at'       => $createdAt->toDateTimeString(),
                    'last_read_at'    => rand(0, 1)
                        ? now()->subDays(rand(0, 5))->toDateTimeString()
                        : null,
                    'created_at' => $createdAt->toDateTimeString(),
                    'updated_at' => $createdAt->toDateTimeString(),
                ]);
            }

            $msgBatch  = rand(3, 12);
            $senders   = [$driver->id, $passenger->id];
            $msgOffset = $createdAt->copy();

            for ($m = 0; $m < $msgBatch; $m++) {
                $msgOffset->addMinutes(rand(1, 60));
                DB::table('messages')->insert([
                    'conversation_id' => $convId,
                    'sender_id'       => $senders[$m % 2],
                    'type'            => 'text',
                    'content'         => $this->messageTemplates[array_rand($this->messageTemplates)],
                    'metadata'        => null,
                    'is_edited'       => 0,
                    'edited_at'       => null,
                    'read_at'         => rand(0, 1)
                        ? $msgOffset->copy()->addMinutes(rand(1, 30))->toDateTimeString()
                        : null,
                    'created_at' => $msgOffset->toDateTimeString(),
                    'updated_at' => $msgOffset->toDateTimeString(),
                ]);
                $msgCount++;
            }

            $convCount++;
        }

        $this->command->info("  ✓ {$convCount} conversations, {$msgCount} messages inserted");
    }

    private function seedProfileComments(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding profile comments…');

        $driverIds  = array_map(fn ($u) => $u->id, $drivers);
        $profileMap = DB::table('profiles')
            ->whereIn('user_id', $driverIds)
            ->pluck('id', 'user_id')
            ->toArray();

        $rows = [];
        foreach ($passengers as $passenger) {
            if (rand(0, 2) !== 0) continue;

            $driver    = $drivers[array_rand($drivers)];
            $profileId = $profileMap[$driver->id] ?? null;
            if (! $profileId) continue;

            $rows[] = [
                'profile_id' => $profileId,
                'user_id'    => $passenger->id,
                'comment'    => $this->profileCommentTemplates[array_rand($this->profileCommentTemplates)],
                'created_at' => now()->subDays(rand(1, 90))->toDateTimeString(),
                'updated_at' => now()->subDays(rand(0, 30))->toDateTimeString(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('profile_comments')->insert($chunk);
        }

        $this->command->info('  ✓ ' . count($rows) . ' profile comments inserted');
    }

    private function seedRatings(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding user ratings…');

        $seen = [];
        $rows = [];

        foreach ($passengers as $passenger) {
            if (rand(0, 9) < 5) continue;

            $driver  = $drivers[array_rand($drivers)];
            $pairKey = "{$passenger->id}:{$driver->id}";
            if (isset($seen[$pairKey])) continue;
            $seen[$pairKey] = true;

            // FIX: 'rater_id' key was missing — bare $passenger->id was an unnamed element
            $rows[] = [
                'rated_user_id' => $driver->id,
                'rater_id'      => $passenger->id,
                'rating'        => rand(3, 5),
                'created_at'    => now()->subDays(rand(1, 120))->toDateTimeString(),
                'updated_at'    => now()->subDays(rand(0, 30))->toDateTimeString(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('user_ratings')->insert($chunk);
        }

        $this->command->info('  ✓ ' . count($rows) . ' ratings inserted');
    }

    private function seedNotifications(array $drivers, array $passengers): void
    {
        $this->command->info('Seeding notifications…');

        $notificationIds = [];
        foreach ($this->notificationTemplates as $tpl) {
            $notificationIds[] = DB::table('notifications')->insertGetId([
                'title'      => $tpl['title'],
                'message'    => $tpl['message'],
                'type'       => $tpl['type'],
                'data'       => null,
                'user_id'    => null,
                'sent_at'    => now()->subDays(rand(1, 60))->toDateTimeString(),
                'created_at' => now()->subDays(rand(1, 60))->toDateTimeString(),
                'updated_at' => now()->subDays(rand(0, 20))->toDateTimeString(),
            ]);
        }

        $this->command->info('  ✓ ' . count($notificationIds) . ' notifications created');

        $rows = [];
        foreach (array_merge($drivers, $passengers) as $user) {
            foreach ($notificationIds as $notifId) {
                if (rand(0, 2) === 0) continue;

                $rows[] = [
                    'user_id'         => $user->id,
                    'notification_id' => $notifId,
                    'read_at'         => rand(0, 1)
                        ? now()->subDays(rand(0, 20))->toDateTimeString()
                        : null,
                    'created_at' => now()->subDays(rand(1, 30))->toDateTimeString(),
                    'updated_at' => now()->subDays(rand(0, 10))->toDateTimeString(),
                ];
            }
        }

        $deduped = [];
        foreach ($rows as $row) {
            $key = "{$row['user_id']}:{$row['notification_id']}";
            $deduped[$key] = $row;
        }

        foreach (array_chunk(array_values($deduped), 1000) as $chunk) {
            DB::table('user_notifications')->insert($chunk);
        }

        $this->command->info('  ✓ ' . count($deduped) . ' user_notifications inserted');
    }

    // =========================================================================
    // WALLET PRIMITIVES
    // =========================================================================

    private function createWallet(User $user, string $phone): Wallet
    {
        $wallet = Wallet::create([
            'user_id'        => $user->id,
            'wallet_number'  => 'SYR-' . strtoupper(Str::random(10)),
            'phone_number'   => $phone,
            'balance'        => 0,
            'cash_ride_debt' => 0,
        ]);

        User::where('id', $user->id)->update(['wallet_id' => $wallet->id]);

        return $wallet;
    }

    private function adminFundWallet(Wallet $wallet, float $amount): void
    {
        $prev = (float) $wallet->balance;
        $new  = $prev + $amount;

        $wallet->balance = $new;
        $wallet->save();

        WalletTransaction::create([
            'wallet_id'        => $wallet->id,
            'user_id'          => null,
            'type'             => 'admin_charge',
            'amount'           => $amount,
            'previous_balance' => $prev,
            'new_balance'      => $new,
            'description'      => 'شحن محفظة من الإدارة (Seeder)',
            'transaction_id'   => 'SEED-' . $wallet->id . '-' . now()->timestamp . '-' . rand(1000, 9999),
            'status'           => 'completed',
            'reference'        => 'seeder:initial_fund',
        ]);
    }

    private function walletDebit(
        Wallet  $wallet,
        float   $amount,
        string  $type,
        string  $description,
        string  $txId,
        string  $ref,
        ?int    $userId,
    ): void {
        $wallet->refresh();
        if ($wallet->balance < $amount) return;

        $prev = (float) $wallet->balance;
        $new  = $prev - $amount;
        $wallet->balance = $new;
        $wallet->save();

        WalletTransaction::create([
            'wallet_id'        => $wallet->id,
            'user_id'          => $userId,
            'type'             => $type,
            'amount'           => -$amount,
            'previous_balance' => $prev,
            'new_balance'      => $new,
            'description'      => $description,
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'reference'        => $ref,
        ]);
    }

    private function walletCredit(
        Wallet  $wallet,
        float   $amount,
        string  $type,
        string  $description,
        string  $txId,
        string  $ref,
        ?int    $userId,
    ): void {
        $wallet->refresh();

        $prev = (float) $wallet->balance;
        $new  = $prev + $amount;
        $wallet->balance = $new;
        $wallet->save();

        WalletTransaction::create([
            'wallet_id'        => $wallet->id,
            'user_id'          => $userId,
            'type'             => $type,
            'amount'           => $amount,
            'previous_balance' => $prev,
            'new_balance'      => $new,
            'description'      => $description,
            'transaction_id'   => $txId,
            'status'           => 'completed',
            'reference'        => $ref,
        ]);
    }

    // =========================================================================
    // SCORE
    // =========================================================================

    private function createScore(int $userId, int $score, int $totalRides): void
    {
        $cancelCount = $totalRides > 0 ? (int) ($totalRides * rand(0, 10) / 100) : 0;
        UserScore::updateOrCreate(
            ['user_id' => $userId],
            [
                'score'               => $score,
                'total_rides'         => $totalRides,
                'total_cancellations' => $cancelCount,
                'total_no_shows'      => 0,
            ]
        );
    }

    private function applyScore(int $userId, string $action, int $points, string $reason): void
    {
        $score = UserScore::where('user_id', $userId)->first();
        if (! $score) return;

        $prev = $score->score;
        $new  = max(0, min(100, $prev + $points));

        try {
            DB::table('score_transactions')->insert([
                'user_id'                  => $userId,
                'action'                   => $action,
                'points'                   => $points,
                'previous_score'           => $prev,
                'new_score'                => $new,
                'reason'                   => $reason,
                'high_cancel_rate_applied' => false,
                'reference_type'           => null,
                'reference_id'             => null,
                'created_at'               => now(),
            ]);
        } catch (\Throwable) {}

        $score->score        = $new;
        $score->total_rides += 1;
        $score->save();
    }

    private function applyPenalty(int $userId, string $action, int $points, string $reason): void
    {
        if ($points === 0) return;

        $score = UserScore::where('user_id', $userId)->first();
        if (! $score) return;

        $prev = $score->score;
        $new  = max(0, min(100, $prev + $points));

        try {
            DB::table('score_transactions')->insert([
                'user_id'                  => $userId,
                'action'                   => $action,
                'points'                   => $points,
                'previous_score'           => $prev,
                'new_score'                => $new,
                'reason'                   => $reason,
                'high_cancel_rate_applied' => false,
                'reference_type'           => null,
                'reference_id'             => null,
                'created_at'               => now()->subDays(rand(1, 30)),
            ]);
        } catch (\Throwable) {}

        $score->score               = $new;
        $score->total_cancellations += 1;
        $score->save();
    }

    private function seedCancellationPenalties(): void
    {
        $this->command->info('Seeding cancellation score penalties…');
        $count = 0;

        // Driver penalty for every cancelled ride
        $cancelledRides = DB::table('rides')
            ->where('status', RideStatus::CANCELLED->value)
            ->get(['id', 'driver_id']);

        foreach ($cancelledRides as $ride) {
            $pct = rand(20, 95);
            [$points, $action] = match (true) {
                $pct < 30 => [0,   'driver_cancel_ride_early'],
                $pct < 50 => [-7,  'driver_cancel_ride_mid'],
                default   => [-12, 'driver_cancel_ride_late'],
            };
            $this->applyPenalty(
                $ride->driver_id, $action, $points,
                "إلغاء رحلة #{$ride->id} ({$pct}% من الوقت مضى)"
            );
            if ($points !== 0) $count++;
        }

        // Passenger penalties — simulate ~100 late cancellations on active bookings
        $bookings = DB::table('bookings')
            ->whereIn('status', [BookingStatus::CONFIRMED->value, BookingStatus::PENDING->value])
            ->inRandomOrder()
            ->limit(100)
            ->get(['id', 'user_id']);

        foreach ($bookings as $booking) {
            $pct = rand(10, 90);
            [$points, $action] = match (true) {
                $pct < 30 => [-3, 'passenger_cancel_early'],
                $pct < 60 => [-5, 'passenger_cancel_mid'],
                default   => [-8, 'passenger_cancel_late'],
            };
            $this->applyPenalty(
                $booking->user_id, $action, $points,
                "إلغاء حجز #{$booking->id} ({$pct}% من الوقت مضى)"
            );
            $count++;
        }

        $this->command->info("  ✓ {$count} cancellation penalties seeded");
    }

    // =========================================================================
    // DOCUMENTS
    // =========================================================================

    private function createDocuments(int $userId, array $types): void
    {
        foreach ($types as $type) {
            $path = "documents/{$type}/{$userId}_{$type}.jpg";
            Storage::disk('public')->put($path, $this->placeholderDoc);

            DB::table('photos')->insert([
                'user_id'    => $userId,
                'type'       => $type,
                'path'       => $path,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function preparePlaceholderDocument(): void
    {
        $this->placeholderDoc = base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDB'
            . 'kSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAAR'
            . 'CAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAA'
            . 'AAAAAAAAAAAAAP/EABQBAQAAAAAAAAAAAAAAAAAAAAD/xAAUEQEAAAAAAAAAAAAA'
            . 'AAAAAAAA/9oADAMBAAIRAxEAPwCwABmX/9k='
        );
    }

    // =========================================================================
    // SUMMARY
    // =========================================================================

    private function printSummary(): void
    {
        $this->command->line('');
        $this->command->line('────────────────────────────────────────────');
        $this->command->info('✅  Seeding complete — snapshot summary:');
        $this->command->line('');

        $this->command->table(['Category', 'Count'], [
            ['Employees (all roles)',       Employee::count()],
            ['Verified drivers',            User::where('is_verified_driver',    true)->count()],
            ['Verified passengers',         User::where('is_verified_passenger', true)->count()],
            ['Pending verification',        User::where('verification_status',   'pending')->count()],
            ['Unverified users',            User::where('verification_status',   'none')->count()],
            ['──────────────────', '────'],
            ['Total rides',                 DB::table('rides')->count()],
            ['  Active (future)',            DB::table('rides')->where('status', 'active')->count()],
            ['  Finished',                  DB::table('rides')->where('status', 'finished')->count()],
            ['  Awaiting confirmation',     DB::table('rides')->where('status', 'awaiting_confirmation')->count()],
            ['  Cancelled',                 DB::table('rides')->where('status', 'cancelled')->count()],
            ['──────────────────', '────'],
            ['Total bookings',              DB::table('bookings')->count()],
            ['  Completed',                 DB::table('bookings')->where('status', 'completed')->count()],
            ['  Confirmed',                 DB::table('bookings')->where('status', 'confirmed')->count()],
            ['  Pending',                   DB::table('bookings')->where('status', 'pending')->count()],
            ['──────────────────', '────'],
            ['Wallet transactions',         DB::table('wallet_transactions')->count()],
            ['SyCash escrow balance',       number_format($this->sycashWallet?->fresh()->balance ?? 0) . ' SYP'],
            ['──────────────────', '────'],
            ['Complaints',                  DB::table('complaints')->count()],
            ['  Pending',                   DB::table('complaints')->where('status', 'pending')->count()],
            ['  In Review',                 DB::table('complaints')->where('status', 'in_review')->count()],
            ['  Resolved / Closed',         DB::table('complaints')->whereIn('status', ['resolved', 'closed'])->count()],
            ['Complaint attachments',       DB::table('complaint_attachments')->count()],
            ['──────────────────', '────'],
            ['Conversations',               DB::table('conversations')->count()],
            ['Messages',                    DB::table('messages')->count()],
            ['Profile comments',            DB::table('profile_comments')->count()],
            ['Push notification tokens',    DB::table('push_notification_tokens')->count()],
            ['Wallet requests',             DB::table('wallet_requests')->count()],
            ['User ratings',                DB::table('user_ratings')->count()],
            ['Notifications (global)',      DB::table('notifications')->count()],
            ['User notifications',          DB::table('user_notifications')->count()],
        ]);

        $this->command->line('');
        $this->command->line('Login credentials (all test accounts):');
        $this->command->line('  Password  : Password@123');
        $this->command->line('  Drivers   : driver_0@syride.test … driver_249@syride.test');
        $this->command->line('  Passengers: passenger_0@syride.test … passenger_399@syride.test');
        $this->command->line('  Admins    : admin_1@syride.com (Admin1@2024) … admin_3@syride.com');
        $this->command->line('  Agents    : agent_1@syride.com (Agent1@2024) … agent_5@syride.com');
        $this->command->line('');
    }
}
