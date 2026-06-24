<?php

namespace App\Livewire;

use App\Models\Lead;
use App\Models\Conversation;
use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Filament\Notifications\Notification;
use Livewire\Component;

class WhatsAppChatCenter extends Component
{
    // Public properties that Livewire maintains between cycles
    public $conversations = [];
    public $messages = [];
    public ?string $selectedPhone = null;
    public ?int $selectedConversationId = null;
    public bool $botActive = true;
    public string $newMessage = ''; // For text input field
    public ?int $filterStoreId = null; // For superuser store filtering
    public $stores = []; // Available stores for superuser filter
    public ?int $selectedLeadId = null; // For JS modal
    public $whatsappTemplates = [];    // List templates

    public function mount()
    {
        $this->loadConversations();
        
        // If superuser, load all stores for the filter dropdown
        if (Auth::user()?->is_super_admin) {
            $this->stores = Store::all();
        }
    }

    public function render()
    {
        // Reload conversations on each render to prevent them from disappearing
        $this->loadConversations();

        return view('livewire.whats-app-chat-center');
    }

    /**
     * Load all conversations (unique customer phones) and messages for selected conversation
     * CRITICAL: Must filter by store_id for multi-tenant safety
     * EXCEPTION: Superusers see all stores (or filtered by $filterStoreId)
     * ORDERS by most recent message for each conversation (newest first)
     */
    public function loadConversations()
    {
        try {
            $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
            
            // Determine which store(s) to query
            if ($isSuperAdmin && $this->filterStoreId) {
                // Superuser with filter applied
                $storeId = $this->filterStoreId;
            } elseif (!$isSuperAdmin) {
                // Regular user - must use their store_id
                $storeId = Auth::user()?->store_id;
                
                if (!$storeId) {
                    Log::warning('loadConversations: store_id is null for non-admin user', [
                        'user_id' => Auth::id(),
                        'user_email' => Auth::user()?->email,
                    ]);
                    $this->conversations = [];
                    return;
                }
            } else {
                // Superuser without filter - see all
                $storeId = null;
            }

            // 1. Load conversations with the date of the last message
            $query = WhatsAppMessage::query();
            
            // Apply store filter if needed
            if ($storeId) {
                $query->where('store_id', $storeId);
            }
            
            $this->conversations = $query
                ->select('customer_phone')
                ->selectRaw('MAX(created_at) as last_message_at')
                ->groupBy('customer_phone')
                ->orderBy('last_message_at', 'DESC')
                ->get();

            Log::debug('loadConversations: Retrieved conversations', [
                'is_super_admin' => $isSuperAdmin,
                'store_id' => $storeId,
                'filter_store_id' => $this->filterStoreId,
                'count' => count($this->conversations),
            ]);

            // 2. If a phone is selected, load its messages
            if ($this->selectedPhone) {
                $messageQuery = WhatsAppMessage::query()
                    ->where('customer_phone', (string) $this->selectedPhone);
                
                // Apply store filter if needed
                if ($storeId) {
                    $messageQuery->where('store_id', $storeId);
                }
                
                $queryMessages = $messageQuery
                    ->orderBy('created_at', 'asc')
                    ->get();
                
                // Only update and dispatch scroll if the count changed
                if (count($queryMessages) !== count($this->messages)) {
                    $this->messages = $queryMessages;
                    $this->dispatch('scroll-down');
                    
                    Log::debug('loadConversations: Messages updated', [
                        'customer_phone' => $this->selectedPhone,
                        'message_count' => count($queryMessages),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('loadConversations Error: ' . $e->getMessage(), [
                'user_id' => Auth::id(),
                'is_super_admin' => Auth::user()?->is_super_admin,
                'store_id' => Auth::user()?->store_id,
                'filter_store_id' => $this->filterStoreId,
                'selected_phone' => $this->selectedPhone,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Select a conversation and load its details
     * Fetches bot_active status from leads table (works for both control records and marketing leads)
     * For superusers, determine the store_id of the conversation
     */
    public function selectConversation(string $phone): void
    {
        $this->selectedPhone = (string) $phone;
        $this->loadConversations(); // Force message load

        $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
        
        if ($isSuperAdmin && !$this->filterStoreId) {
            // Superuser without filter - need to find the store this conversation belongs to
            $firstMessage = WhatsAppMessage::query()
                ->where('customer_phone', (string) $phone)
                ->first();
            
            if ($firstMessage) {
                $storeId = $firstMessage->store_id;
            }
        } elseif ($this->filterStoreId) {
            // Using filtered store
            $storeId = $this->filterStoreId;
        } else {
            // Regular user
            $storeId = Auth::user()?->store_id;
        }

        if ($storeId ?? false) {
            // Check if a control record or marketing lead exists for this phone
            $lead = Lead::query()
                ->where('store_id', $storeId)
                ->where('customer_phone', (string) $phone)
                ->first();

            // Store selected conversation id if one exists
            $conversation = Conversation::query()
                ->where('store_id', $storeId)
                ->where('customer_phone', (string) $phone)
                ->first();

            $this->selectedConversationId = $conversation?->id;
            // Fetch bot_active status, default to true if no record exists yet
            $this->botActive = $lead?->bot_active ?? true;
            // Store selected lead ID for JS modal (can be null if no lead record exists yet)
            $this->selectedLeadId = $lead?->id;
            // Load WhatsApp templates for this store (for manual template sending)
            $this->whatsappTemplates = \App\Models\WhatsAppTemplate::where('store_id', $storeId)->get();
        } else {
            $this->selectedConversationId = null;
        }

        // Important for JavaScript
        $this->loadConversations();
        $this->dispatch('scroll-down');
    }

    /**
     * Send manual message to customer
     * Saves to database and sends via WhatsAppService
     * 
     * IMPORTANT: Human operator messages are saved with role='assistant' (not 'user').
     * This ensures that when the bot re-enables, it can see and maintain context
     * from everything the human operator said during the intervention period.
     * 
     * Message flow:
     * 1. Operator types message → Saved to DB with role='assistant'
     * 2. Message sent to customer via WhatsApp API
     * 3. Customer replies → ProcessWhatsAppMessage job fetches history
     * 4. History includes operator's previous messages (role='assistant')
     * 5. AI engine receives full context including what operator said
     * 6. Bot continues conversation with full context awareness
     */
    public function sendMessage(): void
    {
        if (empty(trim($this->newMessage)) || !$this->selectedPhone) {
            return;
        }

        $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
        
        // Determine store_id for this message
        if ($isSuperAdmin && !$this->filterStoreId) {
            // Superuser without filter - find the store this conversation belongs to
            $firstMessage = WhatsAppMessage::query()
                ->where('customer_phone', $this->selectedPhone)
                ->first();
            
            if ($firstMessage) {
                $storeId = $firstMessage->store_id;
            } else {
                Log::warning('sendMessage: Cannot determine store for superuser without existing messages');
                return;
            }
        } elseif ($this->filterStoreId) {
            // Using filtered store
            $storeId = $this->filterStoreId;
        } else {
            // Regular user
            $storeId = Auth::user()?->store_id;
        }
        
        if (!$storeId) {
            Log::warning('sendMessage: No store_id available');
            return;
        }

        try {
            // 1. Save message to database (sent by operator)
            // CRITICAL: role='assistant' ensures bot maintains context when re-enabled
            $chatMessage = WhatsAppMessage::create([
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'content' => $this->newMessage,
                'role' => 'assistant', // ← Operator message treated as 'assistant' for AI context
            ]);

            // 2. Send via WhatsApp API
            $store = Store::find($storeId);
            if ($store) {
                WhatsAppService::sendMessage($this->selectedPhone, $this->newMessage, $store, $chatMessage->id);
                
                Log::info('Manual message sent via WhatsApp', [
                    'store_id' => $storeId,
                    'customer_phone' => $this->selectedPhone,
                    'message_length' => strlen($this->newMessage),
                    'role' => 'assistant',  // Log that this will be in AI context
                ]);
            } else {
                Log::warning('sendMessage: Store not found', ['store_id' => $storeId]);
            }

            // 3. Clear input and refresh
            $this->newMessage = '';
            $this->loadConversations();
            
            // 4. Scroll down
            $this->dispatch('scroll-down');
        } catch (\Exception $e) {
            Log::error('sendMessage: Failed to send message', [
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle bot_active toggle changes
     * Creates bot control records in the leads table to decouple bot control from marketing leads
     * 
     * IMPORTANT: The "leads" table serves TWO purposes:
     * 1. Bot Control Records: Any phone where operator toggled bot on/off (customer_name = 'Unknown')
     * 2. Marketing Leads: Completed conversations with customer data (customer_name = actual name)
     * 
     * This decoupling allows operators to disable bots for ANY conversation,
     * even if the customer hasn't provided their data yet (not a "lead" in marketing sense).
     */
    public function updatedBotActive(bool $value): void
    {
        if (!$this->selectedPhone || !Auth::user()?->store_id) {
            Log::warning('updatedBotActive: Missing phone or store_id');
            return;
        }

        try {
            $storeId = Auth::user()->store_id;
            
            // IMPORTANT: Cast value to boolean and log exactly what we're saving
            $botActiveValue = (bool) $value;
            
            Log::info('updatedBotActive: Toggle switch changed', [
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'raw_value' => $value,
                'cast_value' => $botActiveValue,
                'value_type' => gettype($value),
            ]);
            
            // Create or update control record in leads table
            // If this phone doesn't have a record yet, this creates a "bot control record"
            // identified by customer_name = 'Unknown'
            $lead = Lead::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'customer_phone' => (string) $this->selectedPhone,
                ],
                [
                    'customer_name' => 'Unknown', // Identifies as control record, not a marketing lead
                    'summary' => 'WhatsApp Chat Center',
                    'bot_active' => $botActiveValue,  // EXPLICIT: Pass the cast boolean value
                ]
            );

            Log::info('Bot Active status toggled (control record)', [
                'store_id' => $storeId,
                'customer_phone' => $this->selectedPhone,
                'bot_active_saved' => $lead->bot_active,
                'database_value' => (bool) $lead->bot_active,
            ]);
        } catch (\Exception $e) {
            Log::error('updatedBotActive: Failed to update status', [
                'customer_phone' => $this->selectedPhone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle store filter change for superusers
     * Clears selected phone and reloads conversations with new store filter
     */
    public function updatedFilterStoreId($value): void
    {
        if (!Auth::user()?->is_super_admin) {
            return;
        }

        $this->filterStoreId = (int) $value ?: null;
        $this->selectedPhone = null;
        $this->messages = [];
        $this->loadConversations();
        
        Log::info('Store filter changed by superuser', [
            'user_id' => Auth::id(),
            'filter_store_id' => $this->filterStoreId,
        ]);
    }

    public function sendTemplate(int $templateId, array $customValues = [], ?string $externalPhone = null): void
    {
        $isSuperAdmin = Auth::user()?->is_super_admin ?? false;
        $targetPhone = $this->selectedPhone;
        $isExternalSend = false;

        if (!empty($externalPhone)) {
            $normalized = preg_replace('/\s+/', '', $externalPhone);
            if (!preg_match('/^\+?\d{6,15}$/', $normalized)) {
                Log::warning('sendTemplate: invalid external phone format', ['external_phone' => $externalPhone]);
                $this->dispatch('template-sent-error');
                return;
            }

            $targetPhone = ltrim($normalized, '+');
            $isExternalSend = true;
        }

        if ($isSuperAdmin && !$this->filterStoreId) {
            $storeId = $targetPhone
                ? WhatsAppMessage::query()->where('customer_phone', $targetPhone)->value('store_id')
                : null;
        } elseif ($this->filterStoreId) {
            $storeId = $this->filterStoreId;
        } else {
            $storeId = Auth::user()?->store_id;
        }

        if (!$storeId) {
            Log::warning('sendTemplate: store_id could not be resolved', ['selected_phone' => $this->selectedPhone, 'external_phone' => $externalPhone]);
            $this->dispatch('template-sent-error');
            return;
        }

        $store = Store::find($storeId);
        if (!$store) {
            $this->dispatch('template-sent-error');
            return;
        }

        $template = \App\Models\WhatsAppTemplate::where('id', $templateId)
            ->where('store_id', $storeId)
            ->firstOrFail();

        if ($template->requires_phone_input && !$isExternalSend) {
            Log::warning('sendTemplate: template requires external phone but none was provided', ['template_id' => $templateId]);
            $this->dispatch('template-sent-error');
            return;
        }

        $targetPhone = $targetPhone ? (string) $targetPhone : null;
        if (!$targetPhone) {
            Log::warning('sendTemplate: target phone is missing', ['template_id' => $templateId]);
            $this->dispatch('template-sent-error');
            return;
        }

        $lead = Lead::where('customer_phone', $targetPhone)
            ->where('store_id', $storeId)
            ->first();

        if ($isExternalSend) {
            $lead = Lead::firstOrCreate(
                ['store_id' => $storeId, 'customer_phone' => $targetPhone],
                ['customer_name' => 'Unknown', 'summary' => 'Proactive message', 'bot_active' => false]
            );
        }

        $parametersMap = $template->parameters_map ?? [];
        $leadData = [
            'customer_name'  => $lead?->customer_name  ?? '',
            'customer_phone' => $lead?->customer_phone ?? '',
            'product_service_name'   => $lead?->product_service_name   ?? '',
        ];

        Log::info('DEBUG sendTemplate', [
            'selected_phone' => $this->selectedPhone,
            'external_phone' => $externalPhone,
            'target_phone' => $targetPhone,
            'customValues' => $customValues,
            'parametersMap' => $parametersMap,
            'leadData' => $leadData,
        ]);

        $resolvedValues = [];
        foreach ($parametersMap as $position => $fieldKey) {
            $idx = (int) $position - 1;
            $override = $customValues[$idx] ?? null;
            $resolvedValues[$idx] = ($override !== '' && $override !== null)
                ? $override
                : ($leadData[$fieldKey] ?? '');
        }
        ksort($resolvedValues);

        Log::info('DEBUG resolvedValues', ['resolvedValues' => array_values($resolvedValues)]);

        $conversation = Conversation::firstOrCreate([
            'store_id' => $storeId,
            'customer_phone' => $targetPhone,
        ], [
            'last_session_at' => now(),
        ]);

        $this->selectedConversationId = $conversation->id;
        $this->selectedPhone = $targetPhone;

        $chatMessage = WhatsAppMessage::create([
            'store_id'       => $storeId,
            'customer_phone' => $targetPhone,
            'content'        => $renderedBody = $template->body_preview,
            'role'           => 'assistant',
        ]);

        foreach (array_values($resolvedValues) as $index => $value) {
            $placeholder = '{{'. ($index + 1). '}}';
            $renderedBody = str_replace($placeholder, $value, $renderedBody);
        }

        $chatMessage->update(['content' => $renderedBody]);

        $sent = WhatsAppService::sendTemplateMessage(
            to:           $targetPhone,
            templateName: $template->name,
            languageCode: $template->language,
            variables:    array_values($resolvedValues),
            store:        $store,
            messageId:    $chatMessage->id,
        );

        if ($sent && $template->is_reengagement && $lead) {
            $lead->update(['status' => 'waiting_customer', 'bot_active' => true]);
        }

        if ($sent) {
            Notification::make()
                ->title('Plantilla enviada')
                ->body('El mensaje fue enviado correctamente al cliente.')
                ->success()
                ->send();

            if ($template->is_reengagement && $lead) {
                $lead->update(['status' => 'waiting_customer', 'bot_active' => true]);
            }

            $this->selectConversation($targetPhone);
        } else {
            Notification::make()
                ->title('Error al enviar')
                ->body('Meta no pudo entregar la plantilla. Revisa los logs.')
                ->danger()
                ->send();
        }
    }

    // Este método se llamará desde el script del modal tras un envío exitoso
    #[On('template-sent')] 
    public function handleTemplateSent()
    {
        $this->loadConversations();
        $this->dispatch('scroll-down');
        Log::info('Chat refreshed after template send', ['phone' => $this->selectedPhone]);
    }

}