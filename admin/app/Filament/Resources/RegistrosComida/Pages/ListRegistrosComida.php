<?php

namespace App\Filament\Resources\RegistrosComida\Pages;

use App\Filament\Resources\RegistrosComida\RegistroComidaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegistrosComida extends ListRecords
{
    protected static string $resource = RegistroComidaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
