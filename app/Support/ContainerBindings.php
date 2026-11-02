<?php

namespace App\Support;

use App\Services\File\FileUploadService;
use Illuminate\Contracts\Foundation\Application;

/**
 * The container binding catalogue that used to live inline in `AppServiceProvider::register()`.
 *
 * STAGE 1, PHASE 1(a): the catalogue is now SPLIT BY MODULE. Every binding that belongs to one of
 * the five bounded contexts moved into that module's own provider, verbatim, with its lifetime
 * unchanged:
 *
 *   Communication  app/Modules/Communication/Providers/CommunicationServiceProvider.php
 *   Money          app/Modules/Money/Providers/MoneyServiceProvider.php
 *   Ride           app/Modules/Ride/Providers/RideServiceProvider.php
 *   Identity       app/Modules/Identity/Providers/IdentityServiceProvider.php
 *   Console        app/Modules/Console/Providers/ConsoleServiceProvider.php
 *
 * They are registered from `config/app.php`, after `AppServiceProvider`, in that order.
 *
 * What is left here is the SHARED KERNEL's own binding - the file-upload service, which every context
 * may use and which therefore belongs to no module. `AppServiceProvider::register()` still calls
 * `register()`, so the shared binding is registered exactly where it always was; only the module
 * bindings moved, and each port still resolves to the same singleton it resolved to before.
 *
 * The old note about "container binding order is byte-for-byte unchanged" described the previous
 * refactor, which moved the body without regrouping it. This one regroups by module, so the order of
 * registrations does change. It cannot matter: every port is bound to an implementation in the SAME
 * module (phase 1(b) placed the ports there), so no binding depends on one that another module
 * registers. The full suite, which includes `tests/Unit/Providers/AppServiceProviderTest.php` and the
 * bare-container boundary tests, is the evidence.
 */
class ContainerBindings
{
    public function __construct(private readonly Application $app) {}

    /**
     * Register the shared kernel's bindings.
     */
    public function register(): void
    {
        $this->registerSharedServices();
    }

    /**
     * Services that belong to no module: every context may inject them.
     */
    private function registerSharedServices(): void
    {
        $this->app->singleton(FileUploadService::class);
    }
}
