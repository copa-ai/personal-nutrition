<?php

namespace App\Filament\Resources\Tiempos\Pages;

use App\Filament\Resources\Tiempos\TiempoResource;
use App\Models\Hogar;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTiempo extends CreateRecord
{
    protected static string $resource = TiempoResource::class;

    /** Los tiempos son siempre del hogar de la aplicación. */
    protected function handleRecordCreation(array $data): Model
    {
        return Hogar::unico()->disponibilidad()->create($data);
    }
}
