<?php

use App\Http\Controllers\Interno\CardsController;
use Illuminate\Support\Facades\Route;

/*
 * API interna de la lista de la compra (interfaz «cards», docs/chat-api-interna.md). Se registra en bootstrap/app.php con el prefijo
 * interno/cards y el mismo middleware que el chat (ChatInterno: mismo Bearer CHAT_SECRETO, mismo interruptor), sin el grupo «web».
 */
Route::get('salud', [CardsController::class, 'salud']);
Route::get('mazo', [CardsController::class, 'mazo']);
Route::get('eventos', [CardsController::class, 'eventos']);
Route::post('acciones', [CardsController::class, 'accion']);
Route::get('archivos/{hex}', [CardsController::class, 'archivo']);
