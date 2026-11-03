<?php

namespace App\Support;

use App\Mail\OtpMailer;
use App\Modules\Identity\Contracts\OtpMailInterface;
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
 * What is left here is the SHARED KERNEL's own wiring: the file-upload service, which every context
 * may use and which therefore belongs to no module, and the one binding whose implementation is a
 * kernel class (`App\Mail\OtpMailer`) serving a module port (`Identity\Contracts\OtpMailInterface`).
 * Stage 1.5 item 4 moved that second binding here from the Communication provider, which had
 * imported the Identity port to declare it - one module naming another module's Contracts, which
 * closed a Communication <-> Identity cycle. The kernel is allowed to name module Contracts, so
 * this is its natural home. `AppServiceProvider::register()` still calls `register()`, so both
 * bindings are registered exactly where they always were; only the module bindings moved, and each
 * port still resolves to the same instance it resolved to before.
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
        $this->registerKernelPortImplementations();
    }

    /**
     * Services that belong to no module: every context may inject them.
     */
    private function registerSharedServices(): void
    {
        $this->app->singleton(FileUploadService::class);
    }

    /**
     * Bindings whose implementation is a kernel class and whose port belongs to a module. The kernel
     * may name module Contracts (that is what ports are for), so the kernel is where a kernel class is
     * wired to one - and that keeps the module providers from naming each other's Contracts.
     *
     * `bind()`, not `singleton()`: exactly the lifetime this binding had before the move.
     */
    private function registerKernelPortImplementations(): void
    {
        $this->app->bind(OtpMailInterface::class, OtpMailer::class);
    }
}
