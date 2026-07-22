<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Conversation;
use App\Models\WhatsAppPlatformSetting;
use App\Jobs\ProcessWhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppStatusTracker;
use App\Services\Inventory\ProductFinderService;
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
     * GET /api/whatsapp/webhook
     *
     * Meta sends a GET request with query parameters:
     * - hub.mode=subscribe
     * - hub.challenge=<challenge_string>
     * - hub.verify_token=<verify_token>
     *
     * @param Request $request
     * @return Response
     */
    public function verify(Request $request)
    {
        $settings = WhatsAppPlatformSetting::current();

        $mode = $request->input('hub_mode');
        $challenge = $request->input('hub_challenge');
        $verifyToken = $request->input('hub_verify_token');

        if ($mode === 'subscribe' && $settings->wa_verify_token && $verifyToken === $settings->wa_verify_token) {
            // IMPORTANTE: Retornar solo el challenge como texto plano
            return response($challenge, 200)
                    ->header('Content-Type', 'text/plain');
        }

        Log::warning('WhatsApp webhook verification failed', ['mode' => $mode]);
        return response('Forbidden', 403);
    }

    /**
     * Handle incoming WhatsApp messages from Meta webhook.
     * POST /api/whatsapp/webhook
     *
     * @param Request $request
     * @return Response
     */
    public function handle(Request $request): Response
{
    $payload = $request->json()->all();

    // 1. PROCESS META MESSAGE STATUS EVENTS
    if (isset($payload['entry'][0]['changes'][0]['value']['statuses'])) {
        $statuses = $payload['entry'][0]['changes'][0]['value']['statuses'];

        foreach ($statuses as $statusEvent) {
            $wamid = $statusEvent['id'] ?? null;
            $status = $statusEvent['status'] ?? null;

            if ($wamid && $status) {
                WhatsAppStatusTracker::setStatusForWamid($wamid, $status);

                $trackedMessage = \App\Models\WhatsAppMessage::where('wamid', $wamid)->first();

                if ($status === 'failed' && !empty($statusEvent['errors'])) {
                    Log::warning('WhatsApp status event: message FAILED', [
                        'wamid' => $wamid,
                        'status' => $status,
                        'errors' => $statusEvent['errors'],
                    ]);

                    if ($trackedMessage && $trackedMessage->delivery_status !== 'failed') {
                        $trackedMessage->update([
                            'delivery_status' => 'failed',
                            'delivery_error' => json_encode($statusEvent['errors']),
                        ]);

                        $this->alertSuperAdminsOfDeliveryFailure($trackedMessage, $statusEvent['errors']);
                    }
                } else {
                    Log::info('WhatsApp status event processed', [
                        'wamid' => $wamid,
                        'status' => $status,
                    ]);

                    $trackedMessage?->update(['delivery_status' => $status]);
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
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

    $type      = $message['type'] ?? null;
    $fromPhone = $message['from'] ?? null;

    // El payload crudo llega enmascarado en el log de arriba ("Over 9 levels
    // deep"), y "CONTENIDO REAL" solo se loguea si el store se resuelve —
    // si la resolución falla, nunca vemos qué escribió el cliente. Este log
    // corre siempre, antes de cualquier intento de resolución, para poder
    // diagnosticar por qué un mensaje no logra resolver a ningún store.
    Log::info('INCOMING_MESSAGE: Mensaje recibido antes de resolución', [
        'from'     => $fromPhone,
        'type'     => $type,
        'text'     => $message['text']['body'] ?? null,
        'referral' => $message['referral'] ?? null,
    ]);

    // =========================================================
    // IDENTIFICACIÓN DEL REMITENTE
    // El número de WhatsApp es compartido por todos los operadores,
    // así que ya no se puede resolver el store por wa_phone_number_id.
    // Primero se determina QUIÉN escribe (el asesor vía advisor_whatsapp,
    // superadmin vía User.whatsapp, o cliente) y solo para clientes se
    // aplica la nueva cadena de resolución por mensaje.
    // =========================================================
    $isAdvisor    = $fromPhone ? Store::where('advisor_whatsapp', $fromPhone)->exists() : false;
    $isSuperAdmin = $fromPhone ? \App\Models\User::where('whatsapp', $fromPhone)->where('is_super_admin', true)->exists() : false;

    if ($isAdvisor) {
        $store = Store::where('advisor_whatsapp', $fromPhone)->first();
    } elseif ($isSuperAdmin) {
        $superAdminUser = \App\Models\User::where('whatsapp', $fromPhone)->where('is_super_admin', true)->first();
        $store = $superAdminUser?->store ?? Store::query()->first();
    } else {
        $resolution = $this->resolveStoreForCustomer($fromPhone, $type, $message);

        if ($resolution['ambiguousStores']) {
            $this->replyAskWhichStore($fromPhone, $resolution['ambiguousStores']);
            return response('EVENT_RECEIVED', 200);
        }

        if (!$resolution['store']) {
            $this->replyCannotResolveStore($fromPhone);
            return response('EVENT_RECEIVED', 200);
        }

        $store = $resolution['store'];
    }

    if (!$store) {
        Log::warning('WhatsApp message handling failed: unable to resolve a store for this sender', [
            'from' => $fromPhone,
            'is_advisor' => $isAdvisor,
            'is_super_admin' => $isSuperAdmin,
        ]);
        return response('Not Found', 404);
    }

    // =========================================================
    // CRM: Captura automática del lead en cada mensaje entrante
    // Crea o actualiza el CustomerLead para este contacto.
    // Solo para mensajes de clientes (no del asesor ni superadmin).
    // =========================================================
    if ($fromPhone && $type !== 'button' && !$isAdvisor && !$isSuperAdmin) {
        \App\Services\CustomerLeadService::findOrCreateLead($store, $fromPhone);
    }

    $body    = null;
    $mediaId = null;

    // =========================================================
    // TIPO: button — respuesta del asesor a la plantilla
    // El asesor tocó un botón (ej: "Cerrado").
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
            $this->handleAdvisorButtonResponse($store, $fromPhone, $buttonText, $message);
        }

        return response('EVENT_RECEIVED', 200);
    }

    // =========================================================
    // TIPO: text — verificar si es asesor, superadmin o cliente
    // Orden de prioridad:
    //   1. Reporte (asesor o superadmin)
    //   2. Comando de estado (asesor)
    //   3. Flujo normal del bot (cliente)
    // =========================================================
    if ($type === 'text') {
        $textBody = $message['text']['body'] ?? null;

        if ($textBody) {
            // ¿Es el asesor?
            $advisorStore = \App\Models\Store::where('advisor_whatsapp', $fromPhone)->first();

            if ($advisorStore) {
                // ¿Es un comando de reporte?
                $range = \App\Services\ReportService::parseCommand($textBody);
                if ($range) {
                    $report = \App\Services\ReportService::operatorReport(
                        $advisorStore->id,
                        $range['from'],
                        $range['to'],
                        $range['label']
                    );
                    \App\Services\WhatsAppService::sendMessage(
                        to:      $fromPhone,
                        message: $report,
                        store:   $advisorStore,
                    );
                    Log::info('REPORT: Reporte enviado al asesor', [
                        'store_id' => $advisorStore->id,
                        'label'    => $range['label'],
                    ]);
                    return response('EVENT_RECEIVED', 200);
                }

                // ¿Es un comando de estado?
                $this->handleAdvisorTextCommand($advisorStore, $fromPhone, $textBody);
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
    // TIPOS: text, audio, voice — flujo normal del Job de IA
    // No se procesa el tipo 'location' de Meta — no hay zona de
    // cobertura ni GPS que validar en el flujo de reservas de tours.
    // =========================================================
    if ($type === 'text') {
        $body = $message['text']['body'] ?? null;
    } elseif (in_array($type, ['audio', 'voice'], true)) {
        $mediaId = $message[$type]['id'] ?? null;

        // Keep body empty so ProcessWhatsAppMessage can trigger transcription logic
        $body = null;

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
        $mediaId,
        null
    );

    Log::info('WhatsApp message queued for processing', [
        'store_id'   => $store->id,
        'message_id' => $phoneId,
    ]);

    return response('EVENT_RECEIVED', 200);
}

/**
 * Procesa la respuesta de botón del asesor.
 *
 * El asesor toca un botón en la plantilla (ej: "Cerrado").
 * El sistema:
 *  1. Identifica el lead más reciente del store.
 *  2. Actualiza lead->status con el estado correspondiente.
 *  3. Notifica al cliente con el mensaje del nuevo estado.
 *
 * @param  Store  $store       Store al que pertenece la conversación
 * @param  string $fromPhone   Número del asesor que respondió
 * @param  string $buttonText  Texto del botón presionado
 * @param  array  $message     Payload completo del mensaje
 */
/**
 * Procesa comandos de texto enviados por el asesor.
 *
 * Formato esperado: "ESTADO lead_id" — ej: "DERIVADO 5", "CERRADO 3"
 * Si no viene lead_id, se busca el lead activo más reciente del store.
 *
 * Validaciones:
 *  1. El texto debe contener un estado reconocido.
 *  2. Si viene lead_id, el lead debe existir y pertenecer al store.
 *  3. El lead no debe estar ya cerrado o cancelado.
 *
 * Respuestas al asesor:
 *  - Éxito: confirmación con número de reserva y nuevo estado.
 *  - Error: mensaje descriptivo del problema.
 */
private function handleAdvisorTextCommand(
    \App\Models\Store $store,
    string $fromPhone,
    string $text
): void {
    // Parsear el comando: extraer estado y lead_id opcional
    // Formato: "CERRADO 5" o "CERRADO" o "cerrado 5"
    $parts   = preg_split('/\s+/', trim($text), 2);
    $comando = strtolower($parts[0] ?? '');

    // Extraer lead_id del segundo fragmento (solo el primer número)
    preg_match('/(\d+)/', $parts[1] ?? '', $leadMatches);
    $leadId = isset($leadMatches[1]) ? (int) $leadMatches[1] : null;

    Log::info('ADVISOR_TEXT: Comando recibido', [
        'store_id' => $store->id,
        'from'     => $fromPhone,
        'texto'    => $text,
        'comando'  => $comando,
        'lead_id'  => $leadId,
    ]);

    // Comando especial: RESERVA #lead_id — reenvía al asesor el
    // resumen completo de la reserva. No cambia el estado.
    if ($comando === 'reserva') {
        if (!$leadId) {
            \App\Services\WhatsAppService::sendMessage(
                to:      $fromPhone,
                message: "❓ Indica el número de reserva. Ejemplo: *RESERVA 13*",
                store:   $store,
            );
            return;
        }
        $this->handleResendReservationInfo($store, $fromPhone, $leadId);
        return;
    }

    // 1. Resolver estado
    $newStatus = \App\Models\Lead::resolveStatus($comando);

    if (!$newStatus) {
        Log::warning('ADVISOR_TEXT: Comando no reconocido', [
            'store_id' => $store->id,
            'comando'  => $comando,
        ]);

        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "❓ Comando no reconocido: \"{$text}\"\n\nComandos válidos:\n• DERIVADO [#reserva]\n• CERRADO [#reserva]\n• CANCELADO [#reserva]\n• RESERVA [#reserva]",
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
            Log::warning('ADVISOR_TEXT: Lead no encontrado o no pertenece al store', [
                'store_id' => $store->id,
                'lead_id'  => $leadId,
            ]);

            \App\Services\WhatsAppService::sendMessage(
                to:      $fromPhone,
                message: "❌ No se encontró la reserva #{$leadId} para este asesor.",
                store:   $store,
            );
            return;
        }
    } else {
        $lead = \App\Models\Lead::where('store_id', $store->id)
            ->whereNotIn('status', [
                \App\Models\Lead::STATUS_CERRADO,
                \App\Models\Lead::STATUS_CANCELADO,
            ])
            ->latest()
            ->first();

        if (!$lead) {
            \App\Services\WhatsAppService::sendMessage(
                to:      $fromPhone,
                message: "❌ No se encontró ninguna reserva activa.",
                store:   $store,
            );
            return;
        }
    }

    // 3. Verificar que el lead no esté ya cerrado
    if (in_array($lead->status, [
        \App\Models\Lead::STATUS_CERRADO,
        \App\Models\Lead::STATUS_CANCELADO,
    ])) {
        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "⚠ La reserva #{$lead->id} ya está en estado \"{$lead->status}\" y no puede modificarse.",
            store:   $store,
        );
        return;
    }

    // 4. Actualizar estado
    $oldStatus = $lead->status;
    $lead->update(['status' => $newStatus]);

    Log::info('ADVISOR_TEXT: Estado actualizado', [
        'store_id'   => $store->id,
        'lead_id'    => $lead->id,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
    ]);

    // 5. Confirmar al asesor
    \App\Services\WhatsAppService::sendMessage(
        to:      $fromPhone,
        message: "✅ Reserva #{$lead->id} actualizada a *{$newStatus}*. Cliente notificado.",
        store:   $store,
    );

    // 6. Notificar al cliente
    $clientMessage = \App\Models\Lead::statusMessage($newStatus);

    if ($clientMessage) {
        $systemMessage = \App\Models\WhatsAppMessage::create([
            'store_id'       => $store->id,
            'customer_phone' => $lead->customer_phone,
            'role'           => 'system',
            'content'        => $clientMessage,
        ]);

        \App\Services\WhatsAppService::sendMessage(
            to:      $lead->customer_phone,
            message: $clientMessage,
            store:   $store,
            messageId: $systemMessage->id,
        );

        Log::info('ADVISOR_TEXT: Cliente notificado', [
            'lead_id'        => $lead->id,
            'customer_phone' => $lead->customer_phone,
            'status'         => $newStatus,
        ]);

        // Guardar en chat unificado
        $this->saveMessage($store, $lead->customer_phone, 'advisor', "🧑‍💼 " . $text);
    }
}

/**
 * Reenvía al asesor el resumen completo de una reserva — útil cuando
 * necesita verificarla de nuevo.
 *
 * Comando: RESERVA #lead_id
 * No cambia el estado de la reserva.
 * Bloqueado si la reserva ya fue cerrada o cancelada.
 */
private function handleResendReservationInfo(
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
            message: "❌ No se encontró la reserva #{$leadId} para este asesor.",
            store:   $store,
        );
        return;
    }

    if (in_array($lead->status, [
        \App\Models\Lead::STATUS_CERRADO,
        \App\Models\Lead::STATUS_CANCELADO,
    ])) {
        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "⚠️ La reserva #{$leadId} ya está en estado *{$lead->status}* — no se reenvía info de reservas cerradas o canceladas.",
            store:   $store,
        );
        return;
    }

    $producto = $lead->product_name ?? $lead->product_service_name ?? 'N/A';

    if (!empty($lead->extras_detail)) {
        $extrasNames = collect($lead->extras_detail)->pluck('name')->filter()->implode(', ');
        if ($extrasNames) {
            $producto .= " + {$extrasNames}";
        }
    }

    $valor = $lead->total_amount
        ? '$' . number_format((float) preg_replace('/[^0-9.]/', '', $lead->total_amount), 0, ',', '.')
        : 'Consultar';

    $resumen = "📋 *Reserva #{$lead->id}*\n\n"
        . "👤 Cliente: " . ($lead->customer_name ?? 'N/A') . "\n"
        . "📞 Teléfono: {$lead->customer_phone}\n"
        . "📍 Punto de encuentro: " . ($lead->meeting_point ?? 'N/A') . "\n"
        . "📅 Fecha del tour: " . ($lead->tour_date?->format('Y-m-d') ?? 'N/A') . "\n"
        . "🎟️ Tour/Servicio: {$producto}\n"
        . "💰 Valor: {$valor}\n"
        . "📌 Estado: *{$lead->status}*";

    if (!empty($lead->comments)) {
        $resumen .= "\n📝 Observaciones: {$lead->comments}";
    }

    \App\Services\WhatsAppService::sendMessage(
        to:      $fromPhone,
        message: $resumen,
        store:   $store,
    );

    Log::info('RESERVATION_RESENT: Resumen de reserva reenviado al asesor', [
        'store_id' => $store->id,
        'lead_id'  => $lead->id,
        'advisor'  => $fromPhone,
    ]);
}

