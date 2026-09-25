<?php

declare(strict_types=1);

namespace App\Providers;

use App\A2A\LongTaskExecutor;
use App\A2A\TckAgentCard;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Mounts the TCK agent at the site root and the long-task agent at /long,
 * both on the queued runner (the executor runs on `php artisan queue:work`).
 * No middleware group: no sessions or CSRF, like an API.
 */
final class A2ATckServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        require_once app_path('A2A/TckAgentExecutor.php');

        Route::a2a('/', agentCard: TckAgentCard::class, executor: \TckAgentExecutor::class, agent: 'tck', runner: 'queued');
        Route::a2a('/long', agentCard: TckAgentCard::class, executor: LongTaskExecutor::class, agent: 'long', runner: 'queued', wellKnown: false);
    }
}
