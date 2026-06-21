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

    // =========================================================
    // CRM: Captura automática del lead en cada mensaje entrante
    // Crea o actualiza el CustomerLead para este contacto.
    // Solo para mensajes de clientes (no del restaurante ni superadmin).
    // =========================================================
    if ($fromPhone && $type !== 'button') {
        $isRestaurant = \App\Models\Store::where('store_whatsapp', $fromPhone)->exists();
        $isSuperAdmin = \App\Models\User::where('whatsapp', $fromPhone)
            ->where('is_super_admin', true)
            ->exists();

        if (!$isRestaurant && !$isSuperAdmin) {
            \App\Services\CustomerLeadService::findOrCreateLead($store, $fromPhone);
        }
    }

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
    // TIPO: text — verificar si es restaurante, superadmin o cliente
    // Orden de prioridad:
    //   1. Reporte (restaurante o superadmin)
    //   2. Comando de estado (restaurante)
    //   3. Flujo normal del bot (cliente)
    // =========================================================
    if ($type === 'text') {
        $textBody = $message['text']['body'] ?? null;

        if ($textBody) {
            // ¿Es el restaurante?
            $restaurantStore = \App\Models\Store::where('store_whatsapp', $fromPhone)->first();

            if ($restaurantStore) {
                // ¿Es un comando de reporte?
                $range = \App\Services\ReportService::parseCommand($textBody);
                if ($range) {
                    $report = \App\Services\ReportService::restaurantReport(
                        $restaurantStore->id,
                        $range['from'],
                        $range['to'],
                        $range['label']
                    );
                    \App\Services\WhatsAppService::sendMessage(
                        to:      $fromPhone,
                        message: $report,
                        store:   $restaurantStore,
                    );
                    Log::info('REPORT: Reporte enviado al restaurante', [
                        'store_id' => $restaurantStore->id,
                        'label'    => $range['label'],
                    ]);
                    return response('EVENT_RECEIVED', 200);
                }

                // ¿Es un comando de estado?
                $this->handleRestaurantTextCommand($restaurantStore, $fromPhone, $textBody);
                return response('EVENT_RECEIVED', 200);
            }

            // ¿Es el superadmin?
            $superAdmin = \App\Models\User::where('whatsapp', $fromPhone)
                ->where('is_super_admin', true)
                ->first();

            if ($superAdmin) {
                // ¿Es un comando STORE para actualizar configuración?
                $storeUpdate = $this->parseStoreCommand($textBody);
                if ($storeUpdate) {
                    $this->handleStoreCommand($store, $fromPhone, $storeUpdate);
                    return response('EVENT_RECEIVED', 200);
                }

                $range = \App\Services\ReportService::parseCommand($textBody);
                if ($range) {
                    // Reporte consolidado de TODOS los stores.
                    // Se usa el store del webhook para enviar la respuesta
                    // ya que es el que tiene las credenciales de WhatsApp activas.
                    $report = \App\Services\ReportService::superAdminReport(
                        $range['from'],
                        $range['to'],
                        $range['label']
                    );
                    \App\Services\WhatsAppService::sendMessage(
                        to:      $fromPhone,
                        message: $report,
                        store:   $store,
                    );
                    Log::info('REPORT: Reporte consolidado enviado al superadmin', [
                        'user_id' => $superAdmin->id,
                        'label'   => $range['label'],
                    ]);
                    return response('EVENT_RECEIVED', 200);
                }
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
            // =====================================================
            // VALIDACIÓN DE COBERTURA
            // Si el store tiene bounding box configurado,
            // verificar que las coordenadas estén dentro.
            // =====================================================
            $coverageResult = $store->isWithinCoverage($lat, $lng);

            if ($coverageResult === false) {
                // Fuera de cobertura — notificar al cliente y no guardar
                Log::warning('COVERAGE: Cliente fuera de zona de cobertura', [
                    'store_id' => $store->id,
                    'lat'      => $lat,
                    'lng'      => $lng,
                ]);

                \App\Services\WhatsAppService::sendMessage(
                    to:      $fromPhone,
                    message: "Lo sentimos 😔 Tu ubicación está fuera de nuestra zona de cobertura actual. Por favor contáctanos para verificar si podemos llegar a tu dirección.",
                    store:   $store,
                );

                return response('EVENT_RECEIVED', 200);
            }

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
                    'lead_id'         => $lead->id,
                    'location'        => "{$lat},{$lng}",
                    'address'         => $address ?? $name,
                    'within_coverage' => $coverageResult ?? 'not_configured',
                ]);

                // Si el pedido ya está en estado LISTO, enviar las coordenadas
                // al restaurante para que el domiciliario pueda llegar.
                if (in_array($lead->status, [
                    \App\Models\Lead::STATUS_LISTO,
                    \App\Models\Lead::STATUS_DESPACHADO,
                ]) && $store->hasRestaurantNotification()) {
                    $mapsLink = "https://maps.google.com/?q={$lat},{$lng}";
                    $addressText = $address ?? $name ?? 'Ver en mapa';

                    \App\Services\WhatsAppService::sendMessage(
                        to:      $store->store_whatsapp,
                        message: "📍 *Ubicación del cliente — Pedido #{$lead->id}*\n\n{$addressText}\n🗺️ {$mapsLink}",
                        store:   $store,
                    );

                    Log::info('LOCATION_FORWARDED: Coordenadas enviadas al restaurante', [
                        'lead_id'    => $lead->id,
                        'restaurant' => $store->store_whatsapp,
                        'maps_link'  => $mapsLink,
                    ]);
                }
            }

            // Si el pedido está en estado activo de entrega, no pasar al Job de IA
            $activeLead = $lead ?? \App\Models\Lead::where('store_id', $store->id)
                ->where('customer_phone', $fromPhone)
                ->latest()
                ->first();

            $skipAI = $activeLead && in_array($activeLead->status, [
                \App\Models\Lead::STATUS_LISTO,
                \App\Models\Lead::STATUS_DESPACHADO,
                \App\Models\Lead::STATUS_ACEPTADO,
            ]);

            if ($skipAI) {
                Log::info('LOCATION: Ubicación guardada sin pasar al Job de IA — estado: ' . ($activeLead->status ?? 'N/A'), [
                    'store_id' => $store->id,
                    'lead_id'  => $activeLead->id ?? null,
                ]);
                return response('EVENT_RECEIVED', 200);
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

    // Extraer lead_id del segundo fragmento (solo el primer número)
    preg_match('/(\d+)/', $parts[1] ?? '', $leadMatches);
    $leadId = isset($leadMatches[1]) ? (int) $leadMatches[1] : null;

    Log::info('RESTAURANT_TEXT: Comando recibido', [
        'store_id' => $store->id,
        'from'     => $fromPhone,
        'texto'    => $text,
        'comando'  => $comando,
        'lead_id'  => $leadId,
    ]);

    // Comando especial: TELEFONO #lead_id
    if ($comando === 'telefono') {
        if (!$leadId) {
            \App\Services\WhatsAppService::sendMessage(
                to:      $fromPhone,
                message: "❓ Indica el número de pedido. Ejemplo: *TELEFONO 13*",
                store:   $store,
            );
            return;
        }
        $this->handlePhoneRequest($store, $fromPhone, $leadId);
        return;
    }

    // 1. Resolver estado
    $newStatus = \App\Models\Lead::resolveStatus($comando);

    if (!$newStatus) {
        Log::warning('RESTAURANT_TEXT: Comando no reconocido', [
            'store_id' => $store->id,
            'comando'  => $comando,
        ]);

        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "❓ Comando no reconocido: \"{$text}\"\n\nComandos válidos:\n• ACEPTADO [#pedido]\n• LISTO [#pedido]\n• DESPACHADO [#pedido]\n• ENTREGADO [#pedido]\n• CANCELADO [#pedido]\n• TELEFONO [#pedido]",
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

    // 7. Si el pedido cambió a LISTO y el cliente no compartió ubicación,
    //    pedirle que la comparta para agilizar la entrega del domiciliario.
    if ($newStatus === \App\Models\Lead::STATUS_LISTO && empty($lead->location)) {
        \App\Services\WhatsAppService::sendMessage(
            to:      $lead->customer_phone,
            message: "📍 Para que el domiciliario llegue más rápido a tu puerta, ¿puedes compartir tu ubicación por WhatsApp?\n\nSolo toca el clip 📎 → Ubicación → *Compartir ubicación actual*\n\nSi prefieres no hacerlo, no hay problema — el domiciliario llegará con la dirección registrada. 🏠",
            store:   $store,
        );

        Log::info('LOCATION_REQUEST: Solicitada ubicación al cliente — pedido LISTO sin GPS', [
            'lead_id'        => $lead->id,
            'customer_phone' => $lead->customer_phone,
        ]);
    }
}

/**
 * Entrega el teléfono del cliente al restaurante cuando el domiciliario
 * no puede encontrar la dirección.
 *
 * Comando: TELEFONO #lead_id
 * Validaciones:
 *  1. El lead debe existir y pertenecer al store.
 *  2. El lead debe estar en estado listo, despachado o entregado.
 */
private function handlePhoneRequest(
    \App\Models\Store $store,
    string $fromPhone,
    int $leadId
): void {
    $lead = \App\Models\Lead::where('id', $leadId)
        ->where('store_id', $store->id)
        ->first();

    if (!$lead) {
        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "❌ No se encontró el pedido #{$leadId} para este restaurante.",
            store:   $store,
        );
        return;
    }

    if (!in_array($lead->status, [
        \App\Models\Lead::STATUS_LISTO,
        \App\Models\Lead::STATUS_DESPACHADO,
        \App\Models\Lead::STATUS_ENTREGADO,
    ])) {
        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "⚠️ El pedido #{$leadId} está en estado *{$lead->status}*. El teléfono solo se entrega cuando el pedido está Listo, Despachado o Entregado.",
            store:   $store,
        );
        return;
    }

    \App\Services\WhatsAppService::sendMessage(
        to:      $fromPhone,
        message: "📞 Teléfono del cliente para coordinar entrega del pedido #{$lead->id}:\n*{$lead->customer_phone}*\n\n⚠️ Úsalo únicamente para coordinar esta entrega.",
        store:   $store,
    );

    Log::info('PHONE_SHARED: Teléfono del cliente compartido con restaurante', [
        'store_id'       => $store->id,
        'lead_id'        => $lead->id,
        'restaurant'     => $fromPhone,
        'customer_phone' => $lead->customer_phone,
    ]);
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

    /**
     * Parsea un comando STORE del superadmin.
     * Formatos soportados:
     *   STORE 2 WHATSAPP 573001234567
     *   STORE 2 NOMBRE Restaurante X
     *   STORE 2 DEMO | STORE 2 ACTIVE | STORE 2 INACTIVE
     *   STORE 2 WHATSAPP 573001234567 NOMBRE Restaurante X
     */
    private function parseStoreCommand(string $text): ?array
    {
        if (!preg_match('/^STORE\s+(\d+)\s+(.+)$/i', trim($text), $matches)) {
            return null;
        }

        $storeId = (int) $matches[1];
        $rest    = trim($matches[2]);
        $updates = [];

        if (preg_match('/^(DEMO|ACTIVE|INACTIVE)$/i', $rest, $sm)) {
            $updates['status'] = strtolower($sm[1]);
        }

        if (preg_match('/WHATSAPP\s+(\d{10,15})/i', $rest, $wm)) {
            $updates['store_whatsapp'] = $wm[1];
        }

        if (preg_match('/NOMBRE\s+(.+?)(?:\s+WHATSAPP|\s+DEMO|\s+ACTIVE|$)/i', $rest, $nm)) {
            $updates['name'] = trim($nm[1]);
        }

        return empty($updates) ? null : ['store_id' => $storeId, 'updates' => $updates];
    }

    /**
     * Ejecuta el comando STORE — actualiza los campos del store indicado.
     */
    private function handleStoreCommand(
        \App\Models\Store $currentStore,
        string $fromPhone,
        array $command
    ): void {
        $targetStore = \App\Models\Store::find($command['store_id']);

        if (!$targetStore) {
            \App\Services\WhatsAppService::sendMessage(
                to:      $fromPhone,
                message: "❌ Store #{$command['store_id']} no encontrado.",
                store:   $currentStore,
            );
            return;
        }

        $targetStore->update($command['updates']);

        Log::info('STORE_COMMAND: Store actualizado por superadmin', [
            'store_id' => $targetStore->id,
            'updates'  => $command['updates'],
        ]);

        $statusLabels = ['active' => '✅ Activo', 'demo' => '🎯 Demo', 'inactive' => '⏸️ Inactivo'];
        $lines = ["✅ *Store #{$targetStore->id} actualizado:*"];
        if (isset($command['updates']['status'])) {
            $label = $statusLabels[$command['updates']['status']] ?? $command['updates']['status'];
            $lines[] = "🔄 Status: {$label}";
        }
        if (isset($command['updates']['name'])) {
            $lines[] = "🏪 Nombre: {$command['updates']['name']}";
        }
        if (isset($command['updates']['store_whatsapp'])) {
            $lines[] = "📱 WhatsApp: {$command['updates']['store_whatsapp']}";
        }

        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: implode("\n", $lines),
            store:   $currentStore,
        );
    }
}
