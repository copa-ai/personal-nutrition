<?php

namespace App\Filament\Resources\Tiempos\Pages;

use App\Filament\Resources\Tiempos\TiempoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTiempo extends EditRecord
{
    protected static string $resource = TiempoResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
