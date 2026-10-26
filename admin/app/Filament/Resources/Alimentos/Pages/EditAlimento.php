<?php

namespace App\Filament\Resources\Alimentos\Pages;

use App\Filament\Resources\Alimentos\AlimentoResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAlimento extends EditRecord
{
    protected static string $resource = AlimentoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
