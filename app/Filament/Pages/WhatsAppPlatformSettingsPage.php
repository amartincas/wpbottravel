<?php

namespace App\Filament\Pages;

use App\Models\WhatsAppPlatformSetting;
use App\Services\WhatsAppService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class WhatsAppPlatformSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Configuración WhatsApp/IA';
    protected static ?string $title = 'Configuración WhatsApp/IA';
    protected static ?string $slug = 'whatsapp-platform-settings';

    protected string $view = 'filament.pages.whatsapp-platform-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->is_super_admin;
    }

    public function mount(): void
    {
        $this->form->fill(WhatsAppPlatformSetting::current()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(2)
            ->components([
                TextInput::make('wa_verify_token')
                    ->label('Verify Token')
                    ->helperText('Úsalo en Meta Developers Console > Webhook Settings.')
                    ->columnSpanFull(),

                Placeholder::make('webhook_url')
                    ->label('Webhook URL')
                    ->content(fn () => rtrim(config('app.url'), '/') . '/api/whatsapp/webhook')
                    ->helperText('Una sola URL para todos los operadores — cópiala en Meta Developers Console > Webhook Settings > Callback URL.')
                    ->columnSpanFull(),

                TextInput::make('wa_phone_number_id')
                    ->label('Phone Number ID')
                    ->required()
                    ->helperText('Meta > Settings > Phone Numbers.')
                    ->columnSpan(1),

                TextInput::make('wa_business_account_id')
                    ->label('WABA ID (Business Account)')
                    ->helperText('Meta > Settings > Business Accounts.')
                    ->columnSpan(1),

                TextInput::make('wa_access_token')
                    ->label('Access Token')
                    ->password()
                    ->revealable()
                    ->required()
                    ->helperText('Meta Developers > My Apps > System User Access Tokens.')
                    ->columnSpanFull(),

                Select::make('ai_provider')
                    ->label('AI Provider')
                    ->options([
                        'openai' => 'ChatGPT (OpenAI)',
                        'grok' => 'Grok',
                        'gemini' => 'Gemini',
                    ])
                    ->required()
                    ->reactive()
                    ->columnSpan(1),

                Select::make('ai_model')
                    ->label('AI Model')
                    ->options(function (Get $get) {
                        $provider = $get('ai_provider');
                        if (!$provider) {
                            return [];
                        }

                        $models = config("ai.models.{$provider}", []);
                        return array_combine($models, $models);
                    })
                    ->required()
                    ->reactive()
                    ->columnSpan(1),

                TextInput::make('ai_api_key')
                    ->label('AI API Key')
                    ->password()
                    ->revealable()
                    ->required()
                    ->helperText('Encriptada en la base de datos.')
                    ->columnSpanFull(),
            ]);
    }

    public function save(): void
    {
        $settings = WhatsAppPlatformSetting::current();
        $settings->update($this->form->getState());

        Notification::make()
            ->title('Configuración global guardada')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Test Connection')
                ->icon('heroicon-m-signal')
                ->action(function () {
                    $settings = WhatsAppPlatformSetting::current();

                    $result = WhatsAppService::testConnection(
                        $settings->wa_phone_number_id,
                        $settings->wa_access_token
                    );

                    Notification::make()
                        ->title($result['success'] ? '¡Conexión Exitosa!' : 'Connection Failed')
                        ->body($result['message'])
                        ->status($result['success'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }
}
