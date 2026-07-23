<?php

namespace App\Services;

use App\Models\Store;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\WhatsAppStatusTracker;

class WhatsAppService
{
    /**
     * Send a message via WhatsApp Business API.
     *
     * @param string $to Phone number in format: countrycode[phonenumber]
     * @param string $message Message text to send
     * @param Store $store Store with WhatsApp credentials
     * @param int|null $messageId Local DB message ID to map status events to cache
     * @return bool True if message was sent successfully
     */
    public static function sendMessage(string $to, string $message, Store $store, ?int $messageId = null): bool
    {
        try {
            $url = "https://graph.facebook.com/v20.0/{$store->wa_phone_number_id}/messages";

            if ($messageId !== null) {
                WhatsAppStatusTracker::markPending($messageId);
            }

            $response = Http::withToken($store->wa_access_token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $message],
                ]);

            if ($response->failed()) {
                Log::error("Error de Meta API", [
                    'store_id' => $store->id,
                    'status' => $response->status(),
                    'body' => $response->json()
                ]);
            }

            $wamid = data_get($response->json(), 'messages.0.id');
            if ($response->successful() && $messageId !== null && $wamid) {
                WhatsAppStatusTracker::trackWamid($messageId, $wamid);
                \App\Models\WhatsAppMessage::whereKey($messageId)->update(['wamid' => $wamid]);
            }

            Log::debug('WhatsApp message sent', [
                'store_id' => $store->id,
                'to' => $to,
                'status' => $response->status(),
                'success' => $response->successful(),
            ]);