private function handleAdvisorButtonResponse(
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
    // El asesor solo maneja reservas de su store
    $lead = \App\Models\Lead::where('store_id', $store->id)
        ->whereNotIn('status', [
            \App\Models\Lead::STATUS_CERRADO,
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
        $systemMessage = \App\Models\WhatsAppMessage::create([
            'store_id'       => $store->id,
            'customer_phone' => $lead->customer_phone,
            'role'           => 'system',
            'content'        => $clientMessage,
        ]);

        $sent = \App\Services\WhatsAppService::sendMessage(
            to:      $lead->customer_phone,
            message: $clientMessage,
            store:   $store,
            messageId: $systemMessage->id,
        );

        Log::info('BUTTON_RESPONSE: Cliente notificado del cambio de estado', [
            'lead_id'        => $lead->id,
            'customer_phone' => $lead->customer_phone,
            'status'         => $newStatus,
            'sent'           => $sent,
        ]);

        // Guardar en chat unificado
        $this->saveMessage($store, $lead->customer_phone, 'advisor', "🧑‍💼 [Botón] " . $buttonText);
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

    /**
     * Resuelve a qué operador turístico pertenece un mensaje de un CLIENTE,
     * ahora que el número de WhatsApp es compartido y ya no identifica al store.
     *
     * Cadena de resolución:
     *  1. ID del anuncio de Meta (Click-to-WhatsApp) -> ProductFinderService,
     *     por `referral.source_id`. Más confiable que el texto: el anunciante
     *     lo configura una sola vez y no depende de que el mensaje prellenado
     *     coincida exactamente con el nombre del producto.
     *  2. Nombre del tour mencionado en el texto -> ProductFinderService (cruza todos los stores).
     *  3. Conversación reciente (últimas 24h) de este teléfono -> continúa el pedido en curso.
     *  4. Sin match -> ['store' => null, 'ambiguousStores' => null] (el caller responde el fallback).
     *
     * Nota: para audio/voice no hay texto disponible aún (la transcripción ocurre
     * dentro de ProcessWhatsAppMessage), así que el paso 2 se omite y solo aplica
     * el paso 3 — un cliente nuevo cuyo primer mensaje sea una nota de voz caerá
     * al fallback del paso 4.
     *
     * @return array{store: ?Store, ambiguousStores: ?\Illuminate\Database\Eloquent\Collection}
     */
    private function resolveStoreForCustomer(?string $fromPhone, ?string $type, array $message): array
    {
        $adId = $message['referral']['source_id'] ?? null;

        if ($adId) {
            $resolution = (new ProductFinderService())->resolveStoreByAdId($adId);

            if ($resolution['store'] || $resolution['ambiguousStores']) {
                return ['store' => $resolution['store'], 'ambiguousStores' => $resolution['ambiguousStores']];
            }
        }

        if ($type === 'text') {
            $textBody = $message['text']['body'] ?? null;

            if ($textBody) {
                $resolution = (new ProductFinderService())->resolveStoreByMention($textBody);

                if ($resolution['store'] || $resolution['ambiguousStores']) {
                    return ['store' => $resolution['store'], 'ambiguousStores' => $resolution['ambiguousStores']];
                }
            }
        }

        if ($fromPhone) {
            $conversation = Conversation::where('customer_phone', $fromPhone)
                ->where('last_session_at', '>=', now()->subDay())
                ->orderByDesc('last_session_at')
                ->first();

            if ($conversation) {
                return ['store' => $conversation->store, 'ambiguousStores' => null];
            }
        }

        return ['store' => null, 'ambiguousStores' => null];
    }

    /**
     * Responde cuando el nombre del tour coincide con productos de más de un operador.
     */
    private function replyAskWhichStore(string $fromPhone, \Illuminate\Database\Eloquent\Collection $ambiguousStores): void
    {
        $names = $ambiguousStores->pluck('name')->filter()->implode(', ');

        $anyStore = Store::query()->first();
        if (!$anyStore) {
            return;
        }

        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "¡Hola! 👋 Encontramos ese tour en más de un operador ({$names}). ¿Podrías confirmarnos a cuál te refieres?",
            store:   $anyStore,
        );

        Log::info('CUSTOMER_ROUTING: Mensaje ambiguo entre varios operadores', [
            'from' => $fromPhone,
            'candidate_stores' => $ambiguousStores->pluck('id')->all(),
        ]);
    }

    /**
     * Responde cuando no fue posible identificar el operador (sin match de
     * producto ni conversación reciente) — no se despacha el Job de IA.
     *
     * Corta-circuito anti-bucle: si este número lleva varios intentos fallidos
     * seguidos, es casi seguro que "quien" escribe es otro bot automatizado
     * (no un cliente real) contestándole a nuestro propio mensaje de fallback,
     * y responderle de nuevo solo perpetúa un ping-pong infinito entre los dos
     * bots. Tras el límite, nos quedamos en silencio hasta que expire la
     * ventana en vez de seguir alimentando el bucle.
     */
    private function replyCannotResolveStore(string $fromPhone): void
    {
        $loopKey = "unresolved_reply_count:{$fromPhone}";
        $replyCount = Cache::get($loopKey, 0);

        if ($replyCount >= 3) {
            Log::warning('CUSTOMER_ROUTING: Posible bucle bot-a-bot detectado — silenciado temporalmente', [
                'from'        => $fromPhone,
                'reply_count' => $replyCount,
            ]);
            return;
        }

        Cache::put($loopKey, $replyCount + 1, now()->addMinutes(10));

        $anyStore = Store::query()->first();
        if (!$anyStore) {
            return;
        }

        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: "¡Hola! 👋 Para ayudarte, cuéntanos el nombre del tour u operador que buscas 🧭",
            store:   $anyStore,
        );

        Log::warning('CUSTOMER_ROUTING: No se pudo resolver el operador para este mensaje', [
            'from'        => $fromPhone,
            'reply_count' => $replyCount + 1,
        ]);
    }

    /**
     * Notifica por WhatsApp a los superadmins cuando Meta reporta que un
     * mensaje saliente NO se pudo entregar (a un cliente, a un asesor,
     * o una actualización de estado de reserva). Throttled por mensaje: solo
     * se alerta la primera vez que ese wamid se marca como fallido.
     */
    private function alertSuperAdminsOfDeliveryFailure(\App\Models\WhatsAppMessage $message, array $errors): void
    {
        $superAdmins = \App\Models\User::where('is_super_admin', true)
            ->whereNotNull('whatsapp')
            ->get();

        if ($superAdmins->isEmpty()) {
            return;
        }

        $anyStore = Store::query()->first();
        if (!$anyStore) {
            return;
        }

        $store = Store::find($message->store_id);
        $reason = $errors[0]['title'] ?? $errors[0]['message'] ?? 'Motivo desconocido';
        $preview = mb_strimwidth($message->content ?? '', 0, 100, '…');

        $alertText = "⚠️ *Mensaje de WhatsApp NO entregado*\n"
            . "Negocio: " . ($store?->name ?? "#{$message->store_id}") . "\n"
            . "Para: {$message->customer_phone}\n"
            . "Contenido: \"{$preview}\"\n"
            . "Motivo (Meta): {$reason}";

        foreach ($superAdmins as $admin) {
            \App\Services\WhatsAppService::sendMessage(
                to: $admin->whatsapp,
                message: $alertText,
                store: $anyStore,
            );
        }

        Log::info('DELIVERY_ALERT: Superadmins notificados de mensaje fallido', [
            'message_id' => $message->id,
            'store_id' => $message->store_id,
            'to' => $message->customer_phone,
            'reason' => $reason,
            'alerted_admins' => $superAdmins->pluck('id')->all(),
        ]);
    }

    /**
     * Parsea un comando STORE del superadmin.
     * Formatos soportados:
     *   STORE 2 WHATSAPP 573001234567
     *   STORE 2 NOMBRE Operador X
     *   STORE 2 DEMO | STORE 2 ACTIVE | STORE 2 INACTIVE
     *   STORE 2 WHATSAPP 573001234567 NOMBRE Operador X
     */
    /**
     * Guarda un mensaje en whatsapp_messages para el chat unificado.
     * Roles: user, assistant, advisor, system
     */
    private function saveMessage(
        \App\Models\Store $store,
        string $customerPhone,
        string $role,
        string $content
    ): void {
        try {
            \App\Models\WhatsAppMessage::create([
                'store_id'       => $store->id,
                'customer_phone' => $customerPhone,
                'role'           => $role,
                'content'        => $content,
            ]);
        } catch (\Exception $e) {
            Log::error('CHAT: Error guardando mensaje en historial', [
                'store_id' => $store->id,
                'role'     => $role,
                'error'    => $e->getMessage(),
            ]);
        }
    }

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
            $updates['advisor_whatsapp'] = $wm[1];
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
        if (isset($command['updates']['advisor_whatsapp'])) {
            $lines[] = "📱 WhatsApp: {$command['updates']['advisor_whatsapp']}";
        }

        \App\Services\WhatsAppService::sendMessage(
            to:      $fromPhone,
            message: implode("\n", $lines),
            store:   $currentStore,
        );
    }
}
