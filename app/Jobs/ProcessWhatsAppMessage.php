<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\WhatsAppMessage;
use App\Models\Product;
use App\Models\Lead;
use App\Factories\AIServiceFactory;
use App\Services\AI\OpenAIService;
use App\Services\WhatsAppService;
use App\Services\Inventory\ProductFinderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Store $store,
        public string $from,
        public ?string $messageBody,
        public ?string $phoneId,
        public ?string $messageType = null,
        public ?string $mediaId = null,
        public ?int $productContext = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //sleep(30);

        try {
            // Verify store object is properly serialized
            if (!$this->store || !$this->store->id) {
                Log::error('CRITICAL: Store object is null or missing ID in job', [
                    'has_store' => $this->store !== null,
                    'store_id' => $this->store->id ?? 'NULL',
                ]);
                throw new \Exception('Store object is not properly initialized in job');
            }

            // ===== HUMAN INTERVENTION MODE CHECK (FIRST THING) =====
            // CRITICAL: This check must happen BEFORE ANY AI PROCESSING
            // 
            // IMPORTANT: The "leads" table serves TWO purposes:
            // 1. Bot Control Records: Any phone where operator toggled bot on/off
            // 2. Marketing Leads: Completed conversations with customer data
            //
            // This check decouples bot control from lead status:
            // - If NO lead record exists → Bot continues (default behavior)
            // - If lead exists AND bot_active = false → Bot disabled for human intervention
            // - If lead exists AND bot_active = true → Bot continues
            
            $botDisabled = Lead::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->where('bot_active', false)
                ->exists();

            if ($botDisabled) {
                Log::warning('BOT_DISABLED: Skipping AI response', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'message' => $this->messageBody,
                ]);

                // Still save the user message for reference
                WhatsAppMessage::create([
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'role' => 'user',
                    'content' => $this->messageBody,
                ]);

                return;
            }
            // ===== END HUMAN INTERVENTION MODE CHECK =====

            Log::info("JOB_START: Processing WhatsApp message", [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'customer_phone' => $this->from,
                'message' => $this->messageBody,
                'message_type' => $this->messageType,
                'media_id' => $this->mediaId,
            ]);

            if (in_array($this->messageType, ['audio', 'voice'], true) && empty($this->messageBody)) {
                Log::info('Audio/voice message detected, starting transcription workflow', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'message_type' => $this->messageType,
                    'media_id' => $this->mediaId,
                ]);

                if (!$this->mediaId) {
                    Log::warning('Audio message missing media_id, cannot transcribe', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'message_type' => $this->messageType,
                    ]);
                    return;
                }

                $localPath = WhatsAppService::downloadMedia($this->mediaId, $this->store);
                if (!$localPath) {
                    Log::error('Failed to download audio media for transcription', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'media_id' => $this->mediaId,
                    ]);
                    return;
                }

                try {
                    $openAi = new OpenAIService($this->store->ai_api_key, 'whisper-1');
                    $transcribedText = $openAi->transcribeAudio(Storage::disk('local')->path($localPath));
                    $this->messageBody = "🎤 [AUDIO]: " . trim($transcribedText);

                    if (empty($this->messageBody)) {
                        throw new \Exception('Transcription returned empty text');
                    }

                    Log::info('Audio transcription completed successfully', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'media_id' => $this->mediaId,
                        'transcription_preview' => substr($this->messageBody, 0, 200),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Audio transcription failed', [
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'media_id' => $this->mediaId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    $transcriptionRecoveryMessage = WhatsAppMessage::create([
                        'store_id' => $this->store->id,
                        'customer_phone' => $this->from,
                        'role' => 'assistant',
                        'content' => 'No pude transcribir tu audio. Por favor intenta de nuevo o escríbeme tu pregunta.',
                    ]);

                    WhatsAppService::sendMessage(
                        $this->from,
                        $transcriptionRecoveryMessage->content,
                        $this->store,
                        $transcriptionRecoveryMessage->id
                    );

                    return;
                } finally {
                    if (Storage::disk('local')->exists($localPath)) {
                        Storage::disk('local')->delete($localPath);
                    }
                }
            }

            // Retrieve product context at the beginning
            $productContext = $this->getProductContextWithTypes();

            // Fetch the last 10 messages for this customer
            // IMPORTANT: This includes BOTH AI responses AND human operator messages
            // Both are stored with role='assistant' in the whats_app_messages table:
            // - AI-generated responses: role='assistant', created by this job
            // - Human operator responses: role='assistant', created by sendMessage() in WhatsAppChatCenter
            //
            // This ensures the AI maintains full conversation context even after human intervention.
            $rawHistory = WhatsAppMessage::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'role', 'content', 'created_at']);

            $history = $rawHistory
                ->reverse()
                ->map(function (WhatsAppMessage $msg) {
                    // OpenAI solo acepta: user, assistant, system, tool, function
                    // Mapeamos advisor y system (BD) a roles válidos para la IA.
                    // El contenido se prefija para que la IA entienda el contexto.
                    switch ($msg->role) {
                        case 'advisor':
                            // Mensaje del asesor — tratarlo como usuario externo
                            return [
                                'role'    => 'user',
                                'content' => '[Asesor]: ' . $msg->content,
                            ];
                        case 'system':
                            // Notificación automática del sistema — tratarla como respuesta del bot
                            return [
                                'role'    => 'assistant',
                                'content' => $msg->content,
                            ];
                        default:
                            // user / assistant — pasar tal cual
                            return [
                                'role'    => $msg->role,
                                'content' => $msg->content,
                            ];
                    }
                })
                ->toArray();

            // DEBUG: Log the conversation history being sent to AI
            Log::debug('CONVERSATION_HISTORY: Fetched for AI context', [
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'total_messages_in_history' => count($history),
                'messages' => array_map(function ($msg) {
                    return [
                        'role' => $msg['role'],
                        'preview' => substr($msg['content'], 0, 50) . (strlen($msg['content']) > 50 ? '...' : ''),
                    ];
                }, $history),
            ]);

            // ===== SYSTEM PROMPT PIPELINE =====
            // Pure concatenation of database values following: Store Instructions → Product Context → Metadata
            
            // 1. Load store system prompt (must be set by store admin)
            $systemPrompt = trim($this->store->system_prompt ?? '');
            
            // 2. Validate store prompt exists and log if missing
            if (empty($systemPrompt)) {
                Log::warning('PROMPT_VALIDATION: Store system_prompt is empty', [
                    'store_id' => $this->store->id,
                    'store_name' => $this->store->name,
                ]);
                // Minimal fallback: does not replace user's instructions, just signals empty
                $systemPrompt = 'You are an assistant.';
            }
            
            // 3. Append product context (formatted with headers, no hardcoded rules)
            if ($productContext && !empty($productContext['context'])) {
                $systemPrompt .= "\n\n### PRODUCT CATALOG DATA:\n" . $productContext['context'];
            } else {
                // Log when no product context available
                Log::warning('CONTEXT_VALIDATION: No product context retrieved', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'message' => substr($this->messageBody, 0, 100),
                ]);
            }
            
            // 4. Contexto de la reserva activa del cliente
            // Se consulta en cada mensaje para que la IA siempre tenga
            // el estado más reciente cuando el cliente pregunte por su reserva.
            $activeLead = \App\Models\Lead::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->latest()
                ->first();

            if ($activeLead) {
                $statusLabels = [
                    \App\Models\Lead::STATUS_PENDIENTE => 'Pendiente — todavía estamos reuniendo los datos de tu reserva',
                    \App\Models\Lead::STATUS_DERIVADO  => 'Derivado — un asesor va a contactarte para confirmar el pago y los detalles',
                    \App\Models\Lead::STATUS_CERRADO   => 'Cerrado — tu reserva quedó confirmada',
                    \App\Models\Lead::STATUS_CANCELADO => 'Cancelado',
                ];

                $statusLabel = $statusLabels[$activeLead->status] ?? $activeLead->status;

                $systemPrompt .= "\n\n### RESERVA ACTIVA DEL CLIENTE:\n";
                $systemPrompt .= "Reserva #" . $activeLead->id . "\n";
                $systemPrompt .= "Tour/Servicio: " . ($activeLead->product_service_name ?? 'N/A') . "\n";
                $systemPrompt .= "Total: " . ($activeLead->total_amount ? '$' . number_format((float) preg_replace('/[^0-9.]/', '', $activeLead->total_amount), 0, ',', '.') : 'N/A') . "\n";
                $systemPrompt .= "Punto de encuentro: " . ($activeLead->meeting_point ?? 'N/A') . "\n";
                $systemPrompt .= "Fecha del viaje: " . ($activeLead->tour_date ?? 'N/A') . "\n";
                $systemPrompt .= "Estado actual: " . $statusLabel . "\n";
                $systemPrompt .= "Última actualización: " . $activeLead->updated_at->format('Y-m-d H:i') . "\n";
                $systemPrompt .= "\nSi el cliente pregunta por su reserva, responde ÚNICAMENTE con el estado registrado arriba. Nunca inventes estados ni fechas de contacto del asesor.\n";

                Log::info('ACTIVE_LEAD_CONTEXT: Inyectado en prompt', [
                    'store_id' => $this->store->id,
                    'lead_id'  => $activeLead->id,
                    'status'   => $activeLead->status,
                ]);
            }

            // 5. Append system metadata (timestamps and completion signal)
            $systemPrompt .= "\n\n### SYSTEM METADATA:\n";
            $systemPrompt .= "Current Date/Time: " . now()->format('Y-m-d H:i:s') . "\n";
            $systemPrompt .= "Lead Completion Signal: [LEAD_COMPLETE]\n";
            $systemPrompt .= "Before showing the final booking summary, ALWAYS ask the customer if there's anything else the advisor should know about their trip (optional — e.g. reduced mobility, dietary restrictions, traveling with children, medical conditions, special occasions). Make clear it's optional and they can say no. Wait for their answer (even if it's 'no' or 'nada') before showing the summary — do not skip this question.\n";
            $systemPrompt .= "ONLY append [LEAD_COMPLETE] when the customer has EXPLICITLY confirmed they want to move forward with the booking and are ready to be connected with an advisor to close payment (sí, confirmo, correcto, acepto, listo, dale, de acuerdo) AFTER you have asked about additional comments AND shown the full booking summary. Never emit this token before showing the summary. Never emit this token more than once per conversation unless the customer explicitly starts a NEW and SEPARATE booking after the previous one was already confirmed.\n";

            // Get the configured AI service for this store
            $aiEngine = AIServiceFactory::make($this->store);

            // Debug: Log the final prompt being sent to OpenAI
            Log::info("PROMPT_PIPELINE: System prompt constructed", [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'has_system_prompt' => !empty($this->store->system_prompt),
                'has_product_context' => $productContext !== null && !empty($productContext['context']),
                'prompt_length' => strlen($systemPrompt),
                'full_prompt' => $systemPrompt,
            ]);

            // Get AI response with chat history
            $aiResponse = $aiEngine->getResponse($this->messageBody, $systemPrompt, $history);

            $hasLeadToken = strpos($aiResponse, '[LEAD_COMPLETE]') !== false;

            $messageToSend = $aiResponse;

            // Extract lead data from the conversation for both explicit and fallback detection
            $leadData = $this->extractLeadDataWithAI($history, $aiResponse);

            // Acumular en caché lo extraído en este turno — igual que la ubicación
            // pendiente, así el pedido no depende de que la ventana de extracción
            // (limitada a los últimos mensajes) siga viendo el producto/extras
            // varios turnos después de que el cliente los mencionó.
            $leadData = $this->mergeAndCacheOrderDraft($leadData);

            $shouldCreateLead = $this->shouldCreateLeadFromResponse($aiResponse, $leadData, $hasLeadToken);

            if ($shouldCreateLead) {
                if ($hasLeadToken) {
                    $messageToSend = preg_replace('/\[LEAD_COMPLETE\]/', '', $aiResponse);
                    $messageToSend = trim($messageToSend);
                }

                Log::info('Lead data extracted', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'extracted_data' => $leadData,
                    'has_lead_token' => $hasLeadToken,
                ]);

                // Resolver snapshot de precios desde la BD
                $snapshot = $this->resolveOrderSnapshot($leadData);

                $lead = Lead::create([
                    'store_id'              => $this->store->id,
                    'customer_phone'        => $this->from,
                    'customer_name'         => $leadData['customer_name'] ?? null,
                    'meeting_point'         => $leadData['meeting_point'] ?? null,
                    'origin_city'           => $leadData['origin_city'] ?? null,
                    'tour_date'             => $leadData['tour_date'] ?? null,
                    'travelers_count'       => $leadData['travelers_count'] ?? null,
                    'product_service_name'  => $leadData['product_service_name'] ?? null,
                    'product_name'          => $snapshot['product_name'],
                    'product_sale_price'    => $snapshot['product_sale_price'],
                    'product_cost_price'    => $snapshot['product_cost_price'],
                    'extras_detail'         => $snapshot['extras_detail'],
                    'extras_sale_total'     => $snapshot['extras_sale_total'],
                    'extras_cost_total'     => $snapshot['extras_cost_total'],
                    'comments'              => $leadData['comments'] ?? null,
                    'total_amount'          => $leadData['total_amount'] ?? null,
                    'summary'               => $messageToSend,
                    'is_processed'          => false,
                ]);

                // Reserva finalizada — limpiar el acumulado para que la próxima
                // reserva de este cliente empiece desde cero.
                Cache::forget("order_draft:{$this->store->id}:{$this->from}");

                Log::info('Lead created from WhatsApp conversation', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'customer_name' => $leadData['customer_name'] ?? null,
                    'product_service_name' => $leadData['product_service_name'] ?? null,
                    'completion_method' => $hasLeadToken ? 'explicit_token' : 'heuristic_fallback',
                ]);

                // Notificación automática al asesor para que cierre el pago/reserva
                $this->notifyAdvisor($lead, $leadData);

                // CRM: Registrar pedido en CustomerLead
                $this->registerOrderInCrm($lead, $leadData);
            }

            // Process AI response to extract and send images
            // This also cleans the message by removing [IMG: id] tags
            $messageToSend = WhatsAppService::processAIResponse(
                $messageToSend,
                $this->store,
                $this->from
            );

            // Save user message to database
            WhatsAppMessage::create([
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'role' => 'user',
                'content' => $this->messageBody,
            ]);

            // Save AI response to database
            $aiResponseMessage = WhatsAppMessage::create([
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'role' => 'assistant',
                'content' => $aiResponse,
            ]);

            // Send AI response back to customer (without the [LEAD_COMPLETE] tag)
            WhatsAppService::sendMessage($this->from, $messageToSend, $this->store, $aiResponseMessage->id);

            Log::info('WhatsApp message processed successfully by job', [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'customer_phone' => $this->from,
                'message_body' => $this->messageBody,
            ]);
        } catch (\Exception $e) {
            // Log the specific error with full context
            Log::error('Job: AI Provider Error for store: ' . $this->store->name, [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'customer_phone' => $this->from,
                'provider' => $this->store->ai_provider,
                'error_message' => $e->getMessage(),
                'user_message' => $this->messageBody,
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Send graceful fallback message to customer only on API failures
            $fallbackMessage = 'Lo siento, estoy experimentando dificultades técnicas. Por favor intenta más tarde.';
            try {
                $fallbackMessageRow = WhatsAppMessage::create([
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'role' => 'assistant',
                    'content' => $fallbackMessage,
                ]);

                WhatsAppService::sendMessage($this->from, $fallbackMessageRow->content, $this->store, $fallbackMessageRow->id);
            } catch (\Exception $sendError) {
                Log::error('Failed to send fallback message', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'error' => $sendError->getMessage(),
                ]);
            }

            // Re-throw to mark job as failed after max retries
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    /**
     * Envía notificación de nuevo lead al WhatsApp del asesor.
     * Usa plantilla Meta (HSM) porque el asesor no inicia conversación.
     *
     * Variables de la plantilla Meta "nuevo_lead":
     *   {{1}} nombre del asesor/agencia
     *   {{2}} lead_id
     *   {{3}} customer_name
     *   {{4}} customer_phone
     *   {{5}} origin_city
     *   {{6}} product_service_name (destino/tour confirmado)
     *   {{7}} travelers_count
     *   {{8}} tour_date
     *   {{9}} comments
    /**
     * Resuelve el snapshot de precios del pedido desde la BD.
     * Garantiza inmutabilidad histórica de precios.
     */
    /**
     * Registra el pedido confirmado en el CRM (CustomerLead).
     * Actualiza nombre, primer producto, métricas y status.
     */
    private function registerOrderInCrm(\App\Models\Lead $lead, array $leadData): void
    {
        try {
            $customerLead = \App\Models\CustomerLead::where('store_id', $this->store->id)
                ->where('customer_phone', $this->from)
                ->first();

            if (!$customerLead) {
                Log::warning('CRM: CustomerLead no encontrado para registrar pedido', [
                    'store_id' => $this->store->id,
                    'phone'    => $this->from,
                ]);
                return;
            }

            // Actualizar nombre si la IA lo extrajo y el CRM no lo tiene
            \App\Services\CustomerLeadService::updateCustomerName(
                $customerLead,
                $leadData['customer_name'] ?? null
            );

            // Capturar primer producto de interés
            if ($lead->product_name) {
                $product = \App\Models\Product::where('store_id', $this->store->id)
                    ->where('name', 'like', '%' . $lead->product_name . '%')
                    ->first();

                if ($product) {
                    \App\Services\CustomerLeadService::captureFirstProduct(
                        $customerLead,
                        $product->id
                    );
                }
            }

            // Registrar el pedido — actualiza CLV, total_orders, status
            \App\Services\CustomerLeadService::registerOrder($customerLead, $lead);

        } catch (\Exception $e) {
            Log::error('CRM: Error al registrar pedido', [
                'store_id' => $this->store->id,
                'lead_id'  => $lead->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function resolveOrderSnapshot(array $leadData): array
    {
        $snapshot = [
            'product_name'       => null,
            'product_sale_price' => null,
            'product_cost_price' => null,
            'extras_detail'      => null,
            'extras_sale_total'  => 0,
            'extras_cost_total'  => 0,
        ];

        $productName = $leadData['product_service_name'] ?? null;
        if (!$productName) {
            return $snapshot;
        }

        $product = \App\Models\Product::where('store_id', $this->store->id)
            ->where('name', 'like', '%' . $productName . '%')
            ->with('availableExtras')
            ->first();

        if (!$product) {
            return $snapshot;
        }

        $snapshot['product_name']       = $product->name;
        $snapshot['product_sale_price'] = $product->price;
        $snapshot['product_cost_price'] = $product->cost_price;

        $orderExtras = $leadData['order_extras'] ?? null;
        if (!$orderExtras || $product->availableExtras->isEmpty()) {
            return $snapshot;
        }

        $requestedExtras = array_map('trim', explode(',', $orderExtras));
        $extrasDetail    = [];
        $extrasSaleTotal = 0;
        $extrasCostTotal = 0;

        foreach ($requestedExtras as $requestedName) {
            $extra = $product->availableExtras
                ->first(fn ($e) => stripos($e->name, $requestedName) !== false
                    || stripos($requestedName, $e->name) !== false);

            if ($extra) {
                $extrasDetail[]  = [
                    'name'       => $extra->name,
                    'sale_price' => (float) $extra->sale_price,
                    'cost_price' => (float) $extra->cost_price,
                ];
                $extrasSaleTotal += (float) $extra->sale_price;
                $extrasCostTotal += (float) $extra->cost_price;
                Log::info('SNAPSHOT: Extra resuelto', ['extra' => $extra->name, 'sale_price' => $extra->sale_price]);
            } else {
                Log::warning('SNAPSHOT: Extra no encontrado en BD', ['requested' => $requestedName]);
            }
        }

        $snapshot['extras_detail']     = !empty($extrasDetail) ? $extrasDetail : null;
        $snapshot['extras_sale_total'] = $extrasSaleTotal;
        $snapshot['extras_cost_total'] = $extrasCostTotal;

        Log::info('SNAPSHOT: Resuelto', [
            'product'      => $snapshot['product_name'],
            'extras_count' => count($extrasDetail),
            'extras_total' => $extrasSaleTotal,
        ]);

        return $snapshot;
    }

    private function notifyAdvisor(Lead $lead, array $leadData): void
    {
        if (!$this->store->hasAdvisorNotification()) {
            Log::info('ADVISOR_NOTIFY: Skipped — advisor_whatsapp or advisor_notification_template not configured', [
                'store_id' => $this->store->id,
            ]);
            return;
        }

        try {
            // Nombre limpio del tour/destino confirmado, con extras si aplica
            $productForTemplate = $lead->product_name
                ?? $leadData['product_service_name']
                ?? 'N/A';

            if (!empty($leadData['order_extras'])) {
                $productForTemplate .= ' + ' . $leadData['order_extras'];
            }

            // Variables en el orden de la plantilla Meta "nuevo_lead":
            // {{1}} nombre del asesor/agencia  {{2}} lead_id  {{3}} customer_name
            // {{4}} customer_phone  {{5}} origin_city  {{6}} destino (tour)
            // {{7}} travelers_count  {{8}} tour_date  {{9}} comments
            $variables = [
                $this->store->name,
                (string) $lead->id,
                $leadData['customer_name'] ?? 'N/A',
                $this->from,
                $leadData['origin_city'] ?? 'N/A',
                $productForTemplate,
                $lead->travelers_count ?: 'N/A',
                $lead->tour_date ?: 'N/A',
                $leadData['comments'] ?? 'N/A',
            ];

            // Registrar el envío para poder rastrear su entrega (delivery_status
            // se actualiza cuando llega el status callback de Meta con el wamid).
            $notifyMessage = WhatsAppMessage::create([
                'store_id' => $this->store->id,
                'customer_phone' => $this->store->advisor_whatsapp,
                'role' => 'system',
                'content' => "Notificación de reserva #{$lead->id} enviada al asesor ({$this->store->advisor_notification_template})",
            ]);

            $sent = WhatsAppService::sendTemplateMessage(
                to: $this->store->advisor_whatsapp,
                templateName: $this->store->advisor_notification_template,
                languageCode: $this->store->advisor_notification_template_lang ?? 'es_CO',
                variables: $variables,
                store: $this->store,
                messageId: $notifyMessage->id,
            );

            if ($sent) {
                Log::info('ADVISOR_NOTIFY: Reserva enviada al asesor', [
                    'store_id' => $this->store->id,
                    'lead_id'  => $lead->id,
                    'advisor'  => $this->store->advisor_whatsapp,
                    'template' => $this->store->advisor_notification_template,
                ]);
            } else {
                Log::warning('ADVISOR_NOTIFY: Fallo al enviar reserva al asesor', [
                    'store_id' => $this->store->id,
                    'lead_id'  => $lead->id,
                    'advisor'  => $this->store->advisor_whatsapp,
                ]);
            }
        } catch (\Exception $e) {
            // No relanzamos: fallo en notificación NO debe cancelar el job principal
            Log::error('ADVISOR_NOTIFY: Excepción al notificar asesor', [
                'store_id' => $this->store->id,
                'lead_id'  => $lead->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Job ProcessWhatsAppMessage failed after max retries', [
            'store_id' => $this->store->id,
            'store_name' => $this->store->name,
            'customer_phone' => $this->from,
            'exception' => $exception->getMessage(),
        ]);
    }



    /**
     * Get product context information with type details for the message.
     * Returns array with context string and type flags.
     * Includes fallback logic to ensure products are found.
     *
     * @return array|null
     */
    private function getProductContextWithTypes(): ?array
    {
        try {
            Log::info("CONTEXT_RETRIEVAL: Starting product context retrieval", [
                'store_id' => $this->store->id,
                'store_name' => $this->store->name,
                'message' => $this->messageBody,
                'has_product_context_param' => $this->productContext !== null,
            ]);

            // If a specific product context was provided, fetch that product
            if ($this->productContext) {
                $product = Product::with('images')->find($this->productContext);
                if ($product) {
                    Log::info("CONTEXT_RETRIEVAL: Found product by ID", [
                        'product_id' => $this->productContext,
                        'product_name' => $product->name,
                    ]);

                    return [
                        'context' => $this->formatProductData($product),
                        'hasServices' => $product->type === 'service',
                        'hasProducts' => $product->type === 'product',
                    ];
                }
            }

            // Otherwise, search for products mentioned in the message
            Log::info("CONTEXT_RETRIEVAL: Searching for products by message", [
                'store_id' => $this->store->id,
                'message' => $this->messageBody,
            ]);

            $finder = new ProductFinderService();
            $result = $finder->findProductsWithTypes($this->messageBody, $this->store->id, 10);

            // Ensure images are loaded for the found products
            $result['products']->load('images');

            Log::info("CONTEXT_RETRIEVAL: Search result", [
                'products_count' => $result['products']->count(),
                'has_services' => $result['hasServices'],
                'has_products' => $result['hasProducts'],
                'context_preview' => substr($result['context'], 0, 150),
            ]);

            // If search returned nothing at all, force fetch full catalog
            if ($result['products']->isEmpty()) {
                Log::warning("CONTEXT_RETRIEVAL: Search returned no products, forcing full catalog fetch", [
                    'store_id' => $this->store->id,
                    'original_message' => $this->messageBody,
                ]);

                // Force fetch all products for this store (ProductFinderService already handles this fallback)
                // But let's add an explicit secondary fallback
                $allProducts = Product::where('store_id', $this->store->id)
                    ->with('images')
                    ->limit(10)
                    ->get(['id', 'name', 'price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

                Log::info("CONTEXT_RETRIEVAL: Explicit full catalog fetch", [
                    'store_id' => $this->store->id,
                    'products_found' => $allProducts->count(),
                ]);

                if ($allProducts->isEmpty()) {
                    Log::warning("CONTEXT_RETRIEVAL: No products exist in database for store", [
                        'store_id' => $this->store->id,
                    ]);

                    return null;
                }

                // Return the explicit full catalog
                $hasServices = $allProducts->where('type', 'service')->isNotEmpty();
                $hasProducts = $allProducts->where('type', 'product')->isNotEmpty();

                Log::info("CONTEXT_CONTENT: " . $this->formatProductsForContext($allProducts));

                return [
                    'context' => $this->formatProductsForContext($allProducts),
                    'hasServices' => $hasServices,
                    'hasProducts' => $hasProducts,
                    'products' => $allProducts,
                ];
            }

            Log::info("CONTEXT_CONTENT: " . ($result['context'] ?? 'EMPTY'));

            return [
                'context' => $result['context'],
                'hasServices' => $result['hasServices'],
                'hasProducts' => $result['hasProducts'],
                'products' => $result['products'],
            ];
        } catch (\Exception $e) {
            Log::warning('Product context retrieval failed', [
                'store_id' => $this->store->id,
                'product_id' => $this->productContext,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Format products list for context injection.
     * Pure data pipeline: validates fields exist, formats with headers, concatenates database values only.
     * NO hardcoded instructions or sales text - all content from database.
     */
    private function formatProductsForContext(Collection $products): string
    {
        if ($products->isEmpty()) {
            return "No products available.";
        }

        $formatted = "Available Offerings:\n\n";

        foreach ($products as $product) {
            // ===== PRODUCT IDENTITY =====
            $formatted .= "Product: " . ($product->name ?? 'Unknown') . "\n";
            $formatted .= "Type: " . ($product->type ?? 'unknown') . "\n";
            
            // ===== PRICING & AVAILABILITY =====
            $formatted .= "Price: $" . number_format($product->price ?? 0, 2) . "\n";
            
            if ($product->type === 'service') {
                $availability = ($product->stock ?? 0) === 1 ? 'Available' : 'Unavailable';
                $formatted .= "Availability: " . $availability . "\n";
            } else {
                $stock = $product->stock ?? 0;
                $formatted .= "Stock: " . $stock . " units\n";
            }
            
            // ===== DESCRIPTION =====
            if (!empty($product->description)) {
                $formatted .= "Description: " . $product->description . "\n";
            }
            
            // ===== IMAGES =====
            if ($product->images && $product->images->count() > 0) {
                $formatted .= "Images:\n";
                // Sort images: primary first, then by ID
                $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
                foreach ($sortedImages as $image) {
                    $formatted .= "- [IMG:{$image->id}] Product image\n";
                }
            } else {
                $formatted .= "Images: None\n";
            }
            
            // ===== DATABASE FIELDS FOR AI SALES & RULES =====
            // These are populated by store admin in database - passed through without modification
            
            if (!empty($product->ai_sales_strategy)) {
                $formatted .= "Sales Strategy: " . $product->ai_sales_strategy . "\n";
            }
            
            if (!empty($product->faq_context)) {
                $formatted .= "Rules & FAQ: " . $product->faq_context . "\n";
            }
            
            if (!empty($product->required_customer_info)) {
                $formatted .= "Required Data: " . $product->required_customer_info . "\n";
            } else {
                Log::debug('FIELD_VALIDATION: Product missing required_customer_info', [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                ]);
            }
            
            $formatted .= "\n---\n\n";
        }

        return $formatted;
    }

    /**
     * Get product context information for the message (legacy method).
     * If a specific product ID is provided, fetch that product's details.
     * Otherwise, search for product mentions in the user message.
     */
    private function getProductContext(): ?string
    {
        $contextData = $this->getProductContextWithTypes();
        return $contextData['context'] ?? null;
    }

    /**
     * Format a single product's data for context.
     * Pure data pipeline: validates fields, formats with headers, no hardcoded sales text.
     */
    private function formatProductData(Product $product): string
    {
        // ===== PRODUCT IDENTITY =====
        $formatted = "Product: " . ($product->name ?? 'Unknown') . "\n";
        $formatted .= "Type: " . ($product->type ?? 'unknown') . "\n";
        
        // ===== PRICING & AVAILABILITY =====
        $formatted .= "Price: $" . number_format($product->price ?? 0, 2) . "\n";
        
        if ($product->type === 'service') {
            $availability = ($product->stock ?? 0) === 1 ? 'Available' : 'Unavailable';
            $formatted .= "Availability: " . $availability . "\n";
        } else {
            $stock = $product->stock ?? 0;
            $formatted .= "Stock: " . $stock . " units\n";
        }
        
        // ===== DESCRIPTION =====
        if (!empty($product->description)) {
            $formatted .= "Description: " . $product->description . "\n";
        }
        
        // ===== IMAGES =====
        if ($product->images && $product->images->count() > 0) {
            $formatted .= "Images:\n";
            // Sort images: primary first, then by ID
            $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
            foreach ($sortedImages as $image) {
                $formatted .= "- [IMG:{$image->id}] Product image\n";
            }
        } else {
            $formatted .= "Images: None\n";
        }
        
        // ===== DATABASE FIELDS FOR AI SALES & RULES =====
        // Store admin manages these in database - passed through without modification
        
        if (!empty($product->ai_sales_strategy)) {
            $formatted .= "Sales Strategy: " . $product->ai_sales_strategy . "\n";
        }
        
        if (!empty($product->faq_context)) {
            $formatted .= "Rules & FAQ: " . $product->faq_context . "\n";
        }
        
        if (!empty($product->required_customer_info)) {
            $formatted .= "Required Data: " . $product->required_customer_info . "\n";
        } else {
            Log::debug('FIELD_VALIDATION: Product missing required_customer_info', [
                'product_id' => $product->id,
                'product_name' => $product->name,
            ]);
        }
        
        return $formatted;
    }

    /**
     * Extract lead data using AI with strict context validation.
     * Uses JSON extraction to ensure accurate product/service matching.
     * 
     * CRITICAL: This method prioritizes the CURRENT conversation context,
     * ignoring legacy values from earlier in history if the topic has changed.
     *
     * @param array $history Chat history
     * @param string $lastAiResponse The last AI response before [LEAD_COMPLETE]
     * @return array Extracted lead data
     */
    private function extractLeadDataWithAI(array $history, string $lastAiResponse): array
    {
        $leadData = [
            'customer_name' => null,
            'meeting_point' => null,
            'origin_city' => null,
            'tour_date' => null,
            'travelers_count' => null,
            'product_service_name' => null,
            'total_amount' => null,
            'order_extras' => null,
            'comments' => null,
        ];

        try {
            // Build extraction prompt focusing on CURRENT context
            $today = now()->format('Y-m-d (l)');
            $extractionPrompt = <<<PROMPT
You are a data extraction specialist. Extract customer information from the conversation.

Today's date is {$today}.

CRITICAL RULES FOR EXTRACTION:
1. Only extract the product/service the customer EXPLICITLY confirmed or requested in the MOST RECENT message
2. IGNORE any products mentioned earlier if the customer changed their mind or topic
3. The product/service must be mentioned in either:
   - The last customer message, OR
   - The last AI response (where you confirmed their request)
4. Do NOT use products from the beginning of the conversation if they were discussing a different service later
5. For tour_date: keep the customer's own wording/intent, don't force it into a rigid format. If they gave an exact date, write it clearly (e.g. "15 de agosto 2026"). If they gave a relative or open-ended reference (e.g. "after August 15", "next weekend", "sometime in September"), use today's date as reference to make it concrete where you reasonably can, but preserve the open-ended nature instead of inventing a single exact day (e.g. "después del 15 de agosto 2026", not a fabricated exact date). Only use null if the customer gave no date reference at all.
6. For travelers_count: capture it exactly as the customer described it, including any adult/children breakdown (e.g. "2 adultos y 3 niños", "4 personas"). Do NOT reduce it to a plain number if the customer gave more detail than that.

Return ONLY valid JSON (no markdown, no code blocks, no extra text):
{
  "customer_name": "extracted name or null",
  "meeting_point": "meeting point / pickup reference the customer gave, or null",
  "origin_city": "the traveler's city of origin (where they are traveling FROM), or null",
  "tour_date": "the travel date/timeframe per rule 5 above, as free text, or null",
  "travelers_count": "number/composition of travelers per rule 6 above, as free text, or null if not confirmed",
  "product_service_name": "CURRENT confirmed service only - not from earlier in conversation",
  "total_amount": "total booking value as plain number without symbols, e.g. 48900, or null if not confirmed",
  "order_extras": "comma separated list of extras the customer confirmed, exactly as named in the catalog, or null if none",
  "comments": "anything extra the customer wants the advisor to know (e.g. reduced mobility, dietary restrictions, children, medical conditions, special occasions), or null if they said no/nothing/nada"
}

CONVERSATION:
PROMPT;

            // Add relevant conversation context (last few messages are most important)
            $contextMessages = array_slice($history, -6); // Last 6 messages for context
            foreach ($contextMessages as $msg) {
                $role = ucfirst($msg['role']);
                $extractionPrompt .= "{$role}: {$msg['content']}\n";
            }

            // Add current message
            $extractionPrompt .= "Customer: {$this->messageBody}\n";
            $extractionPrompt .= "\nAI Confirmation: {$lastAiResponse}\n";

            // Get AI to extract as JSON
            $aiEngine = AIServiceFactory::make($this->store);
            $jsonResponse = $aiEngine->getResponse(
                "Extract lead information as JSON",
                $extractionPrompt,
                [] // No history for this extraction request
            );

            // Log raw response for debugging
            Log::info('Raw AI Lead Extraction Response', [
                'store_id' => $this->store->id,
                'customer_phone' => $this->from,
                'raw_response' => substr($jsonResponse, 0, 500), // First 500 chars
            ]);

            // Parse JSON response
            $jsonResponse = trim($jsonResponse);
            
            // Remove markdown code blocks if present
            $jsonResponse = preg_replace('/^```(?:json)?\s*/i', '', $jsonResponse);
            $jsonResponse = preg_replace('/\s*```$/', '', $jsonResponse);
            $jsonResponse = trim($jsonResponse);

            $extracted = json_decode($jsonResponse, true);

            if ($extracted && is_array($extracted)) {
                // Validate product/service name against recent messages
                if (!empty($extracted['product_service_name'])) {
                    $productName = $extracted['product_service_name'];

                    // Usa la misma ventana de 6 mensajes que se le dio al extractor,
                    // no solo el turno actual — si no, un "sí, confirmo" final (que no
                    // repite el nombre del producto) invalida un producto ya confirmado
                    // pocos turnos atrás y la reserva llega al asesor como N/A.
                    $recentText = implode(' ', array_column($contextMessages, 'content'))
                        . ' ' . $this->messageBody . ' ' . $lastAiResponse;

                    // Check if product appears in recent context (case-insensitive)
                    if (stripos($recentText, $productName) === false) {
                        Log::warning('Extracted product not in recent context', [
                            'extracted_product' => $productName,
                            'recent_text' => substr($recentText, 0, 200),
                        ]);
                        
                        // Fall back to regex extraction which is more conservative
                        $fallbackData = $this->extractLeadDataRegex();
                        $extracted['product_service_name'] = $fallbackData['product_service_name'];
                    }
                }

                // Sanitize and validate each field
                $leadData['customer_name'] = $this->sanitizeString($extracted['customer_name'] ?? null, 100);
                $leadData['meeting_point'] = $this->sanitizeString($extracted['meeting_point'] ?? null, 255);
                $leadData['origin_city'] = $this->sanitizeString($extracted['origin_city'] ?? null, 100);
                $leadData['tour_date'] = $this->sanitizeString($extracted['tour_date'] ?? null, 100);
                $leadData['travelers_count'] = $this->sanitizeString($extracted['travelers_count'] ?? null, 100);
                $leadData['product_service_name'] = $this->sanitizeString($extracted['product_service_name'] ?? null, 150);
                $leadData['total_amount'] = $this->sanitizeString($extracted['total_amount'] ?? null, 50);
                $leadData['order_extras'] = $this->sanitizeString($extracted['order_extras'] ?? null, 255);
                $leadData['comments'] = $this->sanitizeString($extracted['comments'] ?? null, 500);

                Log::info('Lead Data Successfully Extracted via AI', [
                    'store_id' => $this->store->id,
                    'customer_phone' => $this->from,
                    'customer_name' => $leadData['customer_name'],
                    'product_service_name' => $leadData['product_service_name'],
                ]);
            } else {
                Log::warning('Failed to parse AI extraction JSON', [
                    'store_id' => $this->store->id,
                    'response' => substr($jsonResponse, 0, 200),
                ]);
                
                // Fall back to regex extraction
                $leadData = $this->extractLeadDataRegex();
            }
        } catch (\Exception $e) {
            Log::error('AI extraction failed, using fallback regex', [
                'error' => $e->getMessage(),
                'store_id' => $this->store->id,
            ]);
            
            // Fall back to regex extraction
            $leadData = $this->extractLeadDataRegex();
        }

        return $leadData;
    }

    /**
     * Sanitize and validate string fields
     */
    private function shouldCreateLeadFromResponse(string $aiResponse, array $leadData, bool $hasLeadToken): bool
    {
        if ($hasLeadToken) {
            return true;
        }

        if (empty($leadData['product_service_name']) && empty($leadData['customer_name'])) {
            return false;
        }

        if ($this->isLeadCompletionResponse($aiResponse)) {
            return true;
        }

        return false;
    }

    private function isLeadCompletionResponse(string $aiResponse): bool
    {
        $text = mb_strtolower($aiResponse);
        $patterns = [
            'orden está confirmada',
            'pedido está confirmado',
            'tu orden está confirmada',
            'tu pedido está confirmado',
            'su pedido está confirmado',
            'su orden está confirmada',
            'pedido confirmado',
            'orden confirmada',
            'confirmo el pedido',
            'confirmé el pedido',
            'su pedido está casi listo',
            'su orden está casi lista',
            'orden lista',
            'pedido listo',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($text, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeString(?string $value, int $maxLength = 255): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);
        
        // Remove "null" string if present
        if (strtolower($value) === 'null') {
            return null;
        }

        if (strlen($value) === 0) {
            return null;
        }

        return substr($value, 0, $maxLength);
    }

    /**
     * Combina lo extraído en el turno actual con lo acumulado en caché de
     * turnos anteriores de este mismo pedido — un campo nuevo no vacío
     * sobreescribe el valor previo (ej: el cliente cambió de producto); un
     * campo vacío en este turno conserva lo ya confirmado antes.
     */
    private function mergeAndCacheOrderDraft(array $freshData): array
    {
        $draftKey = "order_draft:{$this->store->id}:{$this->from}";
        $cached = Cache::get($draftKey, []);

        $merged = [];
        foreach (['customer_name', 'meeting_point', 'origin_city', 'tour_date', 'travelers_count', 'product_service_name', 'total_amount', 'order_extras', 'comments'] as $field) {
            $merged[$field] = !empty($freshData[$field]) ? $freshData[$field] : ($cached[$field] ?? null);
        }

        Cache::put($draftKey, $merged, now()->addHours(4));

        return $merged;
    }

    /**
     * Fallback regex-based lead extraction
     * Used when AI extraction fails or when validation indicates it picked up legacy data
     */
    private function extractLeadDataRegex(): array
    {
        $leadData = [
            'customer_name' => null,
            'meeting_point' => null,
            'product_service_name' => null,
            'total_amount' => null,
        ];

        // Combine recent user messages only (not full history)
        $recentMessages = array_slice($this->getRawHistory(), -3); // Last 3 messages
        $allUserMessages = '';
        foreach ($recentMessages as $message) {
            if ($message['role'] === 'user') {
                $allUserMessages .= ' ' . $message['content'];
            }
        }
        $allUserMessages .= ' ' . $this->messageBody;

        // Extract customer name
        if (preg_match('/(?:my name is|i\'?m|call me|my name\'?s)\s+([A-Za-z\s]+?)(?:[,\.]|$|\b(?:and|for|at|on))/i', $allUserMessages, $matches)) {
            $name = trim($matches[1]);
            if (strlen($name) < 50) {
                $leadData['customer_name'] = $name;
            }
        }

        // Extract meeting point
        if (preg_match('/(?:address|location|at|meet at|pickup at|meeting point|located at)\s+([^,\.]*[,\.]|\b[A-Za-z0-9\s,]+(?:Road|Street|Ave|Boulevard|Lane|Drive|Court|District|City|Apt|Apartment|Suite|Block)[\w\s]*)/i', $allUserMessages, $matches)) {
            $address = trim($matches[1]);
            if (strlen($address) < 200) {
                $leadData['meeting_point'] = preg_replace('/[,\.]+$/', '', $address);
            }
        }

        // Extract product/service - ONLY from current context, strict matching
        $productContext = $this->getProductContextWithTypes();
        if ($productContext && !empty($productContext['products'])) {
            // Find the first product actually mentioned in RECENT messages
            foreach ($productContext['products'] as $product) {
                if (stripos($allUserMessages, $product->name) !== false) {
                    $leadData['product_service_name'] = $product->name;
                    break; // Stop at first match
                }
            }
        }

        // Extract date/time
        if (preg_match('/(?:date|time|when|schedule|book|appointment)\s+(?:for|at|on|:)?\s*([^,\.]*\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}[^,\.]*|(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)[^,\.]*|(?:\d{1,2}:\d{2}|\d{1,2}\s*(?:am|pm))[^,\.]*)/i', $allUserMessages, $matches)) {
            $datetime = trim($matches[1]);
            if (strlen($datetime) < 100) {
                // preferred_date_time removed — use total_amount instead
            }
        }

        return $leadData;
    }

    /**
     * Get raw message history (helper for extraction)
     */
    private function getRawHistory(): array
    {
        return WhatsAppMessage::where('store_id', $this->store->id)
            ->where('customer_phone', $this->from)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn($msg) => ['role' => $msg->role, 'content' => $msg->content])
            ->toArray();
    }

    /**
     * Extract lead data from chat history and AI responses.
     * DEPRECATED: Use extractLeadDataWithAI instead for better accuracy.
     * Kept as reference only.
     * 
     * IMPORTANT: The history parameter includes the FULL conversation including:
     * - Customer messages (role='user')
     * - AI-generated responses (role='assistant')
     * - Human operator responses (role='assistant', created during human intervention)
     * 
     * This ensures lead extraction includes context from both AI bot and human operator,
     * providing more complete customer data extraction.
     *
     * @param array $history Chat history with 'role' and 'content'
     *                       Includes both AI and human operator messages
     * @return array Extracted lead data with keys: customer_name, meeting_point, product_service_name, total_amount
     * @deprecated Use extractLeadDataWithAI instead
     */
    private function extractLeadData(array $history): array
    {
        $leadData = [
            'customer_name' => null,
            'meeting_point' => null,
            'product_service_name' => null,
            'total_amount' => null,
        ];

        // Combine all user messages from history for analysis
        $allUserMessages = '';
        foreach ($history as $message) {
            if ($message['role'] === 'user') {
                $allUserMessages .= ' ' . $message['content'];
            }
        }

        // Add current message body
        $allUserMessages .= ' ' . $this->messageBody;

        // Extract customer name - look for patterns like "My name is X" or "I'm X"
        if (preg_match('/(?:my name is|i\'?m|call me|my name\'?s)\s+([A-Za-z\s]+?)(?:[,\.]|$|\b(?:and|for|at|on))/i', $allUserMessages, $matches)) {
            $name = trim($matches[1]);
            if (strlen($name) < 50) { // Sanity check
                $leadData['customer_name'] = $name;
            }
        }

        // Extract meeting point - look for patterns indicating a location reference
        if (preg_match('/(?:address|location|at|meet at|pickup at|meeting point|located at)\s+([^,\.]*[,\.]|\b[A-Za-z0-9\s,]+(?:Road|Street|Ave|Boulevard|Lane|Drive|Court|District|City|Apt|Apartment|Suite|Block)[\w\s]*)/i', $allUserMessages, $matches)) {
            $address = trim($matches[1]);
            if (strlen($address) < 200) { // Sanity check
                $leadData['meeting_point'] = preg_replace('/[,\.]+$/', '', $address);
            }
        }

        // Extract product/service name from chat history
        $productContext = $this->getProductContextWithTypes();
        if ($productContext && !empty($productContext['products'])) {
            // Get the first product mentioned
            $product = $productContext['products']->first();
            if ($product) {
                $leadData['product_service_name'] = $product->name;
            }
        }

        // Extract date/time patterns
        if (preg_match('/(?:date|time|when|schedule|book|appointment)\s+(?:for|at|on|:)?\s*([^,\.]*\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4}[^,\.]*|(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)[^,\.]*|(?:\d{1,2}:\d{2}|\d{1,2}\s*(?:am|pm))[^,\.]*)/i', $allUserMessages, $matches)) {
            $datetime = trim($matches[1]);
            if (strlen($datetime) < 100) { // Sanity check
                // preferred_date_time removed — use total_amount instead
            }
        }

        return $leadData;
    }
}
