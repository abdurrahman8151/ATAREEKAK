<?php

namespace App\Modules\Console\Providers;

use App\Modules\Communication\Contracts\SupportDeskDirectory;
use App\Modules\Console\Contracts\BanLifterInterface;
use App\Modules\Console\Contracts\ComplaintDirectory;
use App\Modules\Console\Contracts\ComplaintRepositoryInterface;
use App\Modules\Console\Contracts\EmployeeManagementInterface;
use App\Modules\Console\Contracts\EmployeeRepositoryInterface;
use App\Modules\Console\Contracts\EmployeeTokenVersion;
use App\Modules\Console\Contracts\SupportAgentRoutingInterface;
use App\Modules\Console\Repositories\ComplaintRepository;
use App\Modules\Console\Repositories\EmployeeRepository;
use App\Modules\Console\Services\AdminAuthService;
use App\Modules\Console\Services\AdminDriverService;
use App\Modules\Console\Services\AdminExportService;
use App\Modules\Console\Services\AdminReportService;
use App\Modules\Console\Services\AdminTripService;
use App\Modules\Console\Services\AdminUserService;
use App\Modules\Console\Services\AdminWalletService;
use App\Modules\Console\Services\BanService;
use App\Modules\Console\Services\ComplaintDirectoryService;
use App\Modules\Console\Services\ComplaintRecorderService;
use App\Modules\Console\Services\ComplaintService;
use App\Modules\Console\Services\EmployeeManagementService;
use App\Modules\Console\Services\EmployeeTokenVersionBumper;
use App\Modules\Console\Services\ReviewModerationService;
use App\Modules\Console\Services\StaffComplaintService;
use App\Modules\Console\Services\SupportAgentRoutingService;
use App\Modules\Console\Services\SupportDeskDirectoryService;
use App\Modules\Ride\Contracts\ComplaintRecorder;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Console (back-office) module's container bindings, moved verbatim out of
 * `App\Support\ContainerBindings`. Lifetimes unchanged.
 *
 * `EmployeeAuthService` and `StaffJwtService` are NOT here even though they live in
 * `app/Modules/Identity/Services`: batch 233's context map puts the staff AUTHENTICATION path in the identity
 * context and the staff BUSINESS surface (employee management, moderation, complaints, support
 * routing) in this one, and the port placement in phase 1(b) follows that map.
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Admin back office.
        // Stage 2 C2: Identity invalidates staff sessions through this, instead of writing employees.
        // Stage 2 C2: Ride opens its no-show support complaint through this instead of `Complaint::create()`.
        // Stage 3 A1: the contract is declared by its CONSUMER (Ride) and implemented here, which is what
        // removes the `Ride -> Console` edge and the `Console <-> Ride` cycle. Same class, same binding.
        $this->app->bind(ComplaintRecorder::class, ComplaintRecorderService::class);
        $this->app->bind(EmployeeTokenVersion::class, EmployeeTokenVersionBumper::class);
        $this->app->singleton(AdminAuthService::class);
        $this->app->singleton(AdminWalletService::class);
        $this->app->singleton(AdminReportService::class);
        $this->app->singleton(AdminExportService::class);
        $this->app->singleton(AdminTripService::class);
        $this->app->singleton(AdminDriverService::class);
        $this->app->singleton(AdminUserService::class);

        // Staff business surface and complaints.
        $this->app->singleton(EmployeeManagementService::class);
        $this->app->singleton(ReviewModerationService::class);
        $this->app->singleton(StaffComplaintService::class);
        $this->app->singleton(ComplaintService::class);

        // Ports.
        $this->app->bind(EmployeeRepositoryInterface::class, EmployeeRepository::class);
        $this->app->bind(ComplaintRepositoryInterface::class, ComplaintRepository::class);
        // Stage 2 C2: Identity's passenger-profile panels read complaints through this, never the model.
        $this->app->bind(ComplaintDirectory::class, ComplaintDirectoryService::class);
        $this->app->bind(EmployeeManagementInterface::class, EmployeeManagementService::class);
        $this->app->bind(SupportAgentRoutingInterface::class, SupportAgentRoutingService::class);
        // Stage 4 D-1: the support-desk answer Communication declares, implemented by the module that owns
        // `employees`. One call replaces the two ports the contact endpoint used to need.
        $this->app->bind(SupportDeskDirectory::class, SupportDeskDirectoryService::class);
        $this->app->bind(BanLifterInterface::class, BanService::class);
    }
}
