<?php

namespace Tests\Feature\AppFuture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Cross-context boundary ratchet (batch 233).
 *
 * `BoundaryDependencyTest` polices LAYERS (Domain must not name Services, controllers
 * must not name Models, ...). It says nothing about the five BOUNDED CONTEXTS inside
 * those layers, so a Ride service could still reach straight into `App\Services\Wallet`
 * and the suite stayed green. This file is the rule for that:
 *
 *   RIDE     app/Services/Ride, Services/Score, Services/Geocoding, Domain/Score,
 *            Repositories/RideRepository, RideController, ScoreController, API/Support/Ride*Handler
 *   MONEY    app/Services/Payment, Services/Wallet, Domain/Payment, Support/FeeSplit,
 *            Support/PostingKey, WalletController, WalletRequestController
 *   IDENTITY Services/Auth, Services/Verification, Services/Profile, JwtService, the three
 *            OTP services, EmployeeAuthService, StaffJwtService, the auth/profile
 *            controllers and handlers, the User/Otp/PasswordReset/Verification/Photo/Profile
 *            repositories
 *   COMM     NotificationService, Services/PushNotification, Services/Chat,
 *            Services/MessageTypes, Listeners, Jobs, Notifications, Mail, Broadcasting,
 *            the Chat/Notification/PushNotification/Contact controllers, ChatRepository
 *   CONSOLE  Services/Admin, Services/Staff minus EmployeeAuthService and StaffJwtService,
 *            Services/Complaint, the AdminX / StaffX controllers (X = the wildcard),
 *            ComplaintController, the Complaint/Employee repositories
 *
 * Everything else is the shared kernel (Models, Enums, DTOs, Interfaces, Events,
 * Domain/ValueObjects, Exceptions, GeoPoint, Services/File) and may be imported by any
 * context, so it is never an edge. Composition-root files (Providers, Support/ContainerBindings,
 * Http/Middleware, Http/Requests, Console commands) are outside every context and are not
 * scanned as sources either.
 *
 * An EDGE is one concrete cross-context import, i.e. one (source file, target class) pair
 * where the class is declared in the other context and is NOT reached through an
 * `App\Interfaces` port. The shared kernel and the ports are how a context is supposed to
 * talk to another one; naming the other context's concrete class is the violation.
 *
 * Each ordered pair has its own ratchet and its own BASELINE, measured on 2026-10-29
 * (56 edges in total). A new edge fails the run; counts BELOW the baseline must be claimed
 * by editing BASELINES, so the improvement is never accidental; a baseline is only ever
 * lowered, never raised.
 *
 * The scanner is deliberately dumb: a class reference is resolved from the file's own
 * `use` table and namespace, with a unique-short-name fallback. No comment or string
 * literal can produce an edge (only name tokens are read), and no regex escaping can
 * silently drop one.
 */
class CrossContextBoundaryTest extends TestCase
{
    /**
     * Ordered pairs and their measured baselines. Format: <FROM>_to_<TO> => edges.
     * Lowering these numbers is the whole point of the batch; raising one is forbidden.
     */
    private const BASELINES = [
        // Batch 233 step (b): all thirteen RIDE money sites now take an App\Interfaces\Payment port.
        'RIDE_to_MONEY' => 0,
        'RIDE_to_IDENTITY' => 1,
        // Batch 233 step (a): all twelve RIDE notification sites now take the NotificationPort.
        'RIDE_to_COMM' => 0,
        'RIDE_to_CONSOLE' => 0,

        'MONEY_to_RIDE' => 0,
        'MONEY_to_IDENTITY' => 0,
        // Batch 233 step (a): WalletRequestService notifies through the NotificationPort.
        'MONEY_to_COMM' => 0,
        'MONEY_to_CONSOLE' => 0,

        'IDENTITY_to_RIDE' => 3,
        'IDENTITY_to_MONEY' => 1,
        // Batch 233 step (a): the two notification sites are ported; one COMM edge remains
        // (EmailOtpService -> App\Mail\OtpVerificationMail), which step (c) removes.
        'IDENTITY_to_COMM' => 1,
        'IDENTITY_to_CONSOLE' => 0,

        'COMM_to_RIDE' => 0,
        'COMM_to_MONEY' => 0,
        'COMM_to_IDENTITY' => 0,
        'COMM_to_CONSOLE' => 2,

        'CONSOLE_to_RIDE' => 2,
        'CONSOLE_to_MONEY' => 3,
        'CONSOLE_to_IDENTITY' => 7,
        // Batch 233 step (a): seven CONSOLE notification sites are ported; one COMM edge
        // remains (StaffChatController -> ChatMessageHandler), which step (c) removes.
        'CONSOLE_to_COMM' => 1,
    ];

