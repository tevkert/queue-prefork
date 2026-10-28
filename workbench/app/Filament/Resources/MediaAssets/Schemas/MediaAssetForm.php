<?php

namespace App\Filament\Resources\MediaAssets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MediaAssetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('post_id')
                    ->relationship('post', 'title'),
                TextInput::make('path')
                    ->required(),
                TextInput::make('width')
                    ->required()
                    ->numeric(),
                TextInput::make('height')
                    ->required()
                    ->numeric(),
                TextInput::make('bytes')
                    ->required()
                    ->numeric(),
                TextInput::make('mime')
                    ->required(),
                TextInput::make('blurhash'),
            ]);
    }
}
