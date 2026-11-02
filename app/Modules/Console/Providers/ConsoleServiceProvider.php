<?php

namespace App\Modules\Console\Providers;

use App\Modules\Console\Contracts\ComplaintRepositoryInterface;
use App\Modules\Console\Contracts\EmployeeManagementInterface;
use App\Modules\Console\Contracts\EmployeeRepositoryInterface;
use App\Modules\Console\Contracts\SupportAgentRoutingInterface;
use App\Repositories\ComplaintRepository;
use App\Repositories\EmployeeRepository;
use App\Services\Admin\AdminAuthService;
use App\Services\Admin\AdminDriverService;
use App\Services\Admin\AdminExportService;
use App\Services\Admin\AdminReportService;
use App\Services\Admin\AdminTripService;
use App\Services\Admin\AdminUserService;
use App\Services\Admin\AdminWalletService;
use App\Services\Complaint\ComplaintService;
use App\Services\Staff\EmployeeManagementService;
use App\Services\Staff\ReviewModerationService;
use App\Services\Staff\StaffComplaintService;
use App\Services\Staff\SupportAgentRoutingService;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Console (back-office) module's container bindings, moved verbatim out of
 * `App\Support\ContainerBindings`. Lifetimes unchanged.
 *
 * `EmployeeAuthService` and `StaffJwtService` are NOT here even though they live in
 * `App\Services\Staff`: batch 233's context map puts the staff AUTHENTICATION path in the identity
 * context and the staff BUSINESS surface (employee management, moderation, complaints, support
 * routing) in this one, and the port placement in phase 1(b) follows that map.
 */
final class ConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Admin back office.
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
        $this->app->bind(EmployeeManagementInterface::class, EmployeeManagementService::class);
        $this->app->bind(SupportAgentRoutingInterface::class, SupportAgentRoutingService::class);
    }
}
