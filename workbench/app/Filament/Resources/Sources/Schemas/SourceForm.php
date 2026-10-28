<?php

namespace App\Filament\Resources\Sources\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('url')
                    ->url()
                    ->required(),
                TextInput::make('feed_url')
                    ->url()
                    ->required(),
                TextInput::make('title'),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('category'),
                TextInput::make('item_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('last_imported_at'),
            ]);
    }
}