    private const CONTEXTS = ['RIDE', 'MONEY', 'IDENTITY', 'COMM', 'CONSOLE'];

    /** Auth/profile controllers: IDENTITY, even when their name starts with Staff. */
    private const IDENTITY_CONTROLLERS = [
        'LoginController.php', 'LogoutController.php', 'RefreshTokenController.php',
        'SignupController.php', 'ForgotPasswordController.php', 'ResetPasswordController.php',
        'OtpController.php', 'VerifyPasswordOtpController.php', 'TextMeOtpController.php',
        'EmailVerificationController.php', 'VerificationController.php', 'DocumentController.php',
        'GoogleController.php', 'ProfileController.php', 'PassengerProfileController.php',
        'StaffAuthController.php',
    ];

    /** Auth/profile handlers: IDENTITY. */
    private const IDENTITY_HANDLERS = [
        'LoginHandler.php', 'SignupRegistrationHandler.php', 'DocumentUploadHandler.php',
        'ProfileInteractionHandler.php',
    ];

    private const RIDE_CONTROLLERS = ['RideController.php', 'ScoreController.php'];

    private const MONEY_CONTROLLERS = ['WalletController.php', 'WalletRequestController.php'];

    private const COMM_CONTROLLERS = [
        'ChatController.php', 'NotificationController.php',
        'PushNotificationController.php', 'ContactController.php',
    ];

    /** @var array<string, array<string, string[]>>|null */
    private static ?array $scanCache = null;

    /**
     * The bounded context a file belongs to, or SHARED / UNASSIGNED.
     */
    private function contextOf(string $rel): string
    {
        // ---- shared kernel: importable by every context ---------------------
        foreach (['Models/', 'Enums/', 'DTOs/', 'Interfaces/', 'Events/', 'Exceptions/'] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return 'SHARED';
            }
        }
        if (str_starts_with($rel, 'Domain/ValueObjects/')) {
            return 'SHARED';
        }
        if ($rel === 'Support/GeoPoint.php' || str_starts_with($rel, 'Services/File/')) {
            return 'SHARED';
        }

