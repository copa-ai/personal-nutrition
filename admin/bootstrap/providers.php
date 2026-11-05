<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuditoriaServiceProvider;
use App\Providers\ChatServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AuditoriaServiceProvider::class,
    ChatServiceProvider::class,
    AdminPanelProvider::class,
];
