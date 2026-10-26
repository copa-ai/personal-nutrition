<?php

namespace App\Filament\Resources\Hogares\Pages;

use App\Filament\Resources\Hogares\HogarResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHogares extends ListRecords
{
    protected static string $resource = HogarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
