<?php

namespace App\Filament\Resources\Tiempos\Pages;

use App\Filament\Resources\Tiempos\TiempoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTiempos extends ListRecords
{
    protected static string $resource = TiempoResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
