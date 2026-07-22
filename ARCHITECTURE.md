# WhatsApp AI Negotiation Chatbot Ecosystem
## Comprehensive Software Architecture & Technical Documentation

**Version:** 1.0  
**Last Updated:** May 2026  
**Audience:** Systems Engineers, Software Architects, Full-Stack Developers, AI Coding Assistants

---

## Table of Contents

1. [Executive & Technical Overview](#1-executive--technical-overview)
2. [System Architecture & Component Interactions](#2-system-architecture--component-interactions)
3. [Core Logic & Guardrails](#3-core-logic--guardrails-the-negotiation-engine)
4. [Production Infrastructure, Deployment & DevOps](#4-production-infrastructure-deployment--devops)
5. [Developer Guide: Maintenance, Updates & Scaling](#5-developer-guide-maintenance-updates--scaling)

---

## 1. Executive & Technical Overview

### 1.1 System Purpose

This platform enables **multi-tenant, store-based WhatsApp negotiation conversations** where customers negotiate product prices against an AI negotiator personality ("Jessica" or other configured personas). The system is designed to:

- **Accept incoming WhatsApp messages** from customers via Meta's WhatsApp Business Cloud API
- **Execute real-time AI negotiation logic** using pluggable LLM providers (OpenAI GPT-4o, X.ai Grok, Google Gemini)
- **Extract structured lead data** (customer name, delivery address, product/service name, preferred timing) from conversations
- **Manage product context and pricing guardrails** to prevent the AI from breaking predetermined floor prices or business rules
- **Support multi-tenant architecture** where multiple stores operate independently with isolated credentials and AI configurations
- **Process asynchronously** via a robust queue system to handle webhook bursts and API latency

### 1.2 Core Technology Stack

| Component | Technology | Version | Purpose |
|-----------|-----------|---------|---------|
| **Backend Framework** | Laravel | 11/12 (PHP 8.3+) | REST API, webhook handling, business logic |
| **Database** | MySQL | 8.0+ | Transactional data, conversation history, leads |
| **Cache/Session** | Redis | Latest | Queue, cache, idempotency control |
| **Queue System** | Laravel Queue (Database/Redis driver) | Built-in | Async job processing, message orchestration |
| **Admin Panel** | Laravel Filament | 3.x | Store management, lead review, bot control |
| **AI Providers** | OpenAI, X.ai Grok, Google Gemini | API v1 | LLM inference for negotiation and lead extraction |
| **WhatsApp Integration** | Meta WhatsApp Business Cloud API | v20.0+ | Message webhooks, delivery, media handling |
| **Containerization** | Docker | 24.x+ | Development and production environment isolation |
| **Infrastructure** | Linux (Debian/Ubuntu 22.04+), Production WAF | Various | Cloud or on-premise deployment |
| **Transcription** | OpenAI Whisper | 1 | Audio message transcription |

### 1.3 Multi-Tenant Architecture

Each **Store** record represents an independent negotiation bot instance with:

```json
{
  "store": {
    "id": "UUID or INT",
    "name": "Store Display Name",
    "personality_type": "vendedor|soporte|asesor",
    "system_prompt": "Custom AI instructions (encrypted in transit)",
    "ai_provider": "openai|grok|gemini",
    "ai_model": "gpt-4o|grok-beta|gemini-2.5-flash",
    "ai_api_key": "Encrypted environment variable",
    "wa_access_token": "Encrypted WhatsApp Business API token",
    "wa_phone_number_id": "Meta phone number identifier",
    "wa_business_account_id": "Meta business account ID",
    "wa_verify_token": "Webhook verification token (encrypted)"
  }
}
```

Each store operates in **complete isolation**:
- Separate WhatsApp phone numbers and credentials
- Independent AI model configurations
- Isolated conversation histories and leads
- Store-scoped product catalogs

---

## 2. System Architecture & Component Interactions

### 2.1 High-Level Data Flow: Request to Response

```
┌─────────────────────────────────────────────────────────────────────┐
│  INCOMING WHATSAPP WEBHOOK (Meta Cloud API)                         │
│  POST /api/whatsapp/webhook/{store_token}                           │
└─────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│  WEBHOOK HANDLER (WhatsAppController::handle)                        │
│  ✓ Extract store by verify_token                                     │
│  ✓ Idempotency check via Cache (10min TTL on WAMID)                 │
│  ✓ Log raw payload for audit                                         │
│  ✓ Filter status-only events (delivery receipts, read status)        │
└─────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│  DISPATCH ASYNC JOB (ProcessWhatsAppMessage)                         │
│  - Queue: database|redis (configurable in .env)                      │
│  - Payload: Store, Phone, MessageBody, MediaId, ProductContext      │
│  - Retry: 3 attempts with exponential backoff                        │
└─────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│  JOB HANDLER (ProcessWhatsAppMessage::handle)                        │
│  1. BOT CONTROL CHECK (Human Intervention Prevention)                │
│  2. AUDIO TRANSCRIPTION (if audio message)                           │
│  3. PRODUCT CONTEXT RETRIEVAL (ProductFinder pattern)                │
│  4. CONVERSATION HISTORY FETCH (last 10 messages)                    │
│  5. SYSTEM PROMPT PIPELINE (construction & validation)               │
│  6. AI RESPONSE GENERATION (AIOrchestrator)                          │
│  7. LEAD EXTRACTION (AI + Regex fallback)                            │
│  8. MESSAGE DISPATCH (WhatsAppService)                               │
└─────────────────────────────────────────────────────────────────────┘
                                    ↓
┌─────────────────────────────────────────────────────────────────────┐
│  RESPONSE DELIVERY (WhatsAppService::sendMessage)                    │
│  - HTTP POST to Meta Graph API v20.0                                 │
│  - Include text, images (if [IMG:ID] extracted)                      │
│  - Log delivery status and errors                                    │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2 Detailed Data Flow Pipeline: Step-by-Step

#### **Step 1: WhatsApp Webhook Payload Reception**

```php
// Incoming webhook from Meta
POST /api/whatsapp/webhook/{store_token}
Content-Type: application/json

{
  "entry": [
    {
      "changes": [
        {
          "value": {
            "messages": [
              {
                "from": "1234567890",           // Customer phone (country code prefix)
                "id": "wamid_abc123xyz",        // Meta's unique WAMID (idempotency key)
                "timestamp": "1622505700",
                "text": {
                  "body": "What's the lowest price for product 42?"
                },
                "type": "text"
                // OR for media:
                // "type": "audio|image|document|voice"
                // "audio|image|document|voice": { "id": "media_id", "mime_type": "..." }
              }
            ]
          }
        }
      ]
    }
  ]
}
```

**Key Processing:**
- Store lookup via `wa_verify_token` (routes to correct multi-tenant instance)
- WAMID cached in Redis for 10 minutes (idempotency control)
- Status-only events (delivery receipts, read confirmations) are filtered immediately
- Webhook returns HTTP 200 within 15 seconds to satisfy Meta's reliability requirements

#### **Step 2: Bot Control Validation (Human Intervention Prevention)**

```php
// CRITICAL FIRST CHECK - Happens BEFORE any AI processing

$botDisabled = Lead::where('store_id', $this->store->id)
    ->where('customer_phone', $this->from)
    ->where('bot_active', false)
    ->exists();

if ($botDisabled) {
    // Log incoming message but DO NOT generate AI response
    // Operator can now manually respond via Filament dashboard
    WhatsAppMessage::create([
        'store_id' => $this->store->id,
        'customer_phone' => $this->from,
        'role' => 'user',
        'content' => $this->messageBody,
    ]);
    return; // Exit early, no AI processing
}
```

**Purpose:** Allows operators to disable bot for specific customers to handle sensitive negotiations or complaints manually.

**Lead Table Dual Purpose:**
- **Bot Control Record:** Any phone where `bot_active = false` disables AI
- **Marketing Lead:** Completed conversations with extracted customer data

This decoupling prevents bot_active toggle from deleting lead data.

#### **Step 3: Audio Transcription (If Audio Message)**

If message type is `audio`, `voice`:

```php
// Download media from Meta
$localPath = WhatsAppService::downloadMedia($this->mediaId, $this->store);

// Transcribe using OpenAI Whisper
$openAi = new OpenAIService($this->store->ai_api_key, 'whisper-1');
$transcribedText = $openAi->transcribeAudio(Storage::disk('local')->path($localPath));

// Prepend metadata to indicate audio source
$this->messageBody = "🎤 [AUDIO]: " . trim($transcribedText);

// Clean up temporary file
Storage::disk('local')->delete($localPath);
```

**Error Handling:**
- Failed downloads → Log error, send customer friendly message, exit job
- Failed transcription → Log error, send customer friendly message, exit job
- Empty transcription → Treat as error, do not proceed to AI

#### **Step 4: Product Context Retrieval (PRODUCT_FINDER Pattern)**

The system uses a **hierarchical product matching strategy**:

```php
private function getProductContextWithTypes(): ?array
{
    // Pattern 1: Explicit ID in message
    if (preg_match('/\bid[:\s]+(\d+)/i', $this->messageBody, $matches)) {
        $product = Product::where('store_id', $this->store->id)
            ->where('id', $matches[1])
            ->first();
        
        if ($product) {
            return [
                'type' => 'specific',
                'product' => $product,
                'context' => $this->buildProductContext($product)
            ];
        }
    }
    
    // Pattern 2: FullText search on name/description
    $products = $this->fullTextSearchProducts($this->messageBody);
    
    if ($products->count() === 1) {
        return [
            'type' => 'single_match',
            'product' => $products->first(),
            'context' => $this->buildProductContext($products->first())
        ];
    }
    
    if ($products->count() > 1) {
        return [
            'type' => 'multiple_matches',
            'products' => $products,
            'context' => $this->buildMultipleProductContext($products)
        ];
    }
    
    // Pattern 3: No match fallback - include entire catalog
    return [
        'type' => 'catalog_fallback',
        'products' => Product::where('store_id', $this->store->id)->get(),
        'context' => $this->buildCatalogContext()
    ];
}
```

**FullText Search Implementation:**

```sql
-- MySQL FullText query
SELECT * FROM products
WHERE store_id = ? 
AND MATCH(name, description) AGAINST(? IN NATURAL LANGUAGE MODE)
ORDER BY MATCH(name, description) AGAINST(? IN NATURAL LANGUAGE MODE) DESC
LIMIT 10;
```

**Product Context Output Format:**

```
### PRODUCT CATALOG DATA:

**Product Name (ID: 42)**
- Price: $99.99 (Floor Price: $79.99)
- Stock Status: In Stock (15 units)
- Description: High-quality item with premium features
- Sales Strategy: Emphasize durability and warranty
- Required Customer Info: Delivery address, preferred date
- FAQ Context: Common questions about shipping and returns
```

> [!WARNING]  
> **Critical Guardrail:** Product context is appended to system prompt as **read-only reference data**. The AI should NEVER ignore or override floor prices defined in the product record. See Section 3: Core Logic & Guardrails for enforcement mechanisms.

#### **Step 5: Conversation History Fetching**

```php
$rawHistory = WhatsAppMessage::where('store_id', $this->store->id)
    ->where('customer_phone', $this->from)
    ->orderBy('created_at', 'desc')
    ->limit(10)  // Last 10 messages (5 turns typical)
    ->get(['id', 'role', 'content', 'created_at']);

$history = $rawHistory
    ->reverse()
    ->map(fn(WhatsAppMessage $msg) => [
        'role' => $msg->role,  // 'user' or 'assistant'
        'content' => $msg->content,
    ])
    ->toArray();
```

**Important:** Both AI-generated responses and human operator messages are stored as `role='assistant'` in the database. This ensures the AI maintains full context even after a human takes over the conversation.

#### **Step 6: System Prompt Pipeline (Construction & Validation)**

This is the **critical pipeline that enforces business rules:**

```php
// ===== SYSTEM PROMPT PIPELINE =====
// Pure concatenation: Store Instructions → Product Context → Metadata

// 1. Load store system prompt (configured by store admin in Filament)
$systemPrompt = trim($this->store->system_prompt ?? '');

// Validation: Warn if empty
if (empty($systemPrompt)) {
    Log::warning('PROMPT_VALIDATION: Store system_prompt is empty', [
        'store_id' => $this->store->id,
        'store_name' => $this->store->name,
    ]);
    $systemPrompt = 'You are an assistant.';
}

// 2. Append product context (formatted with headers, no conflicting rules)
if ($productContext && !empty($productContext['context'])) {
    $systemPrompt .= "\n\n### PRODUCT CATALOG DATA:\n" . $productContext['context'];
} else {
    Log::warning('CONTEXT_VALIDATION: No product context retrieved', [
        'store_id' => $this->store->id,
        'customer_phone' => $this->from,
    ]);
}

// 3. Append system metadata (timestamps, completion signal)
$systemPrompt .= "\n\n### SYSTEM METADATA:\n";
$systemPrompt .= "Current Date/Time: " . now()->format('Y-m-d H:i:s') . "\n";
$systemPrompt .= "Lead Completion Signal: [LEAD_COMPLETE]\n";
$systemPrompt .= "When the customer has confirmed a purchase, provided enough information to create a lead, "
    . "or the conversation is complete, append the exact token [LEAD_COMPLETE] at the end of your response.\n";

Log::info("PROMPT_PIPELINE: System prompt constructed", [
    'store_id' => $this->store->id,
    'prompt_length' => strlen($systemPrompt),
    'full_prompt' => $systemPrompt,  // For debugging
]);
```

**Prompt Structure Example:**

```
You are an experienced sales representative for EliteStore. Your role is to sell premium products
while maintaining fair pricing. Always prioritize customer satisfaction and provide honest product recommendations.

CRITICAL NEGOTIATION RULES:
- Base price for all items is firm at the market rate
- Maximum discount allowed: 15% for bulk orders only
- DO NOT accept prices below cost + 20% margin
- If customer insists on lower price, offer value-adds instead (faster shipping, warranty extension)

### PRODUCT CATALOG DATA:

**Premium Widget (ID: 42)**
- Price: $99.99 (Floor Price: $79.99)
- Stock Status: In Stock (15 units)
- Description: Durable, premium quality with 2-year warranty
- Sales Strategy: Emphasize durability and long-term value
- Required Customer Info: Delivery address, preferred date

### SYSTEM METADATA:

Current Date/Time: 2026-05-22 14:30:00
Lead Completion Signal: [LEAD_COMPLETE]
When the customer has confirmed a purchase or provided enough information to create a lead,
append the exact token [LEAD_COMPLETE] at the end of your response.
```

> [!WARNING]  
> **Context Overwrite Prevention:** The system concatenates instructions in a specific order to prevent the AI from redefining roles or ignoring critical rules. If the system prompt contains conflicting instructions (e.g., "Always give 50% discounts"), the product context floor price takes precedence and should be referenced explicitly by the AI.

#### **Step 7: LLM Response Generation**

```php
// Get the configured AI service for this store
$aiEngine = AIServiceFactory::make($this->store);

// Execute AI inference
$aiResponse = $aiEngine->getResponse(
    customerMessage: $this->messageBody,
    systemPrompt: $systemPrompt,
    conversationHistory: $history
);

// Example response:
// "I'd be happy to help! The Premium Widget is $99.99, which includes free shipping...
// Can you provide your delivery address and preferred delivery date so I can proceed with the order?
// [LEAD_COMPLETE]"
```

**AI Service Factory Pattern:**

```php
class AIServiceFactory
{
    public static function make(Store $store): AiServiceInterface
    {
        return match ($store->ai_provider) {
            'openai' => new OpenAIService($store->ai_api_key, $store->ai_model),
            'grok' => new GrokService($store->ai_api_key, $store->ai_model),
            'gemini' => new GeminiService($store->ai_api_key, $store->ai_model),
        };
    }
}
```

Each AI service implements `AiServiceInterface::getResponse()` which handles:
- Rate limiting and retry logic
- Token counting and truncation
- Error handling and fallback responses
- Logging for audit and debugging

#### **Step 8: Parallel AI Lead Extraction**

After AI response, the system extracts structured lead data using two-tier approach:

**Tier 1: Explicit Token Signal**

```php
$hasLeadToken = strpos($aiResponse, '[LEAD_COMPLETE]') !== false;
```

**Tier 2: AI-Based Extraction (Primary)**

```php
private function extractLeadDataWithAI(array $history, string $lastAiResponse): array
{
    // Construct extraction prompt
    $extractionPrompt = <<<PROMPT
Extract customer information from this conversation.
Return ONLY valid JSON with these fields (use null for missing values):
{
  "customer_name": "string or null",
  "meeting_point": "string or null",
  "product_service_name": "string or null",
  "preferred_date_time": "string or null"
}
PROMPT;

    // Send extraction request to AI
    $extractionResponse = $this->aiEngine->getResponse(
        json_encode($history),
        $extractionPrompt,
        []
    );

    try {
        // Parse JSON
        $extracted = json_decode($extractionResponse, true, flags: JSON_THROW_ON_ERROR);
        return [
            'customer_name' => $extracted['customer_name'] ?? null,
            'meeting_point' => $extracted['meeting_point'] ?? null,
            'product_service_name' => $extracted['product_service_name'] ?? null,
            'preferred_date_time' => $extracted['preferred_date_time'] ?? null,
        ];
    } catch (\JsonException $e) {
        // Fall back to regex extraction
        return $this->extractLeadDataRegex();
    }
}
```

**Tier 3: Regex Fallback (Resilience)**

```php
private function extractLeadDataRegex(): array
{
    $data = [];
    
    // Find phone numbers, addresses, dates
    preg_match('/(?:phone|celular|tel)[:\s]*([0-9\-\+\s]{10,})/i', 
        $this->messageBody, $matches);
    $data['meeting_point'] = $matches[1] ?? null;

    // Find dates (YYYY-MM-DD, DD/MM, etc.)
    preg_match('/(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}|\d{4}[\/\-]\d{1,2}[\/\-]\d{1,2})/', 
        $this->messageBody, $matches);
    $data['preferred_date_time'] = $matches[1] ?? null;

    // Try to find customer name from conversation history
    foreach (array_reverse($this->history) as $msg) {
        if ($msg['role'] === 'user') {
            preg_match('/(?:my name is|i\'m|i am)\s+([a-z]+)/i', $msg['content'], $matches);
            if (!empty($matches[1])) {
                $data['customer_name'] = $matches[1];
                break;
            }
        }
    }

    return array_merge([
        'customer_name' => null,
        'meeting_point' => null,
        'product_service_name' => null,
        'preferred_date_time' => null,
    ], array_filter($data, fn($v) => $v !== null));
}
```

#### **Step 9: Lead Creation Decision Logic**

```php
private function shouldCreateLeadFromResponse(
    string $aiResponse,
    array $leadData,
    bool $hasLeadToken
): bool
{
    // Explicit: AI appended [LEAD_COMPLETE] token
    if ($hasLeadToken) {
        return true;
    }

    // Heuristic: Check if we have sufficient extracted data
    $requiredFields = ['customer_name', 'meeting_point'];
    $filledFields = array_filter($leadData, fn($v) => $v !== null);

    // Require at least 2 fields to create lead implicitly
    if (count($filledFields) >= 2) {
        return true;
    }

    return false;
}
```

#### **Step 10: Lead Record Creation**

```php
if ($shouldCreateLead) {
    // Remove token from message before storing
    if ($hasLeadToken) {
        $messageToSend = preg_replace('/\[LEAD_COMPLETE\]/', '', $aiResponse);
        $messageToSend = trim($messageToSend);
    }

    Lead::create([
        'store_id' => $this->store->id,
        'customer_phone' => $this->from,
        'customer_name' => $leadData['customer_name'] ?? null,
        'meeting_point' => $leadData['meeting_point'] ?? null,
        'product_service_name' => $leadData['product_service_name'] ?? null,
        'preferred_date_time' => $leadData['preferred_date_time'] ?? null,
        'summary' => $messageToSend,
        'is_processed' => false,
        'bot_active' => true,  // Default: allow bot to continue
    ]);

    Log::info('Lead created from WhatsApp conversation', [
        'store_id' => $this->store->id,
        'customer_phone' => $this->from,
        'completion_method' => $hasLeadToken ? 'explicit_token' : 'heuristic_fallback',
    ]);
}
```

#### **Step 11: Image Injection & Dispatch**

```php
// Process AI response to extract image tags [IMG:ID]
$images = [];
preg_match_all('/\[IMG:(\d+)\]/', $messageToSend, $matches);

foreach ($matches[1] as $productId) {
    $productImage = ProductImage::where('product_id', $productId)->first();
    if ($productImage && Storage::disk('public')->exists($productImage->path)) {
        $images[] = [
            'type' => 'image',
            'url' => Storage::url($productImage->path),
        ];
    }
}

// Remove image tags from message text
$messageToSend = preg_replace('/\[IMG:\d+\]/', '', $messageToSend);
$messageToSend = trim($messageToSend);
```

#### **Step 12: WhatsApp Message Dispatch**

```php
// Save message to history
WhatsAppMessage::create([
    'store_id' => $this->store->id,
    'customer_phone' => $this->from,
    'role' => 'assistant',
    'content' => $messageToSend,
]);

// Send to customer via WhatsApp Business API
$success = WhatsAppService::sendMessage(
    to: $this->from,
    message: $messageToSend,
    store: $this->store,
    images: $images
);

if (!$success) {
    Log::error('Failed to send WhatsApp message', [
        'store_id' => $this->store->id,
        'customer_phone' => $this->from,
        'attempts' => $this->attempts(),
    ]);
    
    // Job will retry up to 3 times
    throw new \Exception('WhatsApp delivery failed');
}
```

---

## 3. Core Logic & Guardrails (The Negotiation Engine)

### 3.1 Mathematical Evaluation: Floor Price Enforcement

The system enforces **strict floor price guardrails** through the system prompt and runtime validation:

#### **Floor Price Definition**

Each product has two price fields:

```php
Product::create([
    'name' => 'Premium Widget',
    'price' => 99.99,        // Suggested retail price
    'floor_price' => 79.99,  // Absolute minimum acceptable price
]);
```

#### **Guardrail Enforcement Mechanism**

**Method 1: Prompt-Based Instruction (Primary)**

```
CRITICAL NEGOTIATION RULES:
- Base price for Premium Widget: $99.99
- Absolute floor price: $79.99 (DO NOT GO BELOW THIS)
- If customer offers less than $79.99, politely decline and offer alternatives
- Alternative value-adds: Free shipping, extended warranty, bulk discount on accessories
```

**Method 2: Runtime Validation (Backup)**

```php
private function validatePriceGuardrail(string $customerOffer, Product $product): bool
{
    // Extract numeric value from customer message
    preg_match('/\$?(\d+(?:\.\d{2})?)/i', $customerOffer, $matches);
    $offeredPrice = (float) $matches[1] ?? 0;

    if ($offeredPrice > 0 && $offeredPrice < $product->floor_price) {
        Log::warning('GUARDRAIL_VIOLATION: Customer offer below floor price', [
            'store_id' => $this->store->id,
            'product_id' => $product->id,
            'floor_price' => $product->floor_price,
            'offered_price' => $offeredPrice,
            'violation_margin' => $product->floor_price - $offeredPrice,
        ]);

        // Send escalation message
        WhatsAppService::sendMessage(
            $this->from,
            "I appreciate your offer, but $" . $offeredPrice . " is below our cost on this item. "
            . "However, I can offer free express shipping or an extended warranty at $" . $product->price . ". "
            . "What do you think?",
            $this->store
        );

        return false;
    }

    return true;
}
```

> [!WARNING]  
> **Critical Design Decision:** The system prompt is the PRIMARY enforcement mechanism because LLMs are better at nuanced negotiation than rigid logic gates. However, guardrail validation is logged for human audit and compliance review.

### 3.2 Context Overwrite Prevention

The system prevents the AI from redefining critical business rules through **strict prompt concatenation ordering**:

#### **Three-Layer Prompt Structure**

1. **Store System Prompt** (User-defined negotiation logic)
   - Configurable via Filament dashboard
   - Can be updated without code changes
   - Defines personality, sales strategy, acceptable discount ranges

2. **Product Context** (Read-only reference)
   - Appended with "### PRODUCT CATALOG DATA:" header
   - Includes floor prices, stock, FAQ
   - Cannot be overridden by earlier sections

3. **System Metadata** (Technical signals)
   - Timestamps, lead completion token
   - Cannot be modified by AI

#### **Conflict Resolution Rule**

If the Store System Prompt contains conflicting instructions:

```
INCORRECT CONFIGURATION (creates conflict):
"Your system_prompt includes: 'Always give 50% discounts'
 But product has floor_price = $79.99 and price = $99.99"
```

The system:

```php
// Log the conflict for manual review
Log::warning('PROMPT_CONFLICT_DETECTED', [
    'store_id' => $this->store->id,
    'issue' => 'System prompt discount rule conflicts with product floor price',
    'action' => 'HUMAN_REVIEW_REQUIRED'
]);

// Do NOT proceed with AI response - escalate
throw new PromptConflictException(
    "Store prompt contains conflicting pricing rules. "
    . "Review system_prompt in Filament dashboard."
);
```

**Best Practice:** Store admin should review system_prompt for conflicts using Filament validation:

```php
class StorePolicy
{
    public function validateSystemPrompt(Store $store): bool
    {
        $conflictKeywords = ['always give', 'free', 'discount', 'price'];
        
        if (Str::containsAll($store->system_prompt, $conflictKeywords)) {
            throw new ValidationException(
                'system_prompt contains potentially conflicting keywords'
            );
        }
        
        return true;
    }
}
```

### 3.3 Image Injection & Proactivity Protocol

#### **Image Tag Syntax**

The AI can emit image tags using a special syntax:

```
Here's the product you're interested in: [IMG:42]

We have several colors available. Check out this option: [IMG:43]
```

#### **Processing Pipeline**

```php
// Step 1: Extract all image tags
preg_match_all('/\[IMG:(\d+)\]/', $aiResponse, $matches);
$imageTags = $matches[1];

// Step 2: Validate each image exists
$validImages = [];
foreach ($imageTags as $productId) {
    $product = Product::where('store_id', $this->store->id)
        ->where('id', $productId)
        ->first();
    
    if ($product) {
        $images = ProductImage::where('product_id', $product->id)->get();
        foreach ($images as $img) {
            if (Storage::disk('public')->exists($img->path)) {
                $validImages[] = Storage::url($img->path);
            }
        }
    }
}

// Step 3: Remove tags from text (they're not human-readable)
$messageToSend = preg_replace('/\[IMG:\d+\]/', '', $aiResponse);

// Step 4: Send message with images via WhatsApp API
WhatsAppService::sendMessage(
    to: $this->from,
    message: $messageToSend,
    store: $this->store,
    images: $validImages
);
```

#### **WhatsApp API Integration**

```php
class WhatsAppService
{
    public static function sendMessage(
        string $to,
        string $message,
        Store $store,
        array $images = []
    ): bool {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message],
        ];

        // If images included, send as separate media messages
        if (!empty($images)) {
            foreach ($images as $imageUrl) {
                Http::withToken($store->wa_access_token)
                    ->post(
                        "https://graph.facebook.com/v20.0/{$store->wa_phone_number_id}/messages",
                        [
                            'messaging_product' => 'whatsapp',
                            'to' => $to,
                            'type' => 'image',
                            'image' => ['link' => $imageUrl],
                        ]
                    );
            }
        }

        return Http::withToken($store->wa_access_token)
            ->post(
                "https://graph.facebook.com/v20.0/{$store->wa_phone_number_id}/messages",
                $payload
            )
            ->successful();
    }
}
```

> [!WARNING]  
> **Critical Compliance:** The [IMG:ID] tag MUST be parsed and removed from customer-facing text. Never send raw [IMG:42] tags to customers. The WhatsApp API requires separate HTTP requests for media delivery (one text message, separate image messages).

### 3.4 Null Extraction Handling & Fallback Strategy

When lead extraction fails to populate required fields:

```php
$leadData = $this->extractLeadDataWithAI($history, $aiResponse);

// Log extraction quality
$extractedFieldCount = count(array_filter($leadData, fn($v) => $v !== null));

Log::info('LEAD_EXTRACTION_QUALITY', [
    'store_id' => $this->store->id,
    'customer_phone' => $this->from,
    'extracted_fields' => $extractedFieldCount,
    'missing_fields' => array_filter($leadData, fn($v) => $v === null),
]);

// If critical fields are null, re-prompt customer
if ($extractedFieldCount < 2) {
    $missingPrompt = "To complete your order, I need a few details:\n";
    
    if (empty($leadData['customer_name'])) {
        $missingPrompt .= "- Your full name\n";
    }
    if (empty($leadData['meeting_point'])) {
        $missingPrompt .= "- Your delivery address\n";
    }
    if (empty($leadData['preferred_date_time'])) {
        $missingPrompt .= "- Your preferred delivery date\n";
    }

    WhatsAppService::sendMessage(
        $this->from,
        $missingPrompt,
        $this->store
    );

    // Don't create incomplete lead yet
    return;
}
```

---

## 4. Production Infrastructure, Deployment & DevOps

### 4.1 Docker Containerization Strategy

#### **Multi-Container Architecture**

```yaml
# docker-compose-production.yml
version: '3.9'

services:
  # Application Server
  app:
    build:
      context: .
      dockerfile: Dockerfile.production
    container_name: whatsapp_bot_app
    environment:
      APP_ENV: production
      QUEUE_CONNECTION: redis
      CACHE_DRIVER: redis
      SESSION_DRIVER: redis
      REDIS_HOST: redis
      DB_HOST: mysql
      REDIS_CLUSTER_ENABLED: true
    depends_on:
      - mysql
      - redis
    volumes:
      - ./storage:/app/storage
      - ./logs:/app/logs
    networks:
      - app_network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
    restart: always
    deploy:
      replicas: 3  # Load balancing
      resources:
        limits:
          cpus: '2'
          memory: 1G

  # Database
  mysql:
    image: mysql:8.0
    container_name: whatsapp_bot_mysql
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
    volumes:
      - mysql_data:/var/lib/mysql
      - ./database/init.sql:/docker-entrypoint-initdb.d/init.sql
    networks:
      - app_network
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: always
    deploy:
      resources:
        limits:
          cpus: '4'
          memory: 2G

  # Redis (Cache + Queue)
  redis:
    image: redis:7-alpine
    container_name: whatsapp_bot_redis
    command: redis-server --appendonly yes --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis_data:/data
    networks:
      - app_network
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5
    restart: always
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 512M

  # Queue Worker
  queue-worker:
    build:
      context: .
      dockerfile: Dockerfile.production
    container_name: whatsapp_bot_queue
    command: php artisan queue:work --timeout=300 --tries=3
    environment:
      APP_ENV: production
      QUEUE_CONNECTION: redis
      CACHE_DRIVER: redis
      SESSION_DRIVER: redis
      REDIS_HOST: redis
    depends_on:
      - mysql
      - redis
    volumes:
      - ./storage:/app/storage
      - ./logs:/app/logs
    networks:
      - app_network
    healthcheck:
      test: ["CMD", "ps", "aux"]  # Simple process check
      interval: 30s
      timeout: 10s
      retries: 3
    restart: always
    deploy:
      replicas: 2
      resources:
        limits:
          cpus: '1'
          memory: 512M

  # Reverse Proxy / Load Balancer
  nginx:
    image: nginx:alpine
    container_name: whatsapp_bot_nginx
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf:ro
      - ./ssl:/etc/nginx/ssl:ro
      - ./storage/app/public:/app/public:ro
    depends_on:
      - app
    networks:
      - app_network
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost/health"]
      interval: 30s
      timeout: 10s
      retries: 3
    restart: always

networks:
  app_network:
    driver: bridge
```

#### **Network Isolation & VLAN Handling**

**Docker Bridge Network Considerations:**

```yaml
networks:
  app_network:
    driver: bridge
    driver_opts:
      # Configure custom subnet to avoid IP collisions
      com.docker.network.driver.mtu: 1500
    ipam:
      config:
        # Use RFC 1918 private range, ensure no collision with corporate VLANs
        - subnet: 172.25.0.0/16
          gateway: 172.25.0.1
```

**VLAN/Corporate Network Collision Resolution:**

| Scenario | Problem | Solution |
|----------|---------|----------|
| Docker bridge `172.17.x.x` conflicts with corporate VLAN | Container cannot reach external services | Reconfigure bridge subnet in `docker-compose.yml` to unused range (e.g., `172.25.0.0/16`) |
| Government health network block all internal IPs | Webhook delivery fails | Use NAT-friendly configuration, expose only port 80/443 via nginx reverse proxy |
| Service-to-service communication over VLAN | Direct container IPs not routable | Use Docker overlay network with Consul backend or restrict to localhost communication |

**Production Network Topology:**

```
┌─────────────────────────────────────────────────────────┐
│                  Public Internet                         │
│           Meta WhatsApp Business API                     │
└────────────────────────┬────────────────────────────────┘
                         │ HTTPS Webhook (port 443)
                         ▼
┌─────────────────────────────────────────────────────────┐
│            WAF (Web Application Firewall)                │
│   - Rate limiting (100 req/sec per phone)                │
│   - DDoS mitigation                                       │
│   - SQL injection blocking                               │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────┐
│              Nginx Reverse Proxy (443→80)                │
│           Load balancer for app replicas                 │
└────────────────────────┬────────────────────────────────┘
                         │
    ┌────────────────────┼────────────────────┐
    ▼                    ▼                    ▼
┌─────────┐         ┌─────────┐         ┌─────────┐
│  App 1  │         │  App 2  │         │  App 3  │
└────┬────┘         └────┬────┘         └────┬────┘
     │                   │                   │
     └─────────────┬─────────────┬───────────┘
                   ▼             ▼
            ┌────────────┐  ┌────────┐
            │  MySQL 8.0 │  │ Redis  │
            └────────────┘  └────────┘
                   │
                   ▼
            ┌────────────────┐
            │ Queue Workers  │
            │  (consumers)   │
            └────────────────┘
```

### 4.2 Security & Logging Strategy

#### **Encryption & Secrets Management**

All sensitive data in the `stores` table are encrypted at rest:

```php
// app/Models/Store.php
protected function casts(): array
{
    return [
        'ai_api_key' => 'encrypted',              // OpenAI/Grok/Gemini API keys
        'wa_access_token' => 'encrypted',          // WhatsApp Business API token
        'wa_verify_token' => 'encrypted',          // Webhook verification token
    ];
}
```

**Encryption Key Management:**

```bash
# .env
APP_ENCRYPTION_KEY=base64:generated_at_install
APP_KEY_ROTATION_ENABLED=true
APP_KEY_ROTATION_INTERVAL=90  # Days

# AWS Secrets Manager (recommended for production)
AWS_SECRETS_MANAGER_ENABLED=true
AWS_REGION=us-east-1
```

#### **Production Logging Standards**

**Log Level Hierarchy:**

| Level | Usage | Retention |
|-------|-------|-----------|
| `production.ERROR` | API failures, data corruption, security incidents | 90 days |
| `production.WARNING` | Guardrail violations, missing data, retry attempts | 60 days |
| `production.INFO` | Successful operations, lead creation, state changes | 30 days |
| `production.DEBUG` | Full payloads, SQL queries, prompt details | 7 days |

**Logging Configuration:**

```php
// config/logging.php
'channels' => [
    'production' => [
        'driver' => 'daily',
        'path' => storage_path('logs/production.log'),
        'level' => env('LOG_LEVEL', 'info'),
        'days' => 30,
        'formatter' => \Monolog\Formatter\JsonFormatter::class,  // JSON for ELK stack
        'processors' => [
            \Monolog\Processor\ProcessorInterface::class,
        ],
    ],

    'security' => [
        'driver' => 'daily',
        'path' => storage_path('logs/security.log'),
        'level' => 'warning',
        'days' => 90,
        'formatter' => \Monolog\Formatter\JsonFormatter::class,
    ],

    'webhook' => [
        'driver' => 'daily',
        'path' => storage_path('logs/webhook.log'),
        'level' => 'debug',
        'days' => 7,
    ],
];
```

**Critical Logging Points:**

```php
// Example: Webhook payload tracking
Log::channel('webhook')->debug('Incoming WhatsApp Webhook', [
    'store_id' => $store->id,
    'phone' => $from,
    'wamid' => $phoneId,
    'message_preview' => substr($messageBody, 0, 100),
    'timestamp' => now()->toIso8601String(),
]);

// Example: Guardrail violation
Log::channel('security')->warning('Price Negotiation Boundary Exceeded', [
    'store_id' => $this->store->id,
    'product_id' => $product->id,
    'floor_price' => $product->floor_price,
    'offered_price' => $offeredPrice,
    'violation_amount' => $product->floor_price - $offeredPrice,
    'user_agent' => request()->userAgent(),
    'ip_address' => request()->ip(),
]);

// Example: Lead extraction quality
Log::channel('production')->info('Lead Quality Metrics', [
    'store_id' => $this->store->id,
    'extraction_method' => $hasLeadToken ? 'explicit_token' : 'ai_based',
    'fields_extracted' => count(array_filter($leadData, fn($v) => $v !== null)),
    'fields_total' => count($leadData),
    'ai_model_used' => $this->store->ai_model,
    'response_tokens' => $tokenCount,
]);
```

#### **Debugging Webhook Payloads**

For support & debugging, maintain a separate webhook archive:

```php
// Store full webhook payloads for 24 hours (PII sensitive)
class WhatsAppController
{
    public function handle(Request $request, string $store_token): Response
    {
        $payload = $request->json()->all();
        
        // Archive for debugging (30 day retention)
        if (env('WEBHOOK_ARCHIVE_ENABLED', true)) {
            Storage::disk('webhook-archive')->put(
                "payload_{$store_token}_" . now()->timestamp . '.json',
                json_encode($payload, JSON_PRETTY_PRINT)
            );
        }

        // ... rest of processing
    }
}
```

> [!WARNING]  
> **GDPR/Privacy Consideration:** WhatsApp messages may contain personal data. Ensure webhook archive respects data retention policies. Consider tokenizing/anonymizing payloads before logging.

#### **WAF Configuration (ngx_http_limit_req_module)**

```nginx
# nginx.conf
limit_req_zone $binary_remote_addr zone=webhook_limit:10m rate=100r/s;
limit_req_zone $http_x_phone_number zone=phone_limit:10m rate=10r/s;

server {
    listen 443 ssl http2;
    server_name api.example.com;

    # Rate limiting by IP (DDoS mitigation)
    limit_req zone=webhook_limit burst=200 nodelay;
    
    # Rate limiting by phone number (per-customer throttle)
    limit_req zone=phone_limit burst=20 nodelay;

    # Security headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    location /api/whatsapp/webhook/ {
        proxy_pass http://app:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        
        # Timeout for webhook delivery (Meta waits 15 seconds max)
        proxy_read_timeout 15s;
        proxy_connect_timeout 5s;
    }
}
```

---

## 5. Developer Guide: Maintenance, Updates & Scaling

### 5.1 Adding New Features: Step-by-Step Guidelines

#### **Scenario: Adding Multi-Language Support**

**Step 1: Define Feature Scope**

```
Goal: Support conversations in Spanish, Portuguese, and French
Impact: Store model, system prompt, AI requests, responses
Risk: Prompt confusion, translation errors, misaligned lead extraction
```

**Step 2: Database Schema Changes**

```php
// database/migrations/add_language_support.php
Schema::table('stores', function (Blueprint $table) {
    $table->string('language', 5)->default('en');  // 'en', 'es', 'pt', 'fr'
    $table->json('language_config')->nullable();   // Per-language system prompt
});

Schema::table('conversations', function (Blueprint $table) {
    $table->string('language', 5)->default('en');
});
```

**Step 3: Extend State Variables in Job**

```php
class ProcessWhatsAppMessage implements ShouldQueue
{
    public function handle(): void
    {
        // Get conversation language (or detect from message)
        $conversation = Conversation::updateOrCreate(
            ['store_id' => $this->store->id, 'customer_phone' => $this->from],
            ['language' => $this->detectLanguage($this->messageBody)]
        );

        // Pass language to system prompt pipeline
        $systemPrompt = $this->buildSystemPrompt($conversation->language);
    }

    private function detectLanguage(string $text): string
    {
        // Use language detection library or AI model
        // Fallback to store's default language
        return detect_language($text) ?? $this->store->language ?? 'en';
    }

    private function buildSystemPrompt(string $language): string
    {
        $basePrompt = $this->store->getLocalizedSystemPrompt($language);
        $productContext = $this->buildProductContext($language);
        
        return "{$basePrompt}\n\n{$productContext}";
    }
}
```

**Step 4: Extend Product Model**

```php
// app/Models/Product.php
public function getLocalizedName(string $language = 'en'): string
{
    return $this->translations[$language]['name'] ?? $this->name;
}

public function getLocalizedDescription(string $language = 'en'): string
{
    return $this->translations[$language]['description'] ?? $this->description;
}
```

**Step 5: Update AI Requests**

```php
$aiEngine = AIServiceFactory::make($this->store);

$aiResponse = $aiEngine->getResponse(
    message: $this->messageBody,
    systemPrompt: $systemPrompt,
    conversationHistory: $history,
    language: $conversation->language  // NEW PARAMETER
);
```

**Step 6: Lead Extraction Adjustment**

```php
private function extractLeadDataWithAI(
    array $history,
    string $lastAiResponse,
    string $language = 'en'
): array {
    $extractionPrompt = match ($language) {
        'es' => <<<PROMPT
Extrae información del cliente. Devuelve SOLO JSON válido...
PROMPT,
        'pt' => <<<PROMPT
Extrair informações do cliente. Retorn APENAS JSON válido...
PROMPT,
        default => <<<PROMPT
Extract customer information. Return ONLY valid JSON...
PROMPT,
    };

    // ... rest of extraction
}
```

**Step 7: Validation & Testing**

```php
// tests/Feature/MultiLanguageSupportTest.php
public function test_spanish_conversation_extraction()
{
    $store = Store::factory()->create(['language' => 'es']);
    
    $job = new ProcessWhatsAppMessage(
        store: $store,
        from: '1234567890',
        messageBody: 'Hola, ¿cuál es el precio más bajo para el producto 42?'
    );

    $job->handle();

    $lead = Lead::where('customer_phone', '1234567890')->first();
    $this->assertNotNull($lead);
}
```

### 5.2 Modifying Negotiation Rules: Best Practices

#### **Safe Update Pattern**

**NEVER directly modify:**
```php
// ❌ WRONG: Hardcoding rules in job code
if ($offeredPrice < 79.99) {
    // Send message
}
```

**ALWAYS use system prompt + product data:**

```php
// ✓ CORRECT: Rules in database, delivered via prompt

Product::factory()->create([
    'id' => 42,
    'price' => 99.99,
    'floor_price' => 79.99,
    'ai_sales_strategy' => 'Emphasize warranty and durability to justify price',
]);

Store::factory()->create([
    'system_prompt' => <<<PROMPT
You are a premium product consultant.
NEGOTIATION RULES:
- Base price is firm at market rate
- Maximum discount: 15% for bulk orders (3+ units)
- Offer value-adds instead of deep discounts: extended warranty, free shipping
- DO NOT accept any price below floor price
PROMPT,
]);
```

#### **Testing Negotiation Changes**

```php
// tests/Feature/NegotiationLogicTest.php

public function test_floor_price_enforcement()
{
    $product = Product::factory()->create([
        'price' => 100,
        'floor_price' => 80,
    ]);

    $store = Store::factory()->create([
        'system_prompt' => 'Enforce floor price strictly.',
    ]);

    // Simulate customer offering below floor price
    $job = new ProcessWhatsAppMessage(
        store: $store,
        from: '5551234567',
        messageBody: 'Will you accept $75 for product ' . $product->id . '?'
    );

    $job->handle();

    // Verify AI response does not accept the offer
    $messages = WhatsAppMessage::where('role', 'assistant')
        ->where('customer_phone', '5551234567')
        ->latest()
        ->first();

    $this->assertStringNotContainsString('75', $messages->content);  // Should reject
    $this->assertStringContainsString('80', $messages->content);     // Reference floor
}

public function test_discount_hierarchy()
{
    $product = Product::factory()->create([
        'price' => 100,
        'floor_price' => 70,
    ]);

    $store = Store::factory()->create([
        'system_prompt' => <<<PROMPT
Discount hierarchy:
- 5% for single unit
- 10% for 2-5 units (minimum $90)
- 15% for 6+ units (minimum $85)
PROMPT,
    ]);

    // Test: Customer asks for 3 units
    $job = new ProcessWhatsAppMessage(
        store: $store,
        from: '5551234567',
        messageBody: 'How much for 3 units of product ' . $product->id . '?'
    );

    $job->handle();

    $messages = WhatsAppMessage::where('role', 'assistant')->latest()->first();
    
    // Should offer 10% discount = $90 per unit = $270 total
    $this->assertStringContainsString('90', $messages->content);
}
```

#### **Deployment Strategy for Rule Changes**

```bash
# Step 1: Create new system prompt in staging environment
$ php artisan tinker
>>> $store = Store::find(1);
>>> $store->system_prompt = "NEW RULES...";
>>> $store->save();

# Step 2: Run integration tests
$ php artisan test tests/Feature/NegotiationLogicTest.php

# Step 3: Deploy to production with feature flag
$ php artisan feature:enable negotiation_v2

# Step 4: Monitor for 24 hours
$ php artisan log:monitor production INFO

# Step 5: Rollback if issues
$ php artisan feature:disable negotiation_v2
$ php artisan cache:clear
```

### 5.3 Common Troubleshooting Scenarios

#### **Scenario 1: Null Extractions in Lead Generation**

**Problem:** Leads are created with `customer_name = null`, `meeting_point = null`

**Root Cause Analysis:**

```php
// Enable debug logging for extraction
Log::channel('production')->debug('LEAD_EXTRACTION_DEBUG', [
    'conversation_history' => $history,
    'ai_response_preview' => substr($aiResponse, 0, 500),
    'has_lead_token' => $hasLeadToken,
    'raw_extraction_response' => $extractionResponse,
]);
```

**Common Causes:**

| Cause | Symptom | Fix |
|-------|---------|-----|
| Customer never provided name | `customer_name = null` | Re-prompt customer before creating lead |
| AI response doesn't mention address | `meeting_point = null` | Ensure system prompt asks for address before lead completion |
| Regex fails on non-standard format | Address exists but not extracted | Update regex patterns to handle local address formats |
| JSON parsing error in extraction | All fields = null | Add fallback to regex extraction (already implemented) |

**Resolution Checklist:**

```php
public function shouldCreateLeadFromResponse(
    string $aiResponse,
    array $leadData,
    bool $hasLeadToken
): bool
{
    // Rejection reason
    if ($hasLeadToken && count(array_filter($leadData)) < 2) {
        Log::warning('LEAD_INCOMPLETE: Has token but missing critical fields', [
            'store_id' => $this->store->id,
            'missing_fields' => array_filter($leadData, fn($v) => $v === null),
        ]);

        // Send re-prompt to customer
        WhatsAppService::sendMessage(
            $this->from,
            "To complete your request, I need:\n"
            . ($leadData['customer_name'] === null ? "- Your full name\n" : "")
            . ($leadData['meeting_point'] === null ? "- Your meeting point\n" : ""),
            $this->store
        );

        return false;
    }

    return $hasLeadToken || count(array_filter($leadData)) >= 2;
}
```

#### **Scenario 2: Catalog Search Returns 0 Results**

**Problem:** Product search finds no matching products; AI falls back to generic responses

**Diagnosis:**

```php
private function getProductContextWithTypes(): ?array
{
    // ... previous logic ...

    // Pattern 3: No match fallback - LOG THE FAILURE
    if ($products->count() === 0) {
        Log::warning('PRODUCT_SEARCH_FAILED: No matches, falling back to catalog', [
            'store_id' => $this->store->id,
            'search_query' => substr($this->messageBody, 0, 100),
            'total_products_in_store' => Product::where('store_id', $this->store->id)->count(),
            'extracted_product_id' => $this->extractProductId($this->messageBody),
        ]);

        return [
            'type' => 'catalog_fallback',
            'products' => Product::where('store_id', $this->store->id)->limit(10)->get(),
            'context' => $this->buildCatalogContext(),
            'search_terms' => $this->getSearchTerms($this->messageBody),
        ];
    }

    return null;
}
```

**Root Causes:**

| Cause | Evidence | Fix |
|-------|----------|-----|
| FullText index not created | MySQL FullText search returns empty | Run migration: `ALTER TABLE products ADD FULLTEXT(name, description)` |
| Search terms too specific/rare | "Super ultra premium widget" doesn't match "Premium Widget" | Adjust AI system prompt to suggest product ID usage |
| Product table empty for store | No products at all | Bulk import products via CSV in Filament |
| Character encoding issue | UTF-8 names not matching (e.g., Spanish accents) | Ensure MySQL charset = utf8mb4 |

**Fix: Improve Search Fallback**

```php
private function buildCatalogContext(): string
{
    $products = Product::where('store_id', $this->store->id)
        ->get(['id', 'name', 'price', 'description'])
        ->map(fn($p) => sprintf(
            "- %s (ID: %d) - $%.2f - %s",
            $p->name,
            $p->id,
            $p->price,
            substr($p->description, 0, 100)
        ))
        ->join("\n");

    return <<<CONTEXT
No specific product found. Here are all available products:

$products

Please specify a product ID (e.g., "product 42") or tell me more about what you're looking for.
CONTEXT;
}
```

#### **Scenario 3: Queue Jobs Pile Up (Message Lag)**

**Problem:** WhatsApp messages accumulate in queue; customers wait >5 min for responses

**Diagnosis:**

```bash
# Check queue status
$ php artisan queue:failed

# Get queue depth
$ redis-cli LLEN queues:default

# Monitor queue workers
$ php artisan queue:work --verbose

# Inspect individual job
$ php artisan queue:retry <uuid>
```

**Common Causes:**

| Cause | Symptom | Fix |
|-------|---------|-----|
| Insufficient queue workers | Jobs pile up, no throughput | Increase `queue-worker` replicas in docker-compose.yml |
| API rate limiting | OpenAI/WhatsApp API returns 429 | Implement exponential backoff in AIService |
| Database connection pool exhausted | Queries hang | Increase `DB_POOL_SIZE`, check for long-running transactions |
| Memory leak in queue worker | Worker crashes after 100 jobs | Add memory limit check, restart worker periodically |

**Scaling Solution:**

```yaml
# docker-compose.yml - Increase queue consumers
queue-worker:
  deploy:
    replicas: 5  # Was 2, now 5
    resources:
      limits:
        cpus: '1'
        memory: 512M
```

**Monitor Real-Time:**

```php
// ArtisanCommand: monitor queue depth
class MonitorQueueDepth extends Command
{
    public function handle()
    {
        while (true) {
            $depth = Cache::store('redis')->connection()->llen('queues:default');
            $this->info("Queue depth: {$depth} jobs pending");
            
            if ($depth > 100) {
                $this->warn("⚠️ Queue is backing up! Increase workers.");
            }

            sleep(5);
        }
    }
}
```

#### **Scenario 4: AI Response Ignores Floor Price**

**Problem:** AI accepts offer below floor_price despite system prompt

**Root Cause:**

```php
// Likely: System prompt conflict or missing product context

Log::warning('AI_GUARDRAIL_BYPASS_DETECTED', [
    'store_id' => $this->store->id,
    'product_id' => $product->id,
    'floor_price' => $product->floor_price,
    'ai_accepted_price' => $extractedPrice,
    'system_prompt_length' => strlen($this->store->system_prompt),
]);
```

**Validation Checklist:**

```php
public function validateGuardrailsBeforeAI()
{
    // Check 1: System prompt mentions floor price
    if (!Str::contains($this->systemPrompt, 'floor')) {
        throw new PromptValidationException('System prompt missing floor price reference');
    }

    // Check 2: Product context is included
    if (empty($productContext)) {
        throw new ProductContextException('Product context missing from prompt');
    }

    // Check 3: No conflicting discount rules
    $conflictKeywords = ['always discount', 'minimum -', 'below cost'];
    foreach ($conflictKeywords as $keyword) {
        if (Str::contains(Str::lower($this->systemPrompt), Str::lower($keyword))) {
            throw new PromptConflictException("Conflicting rule found: {$keyword}");
        }
    }

    return true;
}
```

**Remediation:**

```php
// Override AI response if guardrail violated
$extractedPrice = $this->extractPrice($aiResponse);

if ($extractedPrice && $extractedPrice < $product->floor_price) {
    Log::error('GUARDRAIL_VIOLATION: AI accepted sub-floor price', [
        'ai_response' => $aiResponse,
        'extracted_price' => $extractedPrice,
        'floor_price' => $product->floor_price,
    ]);

    // Send corrected message
    $correctedResponse = "I appreciate the offer, but the lowest I can accept is $"
        . $product->floor_price . ". However, I can offer free expedited shipping...";

    WhatsAppService::sendMessage($this->from, $correctedResponse, $this->store);
    
    // Log incident for manual review
    return;
}
```

---

## Appendix: Configuration Reference

### Environment Variables

```bash
# .env
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
LOG_CHANNEL=production
LOG_LEVEL=info

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=whatsapp_bot
DB_USERNAME=root
DB_PASSWORD=secure_password
DB_QUEUE_CONNECTION=mysql

# Redis / Queue
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PASSWORD=redis_password
REDIS_PORT=6379

# AI Providers
AI_MODELS_OPENAI=gpt-4o,gpt-4o-mini
AI_MODELS_GROK=grok-beta
AI_MODELS_GEMINI=gemini-2.5-flash,gemini-2.5-pro

# WhatsApp
WHATSAPP_API_VERSION=v20.0

# Logging
WEBHOOK_ARCHIVE_ENABLED=true
SECURITY_LOG_RETENTION=90

# Feature Flags
FEATURES_MULTI_LANGUAGE=true
FEATURES_AUDIO_TRANSCRIPTION=true
FEATURES_LEAD_EXTRACTION=true
```

### Database Migrations Checklist

```bash
$ php artisan migrate --env=production

# Verify indices
$ php artisan tinker
>>> DB::select('SHOW INDEX FROM products WHERE Key_name LIKE \'FULLTEXT\'');

# Verify queue table
>>> DB::table('jobs')->count();

# Verify leads table
>>> DB::table('leads')->count();
```

### Deployment Checklist

- [ ] All environment variables configured in `.env`
- [ ] Database migrations executed
- [ ] Cache and queue configured (Redis)
- [ ] SSL certificates installed and valid
- [ ] WAF rules configured in Nginx
- [ ] Logging aggregation enabled (ELK stack or CloudWatch)
- [ ] Queue workers running (minimum 2 replicas)
- [ ] Health checks passing for all containers
- [ ] WhatsApp webhook test successful
- [ ] Rate limiting configured
- [ ] Backup strategy in place (DB, logs)
- [ ] Monitoring alerts configured

---

**End of Documentation**

---

### Quick Reference: API Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/whatsapp/webhook/{store_token}` | Webhook verification (Meta) |
| POST | `/api/whatsapp/webhook/{store_token}` | Webhook message ingestion (Meta) |

### Quick Reference: Key Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `ProcessWhatsAppMessage` | `app/Jobs/` | Core message processing job |
| `WhatsAppService` | `app/Services/` | Meta API integration |
| `ProductSearchService` | `app/Services/` | Product lookup & FullText search |
| `AIOrchestrator` | `app/Services/AI/` | System prompt construction, AI routing |
| `AIServiceFactory` | `app/Factories/` | LLM provider abstraction |
| `WhatsAppController` | `app/Http/Controllers/` | Webhook entry point |

---

*This documentation is maintained by the engineering team. For updates or corrections, submit a pull request or contact the Technical Architecture Lead.*