        // ---- COMM -----------------------------------------------------------
        foreach ([
            'Services/PushNotification/', 'Services/Chat/', 'Services/MessageTypes/',
            'Listeners/', 'Jobs/', 'Notifications/', 'Mail/', 'Broadcasting/',
        ] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return 'COMM';
            }
        }
        if ($rel === 'Services/NotificationService.php') {
            return 'COMM';
        }

        // ---- MONEY ----------------------------------------------------------
        foreach (['Services/Payment/', 'Services/Wallet/', 'Domain/Payment/'] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return 'MONEY';
            }
        }
        if (in_array($rel, ['Support/FeeSplit.php', 'Support/PostingKey.php'], true)) {
            return 'MONEY';
        }

        // ---- RIDE -----------------------------------------------------------
        foreach (['Services/Ride/', 'Services/Score/', 'Services/Geocoding/', 'Domain/Score/'] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return 'RIDE';
            }
        }
        if ($rel === 'Repositories/RideRepository.php') {
            return 'RIDE';
        }

        // ---- IDENTITY -------------------------------------------------------
        foreach (['Services/Auth/', 'Services/Verification/', 'Services/Profile/'] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return 'IDENTITY';
            }
        }
        if (in_array(basename($rel), [
            'JwtService.php', 'EmailOtpService.php', 'TextMeBotOtpService.php', 'WhatsAppOtpService.php',
        ], true)) {
            return 'IDENTITY';
        }
        if (in_array($rel, ['Services/Staff/EmployeeAuthService.php', 'Services/Staff/StaffJwtService.php'], true)) {
            return 'IDENTITY';
        }
        if (str_starts_with($rel, 'Repositories/') && in_array(basename($rel), [
            'UserRepository.php', 'OtpRepository.php', 'PasswordResetRepository.php',
            'VerificationRepository.php', 'PhotoRepository.php', 'ProfileRepository.php',
        ], true)) {
            return 'IDENTITY';
        }

        // ---- CONSOLE --------------------------------------------------------
        foreach (['Services/Admin/', 'Services/Complaint/'] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return 'CONSOLE';
            }
        }
        if (str_starts_with($rel, 'Services/Staff/')) {
            return 'CONSOLE';
        }
        if (str_starts_with($rel, 'Repositories/') && in_array(basename($rel), [
            'ComplaintRepository.php', 'EmployeeRepository.php',
        ], true)) {
            return 'CONSOLE';
        }

        // ---- controllers and handlers --------------------------------------
        if (str_starts_with($rel, 'Http/Controllers/')) {
            $base = basename($rel);

            if (in_array($base, self::IDENTITY_CONTROLLERS, true) || in_array($base, self::IDENTITY_HANDLERS, true)) {
                return 'IDENTITY';
            }
            if (str_starts_with($rel, 'Http/Controllers/API/Support/') && str_contains($base, 'Ride')) {
                return 'RIDE';
            }
            if (in_array($base, self::RIDE_CONTROLLERS, true)) {
                return 'RIDE';
            }
            if (in_array($base, self::MONEY_CONTROLLERS, true)) {
                return 'MONEY';
            }
            if (in_array($base, self::COMM_CONTROLLERS, true)) {
                return 'COMM';
            }
            if ($base === 'ComplaintController.php') {
                return 'CONSOLE';
            }
            if (str_starts_with($base, 'Admin') || str_starts_with($base, 'Staff')) {
                return 'CONSOLE';
            }
        }

        return 'UNASSIGNED';
    }

    /**
     * Every concrete class declared under app/, mapped to its FQCN and its context.
     *
     * @return array{0: array<string, string>, 1: array<string, string[]>}
     */
    private function declaredClasses(): array
    {
        $classContext = [];
        $shortIndex = [];

        foreach ($this->appFiles() as $rel => $path) {
            $context = $this->contextOf($rel);
            $namespace = $this->namespaceOf((string) file_get_contents($path));

            foreach ($this->declaredNames((string) file_get_contents($path)) as $short) {
                $fqcn = ($namespace !== '' ? $namespace.'\\' : '').$short;
                $classContext[$fqcn] = $context;
                $shortIndex[$short][] = $fqcn;
            }
        }

        return [$classContext, $shortIndex];
    }

    /** @return array<string, string> relative path => absolute path */
    private function appFiles(): array
    {
        $app = app_path();
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($app) + 1));
            $files[$rel] = $file->getPathname();
        }
        ksort($files);

        return $files;
    }

    private function namespaceOf(string $src): string
    {
        if (preg_match('/^\s*namespace\s+([^;{]+)\s*[;{]/m', $src, $m) === 1) {
            return trim($m[1], " \t\n\r\\");
        }

        return '';
    }

    /** @return string[] short class/interface/trait/enum names declared in the file */
    private function declaredNames(string $src): array
    {
        $tokens = token_get_all($src);
        $count = count($tokens);
        $names = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }
            // `Foo::class` is T_CLASS preceded by T_DOUBLE_COLON; skip it.
            if ($this->previousMeaningful($tokens, $index) === T_DOUBLE_COLON) {
                continue;
            }
            for ($j = $index + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $names[] = $tokens[$j][1];
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                break;
            }
        }

        return $names;
    }

    private function previousMeaningful(array $tokens, int $index): ?int
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }

            return is_array($tokens[$i]) ? $tokens[$i][0] : null;
        }

        return null;
    }

    /**
     * The concrete class FQCNs a file references, resolved from its own use table,
     * its namespace, and a unique-short-name fallback.
     *
     * @param  array<string, string>  $classContext
     * @param  array<string, string[]>  $shortIndex
     * @return string[]
     */
    private function references(string $src, string $namespace, array $classContext, array $shortIndex): array
    {
        $tokens = token_get_all($src);
        $count = count($tokens);
        $uses = [];
        $depth = 0;
        $references = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                }

                continue;
            }

            if ($token[0] === T_USE && $depth === 0) {
                [$statement, $i] = $this->readUseStatement($tokens, $i);
                foreach ($this->parseUseStatement($statement) as $alias => $fqcn) {
                    $uses[$alias] = $fqcn;
                }

                continue;
            }

            if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                continue;
            }

            [$name, $i] = $this->readName($tokens, $i);
            if ($name === '') {
                continue;
            }

            $fqcn = $this->resolveName($name, $namespace, $uses, $classContext, $shortIndex);
            if ($fqcn !== null) {
                $references[$fqcn] = true;
            }
        }

        return array_keys($references);
    }

    /** @return array{0: string, 1: int} */
    private function readUseStatement(array $tokens, int $start): array
    {
        $buffer = '';
        $i = $start + 1;
        $count = count($tokens);

        while ($i < $count && $tokens[$i] !== ';') {
            $buffer .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            $i++;
        }

        return [trim((string) preg_replace('/\s+/', ' ', $buffer)), $i];
    }

    /** @return array<string, string> alias => FQCN (without a leading backslash) */
    private function parseUseStatement(string $statement): array
    {
        $parsed = [];

        if (str_contains($statement, '{')) {
            [$prefix, $rest] = explode('{', $statement, 2);
            $prefix = rtrim(trim($prefix), '\\');
            foreach (explode(',', rtrim($rest, '}')) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $pieces = preg_split('/\s+as\s+/i', $part);
                $fqcn = $prefix.'\\'.trim($pieces[0], '\\');
                $alias = isset($pieces[1]) ? trim($pieces[1]) : basename(str_replace('\\', '/', $pieces[0]));
                $parsed[$alias] = ltrim($fqcn, '\\');
            }

            return $parsed;
        }

        $pieces = preg_split('/\s+as\s+/i', $statement);
        $fqcn = ltrim(trim($pieces[0]), '\\');
        if ($fqcn !== '') {
            $alias = isset($pieces[1]) ? trim($pieces[1]) : basename(str_replace('\\', '/', $fqcn));
            $parsed[$alias] = $fqcn;
        }

        return $parsed;
    }

    /** @return array{0: string, 1: int} */
    private function readName(array $tokens, int $start): array
    {
        $name = '';
        $i = $start;
        $count = count($tokens);

        while ($i < $count) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [
                T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE,
            ], true)) {
                $name .= $token[1];
                $i++;

                continue;
            }
            break;
        }

        return [$name, $i - 1];
    }

    /**
     * @param  array<string, string>  $uses
     * @param  array<string, string>  $classContext
     * @param  array<string, string[]>  $shortIndex
     */
    private function resolveName(string $name, string $namespace, array $uses, array $classContext, array $shortIndex): ?string
    {
        if (str_starts_with($name, '\\')) {
            $fqcn = ltrim($name, '\\');

            return isset($classContext[$fqcn]) ? $fqcn : null;
        }

        $first = explode('\\', $name)[0];

        if (str_contains($name, '\\')) {
            if (isset($uses[$first])) {
                $fqcn = $uses[$first].substr($name, strlen($first));

                return isset($classContext[$fqcn]) ? $fqcn : null;
            }

            $fqcn = ($namespace !== '' ? $namespace.'\\' : '').$name;

            return isset($classContext[$fqcn]) ? $fqcn : null;
        }

        if (isset($uses[$name])) {
            return isset($classContext[$uses[$name]]) ? $uses[$name] : null;
        }
        if (isset($shortIndex[$name]) && count($shortIndex[$name]) === 1) {
            return $shortIndex[$name][0];
        }
        if ($namespace !== '' && isset($classContext[$namespace.'\\'.$name])) {
            return $namespace.'\\'.$name;
        }

        return null;
    }

    /**
     * @return array<string, array<string, string[]>> FROM => TO => "file :: FQCN"
     */
    private function scan(): array
    {
        if (self::$scanCache !== null) {
            return self::$scanCache;
        }

        [$classContext, $shortIndex] = $this->declaredClasses();
        $matrix = [];
        foreach (self::CONTEXTS as $from) {
            foreach (self::CONTEXTS as $to) {
                $matrix[$from][$to] = [];
            }
        }

        foreach ($this->appFiles() as $rel => $path) {
            $from = $this->contextOf($rel);
            if ($from === 'SHARED' || $from === 'UNASSIGNED') {
                continue;
            }

            $src = (string) file_get_contents($path);
            foreach ($this->references($src, $this->namespaceOf($src), $classContext, $shortIndex) as $fqcn) {
                $to = $classContext[$fqcn];
                if ($to === 'SHARED' || $to === 'UNASSIGNED' || $to === $from) {
                    continue;
                }
                $matrix[$from][$to][] = $rel.' :: '.$fqcn;
            }
        }

        return self::$scanCache = $matrix;
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function pairProvider(): array
    {
        $out = [];
        foreach (self::BASELINES as $pair => $baseline) {
            $out[$pair] = [$pair, $baseline];
        }

        return $out;
    }

    /**
     * @dataProvider pairProvider
     */
    public function test_a_cross_context_edge_never_grows_beyond_its_baseline(string $pair, int $baseline): void
    {
        [$from, $to] = explode('_to_', $pair);
        $found = array_values(array_unique($this->scan()[$from][$to] ?? []));

        // IMPROVEMENT DETECTED: counts below baseline must be claimed by editing BASELINES,
        // so the ratchet can never loosen silently.
        $this->assertGreaterThanOrEqual(
            $baseline,
            count($found),
            "Pair '$pair': baseline is $baseline but only ".count($found).' edges remain — '
                .'lower the number in BASELINES to claim the improvement. Found: '.implode(', ', $found)
        );

        $this->assertLessThanOrEqual(
            $baseline,
            count($found),
            "Pair '$pair' (max $baseline): new cross-context import — ".implode(', ', $found)
                .'. Talk to the other context through an App\\Interfaces port (see '
                .'docs/audit/App future audit review r2.md section 233); do not raise the baseline.'
        );
    }
}