            if (!$response->successful()) {
                if ($messageId !== null) {
                    WhatsAppStatusTracker::setStatusForMessage($messageId, 'failed');
                }

                Log::warning('WhatsApp message send failed', [
                    'store_id' => $store->id,
                    'to' => $to,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Log::error('WhatsApp message send error', [
                'store_id' => $store->id,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send a Meta-approved HSM Template Message via WhatsApp Business Cloud API.
     *
     * Builds the `template` payload with a `body` component whose positional
     * parameters are constructed from the ordered $variables array.
     *
     * Meta payload reference:
     * POST https://graph.facebook.com/v20.0/{phone_number_id}/messages
     * {
     *   "messaging_product": "whatsapp",
     *   "to": "<phone>",
     *   "type": "template",
     *   "template": {
     *     "name": "<template_name>",
     *     "language": { "code": "<language_code>" },
     *     "components": [
     *       {
     *         "type": "body",
     *         "parameters": [
     *           { "type": "text", "text": "value1" },
     *           { "type": "text", "text": "value2" }
     *         ]
     *       }
     *     ]
     *   }
     * }
     *
     * @param string  $to           Recipient phone (E.164, e.g. "573001234567")
     * @param string  $templateName Technical name registered in Meta Business Manager
     * @param string  $languageCode BCP-47 code, e.g. "es_CO", "en_US"
     * @param array   $variables    Ordered list of replacement values for {{1}}, {{2}}, …
     * @param Store   $store        Store instance carrying wa_access_token & wa_phone_number_id
     * @return bool                 True on successful delivery to Meta API
     */
    public static function sendTemplateMessage(
        string $to,
        string $templateName,
        string $languageCode,
        array  $variables,
        Store  $store,
        ?int   $messageId = null
    ): bool {
        try {
            $url = "https://graph.facebook.com/v20.0/{$store->wa_phone_number_id}/messages";

            // Build the ordered parameter objects required by the Meta Cloud API.
            // Each entry in $variables maps to one positional placeholder: {{1}}, {{2}}, …
            $parameters = array_map(
                fn (string $value): array => ['type' => 'text', 'text' => $value],
                array_values($variables)   // ensure sequential numeric keys
            );

            // Only include the components key when there are actual variables.
            // Sending an empty components array causes a Meta API validation error.
            $templatePayload = [
                'name'     => $templateName,
                'language' => ['code' => $languageCode],
            ];

            if (!empty($parameters)) {
                $templatePayload['components'] = [
                    [
                        'type'       => 'body',
                        'parameters' => $parameters,
                    ],
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $to,
                'type'              => 'template',
                'template'          => $templatePayload,
            ];

            Log::info('WhatsApp template message dispatching', [
                'store_id'      => $store->id,
                'to'            => $to,
                'template_name' => $templateName,
                'language'      => $languageCode,
                'variable_count' => count($variables),
            ]);

            Log::debug('WhatsApp full payload', [
                'url' => $url,
                'payload' => $payload
            ]);

            if ($messageId !== null) {
                WhatsAppStatusTracker::markPending($messageId);
            }

            $response = Http::withToken($store->wa_access_token)
                ->timeout(15)
                ->post($url, $payload);

            $wamid = data_get($response->json(), 'messages.0.id');
            if ($response->successful() && $messageId !== null && $wamid) {
                WhatsAppStatusTracker::trackWamid($messageId, $wamid);
                \App\Models\WhatsAppMessage::whereKey($messageId)->update(['wamid' => $wamid]);
            }

            // Log detailed Meta error before the generic failure check,
            // mirroring the pattern used in sendMessage().
            if ($response->failed()) {
                Log::error('WhatsApp template message Meta API error', [
                    'store_id'      => $store->id,
                    'to'            => $to,
                    'template_name' => $templateName,
                    'status'        => $response->status(),
                    'body'          => $response->json(),
                ]);
            }

            if (!$response->successful()) {
                if ($messageId !== null) {
                    WhatsAppStatusTracker::setStatusForMessage($messageId, 'failed');
                }

                Log::warning('WhatsApp template message send failed', [
                    'store_id'      => $store->id,
                    'to'            => $to,
                    'template_name' => $templateName,
                    'status'        => $response->status(),
                    'error'         => $response->json(),
                ]);
                return false;
            }

            Log::info('WhatsApp template message sent successfully', [
                'store_id'      => $store->id,
                'to'            => $to,
                'template_name' => $templateName,
                'wamid'         => data_get($response->json(), 'messages.0.id'),
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('WhatsApp template message send exception', [
                'store_id'      => $store->id,
                'to'            => $to,
                'template_name' => $templateName,
                'error'         => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Test WhatsApp connection with provided credentials
     *
     * @param string $phoneNumberId WhatsApp Phone Number ID
     * @param string $accessToken WhatsApp Business API access token
     * @return array ['success' => bool, 'message' => string, 'data' => array|null]
     */
    public static function testConnection(string $phoneNumberId, string $accessToken): array
    {
        try {
            $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}";

            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => 'Connection successful! Phone number: ' . ($data['display_phone_number'] ?? 'Unknown'),
                    'data' => $data,
                ];
            }

            $errorData = $response->json();
            $errorMessage = $errorData['error']['message'] ?? 'Unknown error from Meta API';

            return [
                'success' => false,
                'message' => 'Connection failed: ' . $errorMessage,
                'data' => $errorData,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Download WhatsApp media for transcription.
     *
     * @param string $mediaId WhatsApp media ID
     * @param Store $store Store with WhatsApp credentials
     * @param string|null $mimeType Optional mime type to help with extension
     * @return string|null Relative disk path of downloaded file or null on failure
     */
    public static function downloadMedia(string $mediaId, Store $store, ?string $mimeType = null): ?string
    {
        try {
            $urlResponse = Http::withToken($store->wa_access_token)
                ->timeout(30)
                ->get("https://graph.facebook.com/v20.0/{$mediaId}");

            if ($urlResponse->failed()) {
                Log::error('Failed to retrieve WhatsApp media URL', [
                    'store_id' => $store->id,
                    'media_id' => $mediaId,
                    'status' => $urlResponse->status(),
                    'body' => $urlResponse->body(),
                ]);
                return null;
            }

            $urlData = $urlResponse->json();
            $downloadUrl = $urlData['url'] ?? null;

            if (!$downloadUrl) {
                Log::error('WhatsApp media URL missing from response', [
                    'store_id' => $store->id,
                    'media_id' => $mediaId,
                    'response' => $urlData,
                ]);
                return null;
            }

            $mediaResponse = Http::withToken($store->wa_access_token)
                ->timeout(60)
                ->get($downloadUrl);

            if ($mediaResponse->failed()) {
                Log::error('Failed to download WhatsApp media content', [
                    'store_id' => $store->id,
                    'media_id' => $mediaId,
                    'status' => $mediaResponse->status(),
                    'body' => $mediaResponse->body(),
                ]);
                return null;
            }

            $contentType = $mediaResponse->header('Content-Type') ?: $mimeType;
            $extension = 'ogg';
            if ($contentType) {
                if (str_contains($contentType, 'mpeg') || str_contains($contentType, 'mp3')) {
                    $extension = 'mp3';
                } elseif (str_contains($contentType, 'mp4') || str_contains($contentType, 'm4a')) {
                    $extension = 'm4a';
                } elseif (str_contains($contentType, 'ogg')) {
                    $extension = 'ogg';
                }
            }

            $relativePath = "whatsapp_media/{$mediaId}.{$extension}";
            $stored = \Illuminate\Support\Facades\Storage::disk('local')->put($relativePath, $mediaResponse->body());

            if (!$stored) {
                Log::error('Failed to save WhatsApp media to disk', [
                    'store_id' => $store->id,
                    'media_id' => $mediaId,
                    'relative_path' => $relativePath,
                ]);
                return null;
            }

            Log::info('WhatsApp media downloaded successfully', [
                'store_id' => $store->id,
                'media_id' => $mediaId,
                'relative_path' => $relativePath,
            ]);

            return $relativePath;
        } catch (\Exception $e) {
            Log::error('Error downloading WhatsApp media', [
                'store_id' => $store->id,
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Send a welcome message after successful setup
     *
     * @param Store $store Store with WhatsApp credentials
     * @param string|null $toNumber Optional target phone number (if null, just logs)
     * @return bool True if message was sent or logged successfully
     */
    public static function sendWelcomeMessage(Store $store, ?string $toNumber = null): bool
    {
        try {
            $message = sprintf(
                "¡Hola! 🚀 Soy el asistente de %s. Estoy configurado correctamente y listo para atender a tus clientes. ¡Hagamos crecer tu negocio!",
                $store->name
            );

            // If target number is provided, send the message
            if ($toNumber) {
                return self::sendMessage($toNumber, $message, $store);
            }

            // Otherwise, just log it for the store owner to see
            Log::info('Store setup completed - Welcome message ready', [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'message' => $message,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error preparing welcome message', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send an image via WhatsApp Business API.
     *
     * @param string $toNumber Phone number in format: countrycode[phonenumber]
     * @param string $imageUrl Full URL to the image (public accessible)
     * @param Store $store Store with WhatsApp credentials
     * @param string|null $caption Optional caption for the image
     * @return bool True if image was sent successfully
     */
    public static function sendWhatsAppImage(
        string $toNumber,
        string $imageUrl,
        Store $store,
        ?string $caption = null
    ): bool {
        try {
            $url = "https://graph.facebook.com/v20.0/{$store->wa_phone_number_id}/messages";

            $imagePayload = [
                'link' => $imageUrl,
            ];

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $toNumber,
                'type' => 'image',
                'image' => $imagePayload,
            ];

            // Add caption if provided (appears as text above image)
            if ($caption) {
                $payload['image']['caption'] = $caption;
            }

            $response = Http::withToken($store->wa_access_token)->post($url, $payload);

            if (!$response->successful()) {
                Log::warning('WhatsApp image send failed', [
                    'store_id' => $store->id,
                    'to' => $toNumber,
                    'image_url' => $imageUrl,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                return false;
            }

            Log::debug('WhatsApp image sent', [
                'store_id' => $store->id,
                'to' => $toNumber,
                'image_url' => $imageUrl,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('WhatsApp image send error', [
                'store_id' => $store->id,
                'to' => $toNumber,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Process AI response to extract image tags and send images.
     *
     * This function finds all [IMG: product_id] tags in the response,
     * sends the corresponding images via WhatsApp, and returns the cleaned text.
     *
     * @param string $responseText Raw AI response containing potential [IMG: id] tags
     * @param Store $store Store with WhatsApp credentials and products
     * @param string $customerNumber Customer's phone number to send images to
     * @return string Cleaned response text without [IMG: ...] tags
     */
    public static function processAIResponse(
        string $responseText,
        Store $store,
        string $customerNumber
    ): string {
        try {
            // Find all [IMG: id] tags
            $pattern = '/\[IMG:\s*(\d+)\s*\]/i';
            preg_match_all($pattern, $responseText, $matches);

            Log::info('AI Response Image Processing', [
                'store_id' => $store->id,
                'customer_number' => $customerNumber,
                'response_text' => $responseText,
                'found_img_tags' => $matches[1] ?? [],
            ]);

            if (!empty($matches[1])) {
                foreach ($matches[1] as $imageId) {
                    Log::info('Processing image tag', [
                        'store_id' => $store->id,
                        'image_id' => $imageId,
                        'customer_number' => $customerNumber,
                    ]);

                    $image = ProductImage::find($imageId);

                    if ($image) {
                        Log::info('ProductImage found', [
                            'store_id' => $store->id,
                            'image_id' => $imageId,
                            'image_path' => $image->image_path,
                            'public_url' => $image->public_url,
                            'product_id' => $image->product_id,
                        ]);

                        // Get product name for caption
                        $productName = $image->product ? $image->product->name : 'Product Image';

                        Log::info('Sending image', [
                            'store_id' => $store->id,
                            'image_id' => $imageId,
                            'public_url' => $image->public_url,
                            'customer_number' => $customerNumber,
                            'product_name' => $productName,
                        ]);

                        // Send the image
                        $imageSent = self::sendWhatsAppImage(
                            $customerNumber,
                            $image->public_url,
                            $store,
                            $productName
                        );

                        Log::info('Image send result', [
                            'store_id' => $store->id,
                            'image_id' => $imageId,
                            'image_sent' => $imageSent,
                            'customer_number' => $customerNumber,
                        ]);
                    } else {
                        Log::warning('ProductImage not found for AI response', [
                            'store_id' => $store->id,
                            'image_id' => $imageId,
                        ]);
                    }
                }
            } else {
                Log::info('No image tags found in AI response', [
                    'store_id' => $store->id,
                    'customer_number' => $customerNumber,
                ]);
            }

            // Remove all [IMG: ...] tags from response
            $cleanText = preg_replace($pattern, '', $responseText);

            // Clean up extra whitespace dejado por los tags [IMG:id] removidos,
            // sin tocar los saltos de línea — WhatsApp los necesita para que
            // la respuesta se vea formateada en vez de un solo párrafo corrido.
            $cleanText = preg_replace('/[ \t]+/', ' ', $cleanText);   // espacios/tabs repetidos
            $cleanText = preg_replace('/\n{3,}/', "\n\n", $cleanText); // máx. una línea en blanco
            $cleanText = trim($cleanText);

            Log::info('AI Response processing completed', [
                'store_id' => $store->id,
                'customer_number' => $customerNumber,
                'original_length' => strlen($responseText),
                'cleaned_length' => strlen($cleanText),
            ]);

            return $cleanText;
        } catch (\Exception $e) {
            Log::error('Error processing AI response images', [
                'store_id' => $store->id,
                'customer_number' => $customerNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return original text if processing fails
            return $responseText;
        }
    }
}
