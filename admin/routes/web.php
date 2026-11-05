<?php

// El panel de Filament vive en la raíz (AdminPanelProvider -> path('')); aquí solo van las rutas auxiliares del panel.

use App\Http\Controllers\FotoController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

// Fotos del chat y de lo comido (chat_archivos). El hash es SHA3-256 en hexadecimal; con otra forma, 404.
Route::get('/fotos/{hash}', FotoController::class)
    ->where('hash', '[0-9a-f]{64}')
    ->middleware(['web', Authenticate::class])
    ->name('fotos');
