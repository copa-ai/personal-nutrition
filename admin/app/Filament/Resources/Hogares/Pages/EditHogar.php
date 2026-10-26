<?php

namespace App\Filament\Resources\Hogares\Pages;

use App\Filament\Resources\Hogares\HogarResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHogar extends EditRecord
{
    protected static string $resource = HogarResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
