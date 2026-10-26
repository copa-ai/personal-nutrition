<?php

namespace App\Filament\Resources\Existencias\Pages;

use App\Filament\Resources\Existencias\ExistenciaResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExistencia extends EditRecord
{
    protected static string $resource = ExistenciaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
