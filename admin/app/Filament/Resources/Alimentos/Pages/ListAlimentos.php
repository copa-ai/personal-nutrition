<?php

namespace App\Filament\Resources\Alimentos\Pages;

use App\Filament\Resources\Alimentos\AlimentoResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlimentos extends ListRecords
{
    protected static string $resource = AlimentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
