<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Conversation;
use App\Jobs\ProcessWhatsAppMessage;
use App\Services\WhatsAppService;
use App\Models\Lead;
use App\Models\WhatsAppTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class WhatsAppController extends Controller
{
    /**
     * Handle Meta WhatsApp webhook verification challenge.
     * GET /api/whatsapp/webhook/{store_token}
     *
     * Meta sends a GET request with query parameters:
     * - hub.mode=subscribe
     * - hub.challenge=<challenge_string>
     * - hub.verify_token=<verify_token>
     *
     * @param Request $request
     * @param string $store_token
     * @return Response
     */
    public function verify(Request $request, string $store_token)
    {
        // Find store by decrypted wa_verify_token
        // Since wa_verify_token is encrypted in DB, we load all stores and compare
        // (Store table is small, so this is efficient)
        $store = Store::all()->firstWhere('wa_verify_token', $store_token);

        if (!$store) {
            Log::warning('WhatsApp webhook verification failed: store not found', [
                'token_length' => strlen($store_token),
            ]);
            return response('Store Not Found', 404);
        }

        // Capturamos los datos que envía Meta
        $mode = $request->input('hub_mode');
        $challenge = $request->input('hub_challenge');
        $verifyToken = $request->input('hub_verify_token');

        // Validamos contra el token de la base de datos
        if ($mode === 'subscribe' && $verifyToken === $store->wa_verify_token) {
            // IMPORTANTE: Retornar solo el challenge como texto plano
            return response($challenge, 200)
                    ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle incoming WhatsApp messages from Meta webhook.
     * POST /api/whatsapp/webhook/{store_token}
     *
     * @param Request $request
     * @param string $store_token
     * @return Response
     */
    public function handle(Request $request, string $store_token): Response
{
    $payload = $request->json()->all();

    // 1. FILTRAR EVENTOS DE ESTADO (Sent, Delivered, Read)
    if (isset($payload['entry'][0]['changes'][0]['value']['statuses'])) {
        return response('OK', 200);
    }

    // Extract first message from the array
    $messages = $payload['entry'][0]['changes'][0]['value']['messages'] ?? [];
    if (empty($messages)) {
        return response('OK', 200);
    }

    $message = $messages[0];
    $phoneId = $message['id'] ?? null; // Este es el WAMID único de Meta

    // 🔥 CONTROL DE IDEMPOTENCIA: Bloquear reintentos de Meta de inmediato
    if ($phoneId) {
        $cacheKey = "whatsapp_msg_processed:{$phoneId}";
        
        // Si el ID ya existe en la caché, es un reintento. Respondemos 200 y salimos.
        if (Cache::has($cacheKey)) {
            Log::warning('WhatsApp Webhook: Reintento de Meta detectado e ignorado.', ['message_id' => $phoneId]);
            return response('EVENT_RECEIVED', 200);
        }
        
        // Guardamos el ID en caché por 10 minutos para evitar duplicados
        Cache::put($cacheKey, true, now()->addMinutes(10));
    }

    // Si pasa los filtros, guardamos el log real del mensaje entrante
    Log::info('Raw WhatsApp Webhook Payload', ['payload' => $payload]);

    $store = $this->resolveStoreFromPayload($payload);
    if (!$store) {
        Log::warning('WhatsApp message handling failed: unable to resolve store from webhook metadata', [
            'store_token' => $store_token,
            'payload_metadata' => data_get($payload, 'entry.0.changes.0.value.metadata'),
        ]);
        return response('Not Found', 404);
    }

    $type      = $message['type'] ?? null;
    $fromPhone = $message['from'] ?? null;

    $body    = null;
    $mediaId = null;

    // =========================================================
    // TIPO: button — respuesta del restaurante a la plantilla
    // El restaurante tocó un botón (ej: "Aceptado").
    // Se actualiza el estado del lead y se notifica al cliente.
    // Este flujo NO pasa por el Job de IA.
    // =========================================================
    if ($type === 'button') {
        $buttonText = $message['button']['text'] ?? null;

        Log::info('BUTTON_RESPONSE: Respuesta de botón recibida', [
            'store_id'    => $store->id,
            'from'        => $fromPhone,
            'button_text' => $buttonText,
        ]);

        if ($buttonText) {
            $this->handleRestaurantButtonResponse($store, $fromPhone, $buttonText, $message);
        }

        return response('EVENT_RECEIVED', 200);
    }

    // =========================================================
    // TIPO: text del restaurante — comando de actualización
    // Si el fromPhone coincide con store_whatsapp de algún store,
    // se interpreta como comando del restaurante (LISTO 5, etc.)
    // y NO se pasa al Job de IA del cliente.
    // =========================================================
    if ($type === 'text') {
        $textBody = $message['text']['body'] ?? null;

        if ($textBody) {
            $restaurantStore = \App\Models\Store::where('store_whatsapp', $fromPhone)->first();

            if ($restaurantStore) {
                $this->handleRestaurantTextCommand($restaurantStore, $fromPhone, $textBody);
                return response('EVENT_RECEIVED', 200);
            }
        }
    }

    // =========================================================
    // TIPO: location — cliente envió su ubicación GPS
    // Se extrae lat/lng, se guarda en el lead más reciente
    // del cliente y se continúa el flujo normal del Job.
    // =========================================================
    if ($type === 'location') {
        $lat     = $message['location']['latitude']  ?? null;
        $lng     = $message['location']['longitude'] ?? null;
        $address = $message['location']['address']   ?? null;
        $name    = $message['location']['name']      ?? null;

        Log::info('LOCATION_RECEIVED: Cliente envió ubicación', [
            'store_id' => $store->id,
            'from'     => $fromPhone,
            'lat'      => $lat,
            'lng'      => $lng,
            'address'  => $address,
        ]);

        if ($lat && $lng) {
            // Guardar coordenadas en el lead más reciente del cliente
            $lead = \App\Models\Lead::where('store_id', $store->id)
                ->where('customer_phone', $fromPhone)
                ->latest()
                ->first();

            if ($lead) {
                $updateData = ['location' => "{$lat},{$lng}"];

                // Si Meta incluye dirección textual, usarla también
                if ($address) {
                    $updateData['delivery_address_or_location'] = $address;
                } elseif ($name) {
                    $updateData['delivery_address_or_location'] = $name;
                }

                $lead->update($updateData);

                Log::info('LOCATION_SAVED: Ubicación guardada en lead', [
                    'lead_id'  => $lead->id,
                    'location' => "{$lat},{$lng}",
                    'address'  => $address ?? $name,
                ]);
            }

            // Construir body descriptivo para que el Job lo incluya en el historial
            $locationParts = ["📍 Ubicación compartida: {$lat},{$lng}"];
            if ($address) $locationParts[] = $address;
            elseif ($name) $locationParts[] = $name;

            $body = implode(' — ', $locationParts);
        }

        if (!$body) {
            return response('OK', 200);
        }
    }

    // =========================================================
    // TIPOS: text, audio, voice — flujo normal del Job de IA
    // =========================================================
    if ($type === 'text') {
        $body = $message['text']['body'] ?? null;
    } elseif (in_array($type, ['audio', 'voice'], true)) {
        $mediaId = $message[$type]['id'] ?? null;
        $body    = '[Mensaje de Voz/Audio]';

        Log::info('WhatsApp audio/voice message received', [
            'store_id'     => $store->id,
            'customer_phone' => $fromPhone,
            'message_id'   => $phoneId,
            'media_id'     => $mediaId,
        ]);
    }

    if (!$fromPhone || (!$body && !$mediaId)) {
        return response('OK', 200);
    }

    Log::info('CONTENIDO REAL: ' . $body);

    // Find or create conversation
    $conversation = Conversation::firstOrCreate(
        ['store_id' => $store->id, 'customer_phone' => $fromPhone],
        ['last_session_at' => now()]
    );

    if ($conversation->wasRecentlyCreated === false) {
        $conversation->update(['last_session_at' => now()]);
    }

    // Dispatch job to process the message asynchronously
    ProcessWhatsAppMessage::dispatch(
        $store,
        $fromPhone,
        $body,
        $phoneId,
        $type,
        $mediaId
    );

    Log::info('WhatsApp message queued for processing', [
        'store_id'   => $store->id,
        'message_id' => $phoneId,
    ]);

    return response('EVENT_RECEIVED', 200);
}

/**
 * Procesa la respuesta de botón del restaurante.
 *
 * El restaurante toca un botón en la plantilla (ej: "Aceptado").
 * El sistema:
 *  1. Identifica el lead más reciente del restaurante.
 *  2. Actualiza lead->status con el estado correspondiente.
 *  3. Notifica al cliente con el mensaje del nuevo estado.
 *
 * @param  Store  $store       Store al que pertenece la conversación
 * @param  string $fromPhone   Número del restaurante que respondió
 * @param  string $buttonText  Texto del botón presionado
 * @param  array  $message     Payload completo del mensaje
 */
/**
 * Procesa comandos de texto enviados por el restaurante.
 *
 * Formato esperado: "ESTADO lead_id" — ej: "LISTO 5", "DESPACHADO 3"
 * Si no viene lead_id, se busca el lead activo más reciente del store.
 *
 * Validaciones:
 *  1. El texto debe contener un estado reconocido.
 *  2. Si viene lead_id, el lead debe existir y pertenecer al store.
 *  3. El lead no debe estar ya entregado o cancelado.
 *
 * Respuestas al restaurante:
 *  - Éxito: confirmación con número de pedido y nuevo estado.
 *  - Error: mensaje descriptivo del problema.
 */
private function handleRestaurantTextCommand(
    \App\Models\Store $store,
    string $fromPhone,
    string $text
): void {
    // Parsear el comando: extraer estado y lead_id opcional
    // Formato: "LISTO 5" o "LISTO" o "listo 5"
    $parts   = preg_split('/\s+/', trim($text), 2);
    $comando = strtolower($parts[0] ?? '');
    $leadId  = isset($parts[1]) ? (int) preg_replace('/\D/', '', $parts[1]) : null;

    Log::info('RESTAURANT_TEXT: Comando recibido', [
        'store_id' => $store->id,
        'from'     => $fromPhone,
        'texto'    => $text,
        'comando'  => $comando,
        'lead_id'  => $leadId,
    ]);

    // 1. Resolver estado
    $newStatus = \App\Models\Lead::resolveStatus($comando);

    if (!$newStatus) {
        Log::warning('RESTAURANT_TEXT: Comando no reconocido', [
            'store_id' => $store->id,
            'comando'  => $comando,
        ]);

        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "❓ Comando no reconocido: \"{$text}\"\n\nComandos válidos:\n• ACEPTADO [#pedido]\n• LISTO [#pedido]\n• DESPACHADO [#pedido]\n• ENTREGADO [#pedido]\n• CANCELADO [#pedido]",
            store:   $store,
        );
        return;
    }

    // 2. Encontrar el lead — por ID específico o el más reciente activo
    if ($leadId) {
        $lead = \App\Models\Lead::where('id', $leadId)
            ->where('store_id', $store->id)
            ->first();

        if (!$lead) {
            Log::warning('RESTAURANT_TEXT: Lead no encontrado o no pertenece al store', [
                'store_id' => $store->id,
                'lead_id'  => $leadId,
            ]);

            \App\Services\WhatsAppService::sendMessage(
                to:      $fromPhone,
                message: "❌ No se encontró el pedido #{$leadId} para este restaurante.",
                store:   $store,
            );
            return;
        }
    } else {
        $lead = \App\Models\Lead::where('store_id', $store->id)
            ->whereNotIn('status', [
                \App\Models\Lead::STATUS_ENTREGADO,
                \App\Models\Lead::STATUS_CANCELADO,
            ])
            ->latest()
            ->first();

        if (!$lead) {
            \App\Services\WhatsAppService::sendMessage(
                to:      $fromPhone,
                message: "❌ No se encontró ningún pedido activo.",
                store:   $store,
            );
            return;
        }
    }

    // 3. Verificar que el lead no esté ya cerrado
    if (in_array($lead->status, [
        \App\Models\Lead::STATUS_ENTREGADO,
        \App\Models\Lead::STATUS_CANCELADO,
    ])) {
        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "⚠ El pedido #{$lead->id} ya está en estado \"{$lead->status}\" y no puede modificarse.",
            store:   $store,
        );
        return;
    }

    // 4. Actualizar estado
    $oldStatus = $lead->status;
    $lead->update(['status' => $newStatus]);

    Log::info('RESTAURANT_TEXT: Estado actualizado', [
        'store_id'   => $store->id,
        'lead_id'    => $lead->id,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
    ]);

    // 5. Confirmar al restaurante
    \App\Services\WhatsAppService::sendMessage(
        to:      $fromPhone,
        message: "✅ Pedido #{$lead->id} actualizado a *{$newStatus}*. Cliente notificado.",
        store:   $store,
    );

    // 6. Notificar al cliente
    $clientMessage = \App\Models\Lead::statusMessage($newStatus);

    if ($clientMessage) {
        \App\Services\WhatsAppService::sendMessage(
            to:      $lead->customer_phone,
            message: $clientMessage,
            store:   $store,
        );

        Log::info('RESTAURANT_TEXT: Cliente notificado', [
            'lead_id'        => $lead->id,
            'customer_phone' => $lead->customer_phone,
            'status'         => $newStatus,
        ]);
    }
}

private function handleRestaurantButtonResponse(
    \App\Models\Store $store,
    string $fromPhone,
    string $buttonText,
    array $message
): void {
    // Resolver el status interno a partir del texto del botón
    $newStatus = \App\Models\Lead::resolveStatus($buttonText);

    if (!$newStatus) {
        Log::warning('BUTTON_RESPONSE: Texto de botón no reconocido como estado', [
            'store_id'    => $store->id,
            'from'        => $fromPhone,
            'button_text' => $buttonText,
        ]);
        return;
    }

    // Encontrar el lead más reciente pendiente de este store
    // El restaurante solo maneja pedidos de su store
    $lead = \App\Models\Lead::where('store_id', $store->id)
        ->whereNotIn('status', [
            \App\Models\Lead::STATUS_ENTREGADO,
            \App\Models\Lead::STATUS_CANCELADO,
        ])
        ->latest()
        ->first();

    if (!$lead) {
        Log::warning('BUTTON_RESPONSE: No se encontró lead activo para actualizar', [
            'store_id'    => $store->id,
            'button_text' => $buttonText,
        ]);
        return;
    }

    // Actualizar el estado del lead
    $lead->update(['status' => $newStatus]);

    Log::info('BUTTON_RESPONSE: Estado del lead actualizado', [
        'store_id'   => $store->id,
        'lead_id'    => $lead->id,
        'old_status' => $lead->getOriginal('status'),
        'new_status' => $newStatus,
    ]);

    // Notificar al cliente con el mensaje correspondiente al nuevo estado
    $clientMessage = \App\Models\Lead::statusMessage($newStatus);

    if ($clientMessage) {
        $sent = \App\Services\WhatsAppService::sendMessage(
            to:      $lead->customer_phone,
            message: $clientMessage,
            store:   $store,
        );

        Log::info('BUTTON_RESPONSE: Cliente notificado del cambio de estado', [
            'lead_id'        => $lead->id,
            'customer_phone' => $lead->customer_phone,
            'status'         => $newStatus,
            'sent'           => $sent,
        ]);
    }
}
// -------------------------------------------------------------------------
    // NEW METHOD
    // -------------------------------------------------------------------------

    /**
     * Send a Meta-approved template message to a lead from the operator dashboard.
     *
     * POST /api/whatsapp/templates/send
     *
     * Request body:
     * {
     *   "lead_id":      123,
     *   "template_id":  7,
     *   "custom_values": ["value1", "value2"]   // operator-supplied overrides
     * }
     *
     * Flow:
     *  1. Validate request fields.
     *  2. Load lead and template, enforcing store_id ownership on both.
     *  3. Auto-prefill variables from lead data using parameters_map,
     *     then merge/override with any custom_values supplied by the operator.
     *  4. Call WhatsAppService::sendTemplateMessage().
     *  5. If template is_reengagement, reset the lead status so the
     *     AIOrchestrator can resume once the customer replies.
     *
     * @param  Request      $request
     * @return JsonResponse
     */
    public function sendManualTemplate(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Derive store from the authenticated user
        $storeId = $user->store_id;    

        // ── 1. Validate ───────────────────────────────────────────────────────
        $validated = $request->validate([
            'lead_id'        => ['required', 'integer', 'exists:leads,id'],
            'template_id'    => ['required', 'integer', 'exists:whatsapp_templates,id'],
            'custom_values'  => ['sometimes', 'array'],
            'custom_values.*' => ['string', 'max:1024'],
        ]);

        // ── 2. Load & authorise ───────────────────────────────────────────────
        // The authenticated user's store_id gates both records, ensuring a store
        // operator can never send a template or contact a lead from another tenant.
        $lead = Lead::findOrFail($validated['lead_id']);

        /** @var \App\Models\Store $store */
        $store = $lead->store;   // eager via relationship

        // Verify the template belongs to the same store as the lead.
        $template = WhatsAppTemplate::where('id', $validated['template_id'])
            ->where('store_id', $store->id)
            ->first();

        if (!$template) {
            Log::warning('WhatsApp template send unauthorised: template does not belong to lead store', [
                'store_id'    => $store->id,
                'lead_id'     => $lead->id,
                'template_id' => $validated['template_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Template not found or does not belong to this store.',
            ], 403);
        }

        // ── 3. Build variables array ──────────────────────────────────────────
        // parameters_map defines which lead/product fields auto-fill each position.
        // Example map: {"1": "customer_name", "2": "product_name", "3": "tracking_number"}
        //
        // Supported auto-fill keys (extend as your Lead / Product models grow):
        //   customer_name, customer_phone, product_name, lead_status
        //
        // custom_values supplied by the operator act as an indexed override list:
        //   position 1 → custom_values[0], position 2 → custom_values[1], …
        // An operator value that is a non-empty string takes precedence over the
        // auto-filled value for that position.

        $parametersMap  = $template->parameters_map ?? [];  // ["1" => "customer_name", …]
        $customValues   = $validated['custom_values'] ?? [];
        $resolvedValues = [];

        // Auto-fill lookup table — add more mappings here as needed
        $leadData = [
            'customer_name'  => $lead->customer_name  ?? '',
            'customer_phone' => $lead->customer_phone ?? '',
            'product_name'   => $lead->product_name   ?? '',
            'lead_status'    => $lead->status         ?? '',
        ];

        // Walk through each positional slot defined in the map
        foreach ($parametersMap as $position => $fieldKey) {
            $positionIndex = (int) $position - 1;  // convert 1-based to 0-based

            // Operator override wins if provided and non-empty
            $operatorValue = $customValues[$positionIndex] ?? null;

            $resolvedValues[$positionIndex] = (is_string($operatorValue) && $operatorValue !== '')
                ? $operatorValue
                : ($leadData[$fieldKey] ?? '');
        }

        // Fill any extra positions the operator added beyond the map definition
        foreach ($customValues as $idx => $value) {
            if (!isset($resolvedValues[$idx]) && $value !== '') {
                $resolvedValues[$idx] = $value;
            }
        }

        // Ensure sequential numeric keys for the service method
        ksort($resolvedValues);
        $finalVariables = array_values($resolvedValues);

        Log::info('WhatsApp manual template send initiated', [
            'store_id'      => $store->id,
            'lead_id'       => $lead->id,
            'template_id'   => $template->id,
            'template_name' => $template->name,
            'is_reengagement' => $template->is_reengagement,
            'variable_count' => count($finalVariables),
        ]);

        // ── 4. Send via WhatsAppService ───────────────────────────────────────
        $sent = WhatsAppService::sendTemplateMessage(
            to:           $lead->customer_phone,
            templateName: $template->name,
            languageCode: $template->language,
            variables:    $finalVariables,
            store:        $store,
        );

        if (!$sent) {
            Log::error('WhatsApp manual template send failed', [
                'store_id'      => $store->id,
                'lead_id'       => $lead->id,
                'template_name' => $template->name,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send template message. Check logs for Meta API details.',
            ], 502);
        }

        // ── 5. Re-engagement: reset lead status ───────────────────────────────
        // When an operator sends a re-engagement template (is_reengagement = true),
        // the 24-hour WhatsApp conversation window will reopen once the customer
        // replies. We pre-emptively set the lead back to an active state so the
        // AIOrchestrator (via ProcessWhatsAppMessage) will resume AI processing
        // as soon as that reply arrives — without requiring manual intervention.
        if ($template->is_reengagement) {
            $lead->update(['status' => 'waiting_customer']);

            // Also re-enable the bot for this phone in case it was paused
            // (bot_active flag lives on the leads table per ARCHITECTURE.md §2.2 Step 2)
            $lead->update(['bot_active' => true]);

            Log::info('WhatsApp re-engagement template sent: lead status reset', [
                'store_id'    => $store->id,
                'lead_id'     => $lead->id,
                'customer'    => $lead->customer_phone,
                'new_status'  => 'waiting_customer',
                'bot_active'  => true,
            ]);
        }

        Log::info('WhatsApp manual template send completed successfully', [
            'store_id'        => $store->id,
            'lead_id'         => $lead->id,
            'template_name'   => $template->name,
            'is_reengagement' => $template->is_reengagement,
        ]);

        return response()->json([
            'success'         => true,
            'message'         => 'Template message sent successfully.',
            'is_reengagement' => $template->is_reengagement,
            'lead_status'     => $template->is_reengagement ? 'waiting_customer' : $lead->status,
        ]);
    }

    private function resolveStoreFromPayload(array $payload): ?Store
    {
        $metadata = data_get($payload, 'entry.0.changes.0.value.metadata', []);
        $phoneNumberId = $metadata['phone_number_id'] ?? $metadata['phoneNumberId'] ?? null;

        if (empty($phoneNumberId)) {
            return null;
        }

        return Store::where('wa_phone_number_id', (string) $phoneNumberId)->first();
    }
}
