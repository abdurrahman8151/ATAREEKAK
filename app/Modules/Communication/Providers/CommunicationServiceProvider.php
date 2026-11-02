<?php

namespace App\Modules\Communication\Providers;

use App\Mail\OtpMailer;
use App\Modules\Communication\Contracts\ChatMessageHandlerInterface;
use App\Modules\Communication\Contracts\ChatRepositoryInterface;
use App\Modules\Communication\Contracts\NotificationPort;
use App\Modules\Communication\Contracts\PushNotifierInterface;
use App\Modules\Communication\Repositories\ChatRepository;
use App\Modules\Communication\Services\ChatMessageHandler;
use App\Modules\Communication\Services\FcmSenderService;
use App\Modules\Communication\Services\NotificationService;
use App\Modules\Communication\Services\PushNotificationService;
use App\Modules\Communication\Services\PushTokenManager;
use App\Modules\Identity\Contracts\OtpMailInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Stage 1, phase 1(a): the Communication module's container bindings.
 *
 * Moved verbatim out of `App\Support\ContainerBindings` (batch 227/233's catalogue), which used to
 * register them in one contiguous body. Every LIFETIME is unchanged - the same `singleton()` and
 * `bind()` calls on the same keys - and every port is bound to the same class it was bound to
 * before, so instance identity and construction are identical. Only the registration ORDER moved,
 * from the original catalogue order to the module order; because each port's implementer lives in
 * the same module as the port (that is how the ports were placed in phase 1(b)), no binding can
 * depend on one registered by another module, so the order carries no meaning.
 *
 * Registered from `config/app.php`, like every other provider.
 */
final class CommunicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Push notifications.
        $this->app->singleton(PushTokenManager::class);
        $this->app->singleton(FcmSenderService::class);
        $this->app->singleton(PushNotificationService::class);

        // Chat.
        $this->app->singleton(ChatMessageHandler::class);

        // The in-app notification writer.
        $this->app->singleton(NotificationService::class);

        // Ports. Each is bound to the singleton above, so a port-typed constructor receives the
        // very same instance it received before the split.
        $this->app->bind(NotificationPort::class, NotificationService::class);
        $this->app->bind(PushNotifierInterface::class, PushNotificationService::class);
        $this->app->bind(ChatMessageHandlerInterface::class, ChatMessageHandler::class);
        $this->app->bind(ChatRepositoryInterface::class, ChatRepository::class);
        // The kernel OTP mailer, bound here rather than in the Identity provider: mail delivery is
        // this context's transport (the boundary map puts the kernel `Mail/` directory with
        // Communication), so the wiring never has to reach across a context to name it.
        $this->app->bind(OtpMailInterface::class, OtpMailer::class);
    }
}
