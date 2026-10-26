<?php

namespace App\Filament\Resources\RegistrosComida\Pages;

use App\Filament\Resources\RegistrosComida\RegistroComidaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRegistroComida extends EditRecord
{
    protected static string $resource = RegistroComidaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
