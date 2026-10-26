<?php

namespace App\Filament\Resources\Perfiles\RelationManagers;

use App\Enums\TipoFeedback;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Lo que le gusta y lo que no le gusta a este perfil. */
class FeedbackRelationManager extends RelationManager
{
    protected static string $relationship = 'feedback';

    protected static ?string $title = 'Gustos';

    protected static ?string $modelLabel = 'gusto';

    protected static ?string $pluralModelLabel = 'gustos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tipo')->label('Tipo')->options(TipoFeedback::class)->required()->default(TipoFeedback::Gusta),
                TextInput::make('elemento')->label('Qué')->required()->maxLength(255)
                    ->placeholder('Un ingrediente, un plato o una categoría: coliflor, pescado azul, guisos…'),
                Textarea::make('nota')->label('Nota')->rows(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('elemento')
            ->columns([
                TextColumn::make('tipo')->label('Tipo')->badge()->sortable(),
                TextColumn::make('elemento')->label('Qué')->searchable(),
                TextColumn::make('nota')->label('Nota')->limit(60)->toggleable(),
            ])
            ->filters([SelectFilter::make('tipo')->options(TipoFeedback::class)])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('Sin gustos registrados');
    }
}
