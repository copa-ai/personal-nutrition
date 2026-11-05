<?php

use App\Http\Controllers\Interno\ArchivosController;
use App\Http\Controllers\Interno\ChatController;
use App\Http\Controllers\Interno\MensajesController;
use App\Http\Controllers\Interno\SecretosController;
use Illuminate\Support\Facades\Route;

/*
 * API interna del chat (docs/chat-api-interna.md). Se registra en bootstrap/app.php con el prefijo interno/chat y el middleware
 * ChatInterno, sin el grupo «web» (ni sesion ni CSRF). Solo escucha en 127.0.0.1:8001.
 */
Route::get('salud', [ChatController::class, 'salud']);
Route::get('capacidades', [ChatController::class, 'capacidades']);
Route::get('conversacion', [ChatController::class, 'conversacion']);

Route::post('mensajes', [MensajesController::class, 'store']);
Route::get('mensajes', [MensajesController::class, 'index']);
Route::get('mensajes/{id}', [MensajesController::class, 'show']);
Route::post('mensajes/{id}/fijar', [MensajesController::class, 'fijar']);
Route::post('mensajes/{id}/cancelar', [MensajesController::class, 'cancelar']);
Route::post('sincronizar', [MensajesController::class, 'sincronizar']);

Route::post('archivos/consultar', [ArchivosController::class, 'consultar']);
Route::put('archivos/{hex}', [ArchivosController::class, 'subir']);
Route::get('archivos/{hex}', [ArchivosController::class, 'descargar']);

Route::post('secretos', [SecretosController::class, 'store']);
