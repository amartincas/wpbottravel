# Project Spec: WhatsApp AI Multi-Operator Tour Booking Orchestrator

## 1. Tech Stack
- **Framework:** Laravel 11 (PHP 8.3)
- **Environment:** Laravel Herd (Local Development)
- **Database:** MySQL 8.0 (with FullText support)
- **Cache/Session:** Redis
- **Admin Panel:** Laravel Filament
- **AI Providers:** OpenAI (GPT-4o) & X.ai (Grok-1)
- **Integration:** WhatsApp Business API (via Meta)

## 2. Database Schema

### Table: stores
- id (Primary Key)
- name (string)
- personality_type (enum: 'vendedor', 'soporte', 'asesor')
- system_prompt (text)
- ai_provider (enum: 'openai', 'grok')
- ai_model (string)
- wa_access_token (string)
- wa_phone_number_id (string)
- wa_verify_token (string)

### Table: products
- id (Primary Key)
- store_id (Foreign Key -> stores)
- name (string) - FullText Index
- description (text) - FullText Index
- price (decimal, 10, 2)
- stock (integer)

### Table: conversations
- id (Primary Key)
- store_id (Foreign Key -> stores)
- customer_phone (string)
- last_session_at (timestamp)

## 3. Core Logic
- **Product Identification:**
    1. Check for product ID in the incoming message.
    2. If no ID, perform a FullText search on 'name' and 'description'.
    3. If multiple matches found, instruct AI to ask for clarification.
- **AI Orchestration:**
    - Use a Service Pattern with an Adapter for AI providers.
    - Context must include: Store Personality + Product Data + Last 5 messages (from Redis).