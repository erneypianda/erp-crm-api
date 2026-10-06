<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Listeners\ProcessStockReservation;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Registro explícito. El descubrimiento automático está desactivado
     * en bootstrap/app.php para no encolar este listener dos veces.
     *
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        OrderPlaced::class => [
            ProcessStockReservation::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
