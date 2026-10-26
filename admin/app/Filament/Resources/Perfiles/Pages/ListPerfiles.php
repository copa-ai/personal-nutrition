<?php

namespace App\Filament\Resources\Perfiles\Pages;

use App\Filament\Resources\Perfiles\PerfilResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPerfiles extends ListRecords
{
    protected static string $resource = PerfilResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
