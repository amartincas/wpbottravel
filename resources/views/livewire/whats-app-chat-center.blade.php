<div style="display: grid; grid-template-columns: repeat(12, 1fr); gap: 0; border: 1px solid #e5e7eb; height: 85vh; overflow: hidden; border-radius: 12px; background: white;">
   
    
    <div wire:poll.10s style="grid-column: span 4 / span 4; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; background: #f9fafb; height: 100%; min-height: 0; overflow: hidden;">
 
        {{-- Store filter (super admin only) — fixed height, never scrolls --}}
        @if(Auth::user()->is_super_admin)
            <div style="padding: 1rem; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
                <select wire:model.live="filterStoreId" style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; background: white;">
                    <option value="">Todas las Tiendas</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->id }}">{{ $store->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    
        {{-- Header — fixed height, never scrolls --}}
        <div style="padding: 16px; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
            <h2 style="font-weight: bold; color: black; margin: 0;">WhatsApp Chats</h2>
        </div>
    
        {{-- Conversation list — THIS is the only thing that scrolls --}}
        <div style="flex: 1; overflow-y: auto; min-height: 0; background: white;" class="custom-scrollbar">
            @foreach($conversations as $conversation)
                <button wire:click="selectConversation('{{ $conversation->customer_phone }}')"
                    style="width: 100%; padding: 12px; text-align: left; border: none; border-bottom: 1px solid #f3f4f6; cursor: pointer; {{ $selectedPhone === $conversation->customer_phone ? 'background-color: #f0fdf4;' : 'background: white;' }}">
                    <div style="font-size: 14px; font-weight: 500; color: black;">
                        {{ $conversation->customer_phone }}
                    </div>
                    <div style="font-size: 11px; color: #6b7280;">
                        {{ \Carbon\Carbon::parse($conversation->last_message_at)->diffForHumans() }}
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    <div style="grid-column: span 8 / span 8; display: flex; flex-direction: column; background: #e5ddd5; position: relative; height: 100%; overflow: hidden;">
        @if ($selectedPhone)
            <div style="display: flex; flex-direction: column; height: 100%;">
                
                <div style="padding: 1rem; border-bottom: 1px solid #e5e7eb; background: white; flex-shrink: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <h3 style="font-weight: 700; color: #111827; margin: 0;">{{ $selectedPhone }}</h3>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 12px; font-weight: 700; color: #4b5563;">BOT</span>
                            <input type="checkbox" wire:model.live="botActive" style="cursor: pointer; width: 16px; height: 16px;">
                        </div>
                    </div>
                </div>

                <div id="chat-container" wire:poll.3s style="flex: 1; overflow-y: auto; padding: 1.5rem; display: flex; flex-direction: column; gap: 1rem;">
                    @foreach ($messages as $message)
                        <div style="display: flex; width: 100%; justify-content: {{ $message->role === 'user' ? 'flex-start' : 'flex-end' }};">
                            <div style="max-width: 75%; padding: 0.6rem 1rem; border-radius: 12px; background: {{ $message->role === 'user' ? 'white' : '#dcf8c6' }}; box-shadow: 0 1px 1px rgba(0,0,0,0.1);">
                                <p style="font-size: 14px; margin: 0; word-wrap: break-word; white-space: pre-wrap; color: #111827;">{{ $message->content }}</p>
                                <div style="font-size: 10px; color: #6b7280; text-align: right; margin-top: 4px;">{{ $message->created_at->format('H:i') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div style="padding: 1rem; background: #f0f0f0; border-top: 1px solid #e5e7eb; flex-shrink: 0;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button onclick="document.getElementById('template-modal').style.display='flex'" 
                                title="Enviar Plantilla"
                                style="background: #3b82f6; color: white; padding: 0.6rem; border-radius: 50%; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                            ⚡
                        </button>

                        <input type="text" wire:model="newMessage" wire:keydown.enter="sendMessage" 
                            placeholder="Escribe un mensaje..." 
                            style="flex: 1; padding: 0.6rem; border-radius: 20px; border: 1px solid #ccc; outline: none; color: black;">
                        
                        <button wire:click="sendMessage" style="background: #25d366; color: white; padding: 0.5rem 1.2rem; border-radius: 20px; font-weight: bold; border: none; cursor: pointer;">
                            Enviar
                        </button>
                    </div>
                </div>
            </div>

            <div id="template-modal" style="display: none; position: absolute; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; align-items: center; justify-content: center; padding: 2rem;">
                <div style="background: white; border-radius: 12px; width: 100%; max-width: 500px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
                    <div style="padding: 1rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="font-weight: 700; color: black; margin: 0;">Plantillas de WhatsApp</h4>
                        <button onclick="document.getElementById('template-modal').style.display='none'" style="border: none; background: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
                    </div>
                    
                    <div style="padding: 1rem; overflow-y: auto; max-height: 400px;">
                        <p style="font-size: 0.875rem; color: #4b5563; margin-bottom: 1rem;">Selecciona una plantilla para enviar. Los campos {{1}}, {{2}}, etc. deben ser completados.</p>
                        
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            @foreach(\App\Models\WhatsAppTemplate::where('store_id', Auth::user()->store_id)->get() as $tpl)
                                <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px; cursor: pointer; hover:bg-gray-50;" 
                                     onclick="selectTemplate('{{ $tpl->id }}', '{{ $tpl->name }}', {{ json_encode($tpl->parameters_map) }})">
                                    <div style="font-weight: bold; font-size: 13px; color: #2563eb;">{{ $tpl->name }}</div>
                                    <div style="font-size: 12px; color: #6b7280;">{{ $tpl->body_preview }}</div>
                                </div>
                            @endforeach
                        </div>

                        <div id="template-form" style="display: none; margin-top: 1.5rem; border-top: 2px solid #f3f4f6; padding-top: 1rem;">
                            <h5 id="selected-tpl-name" style="font-size: 14px; font-weight: bold; margin-bottom: 10px; color: black;"></h5>
                            <div id="dynamic-inputs" style="display: flex; flex-direction: column; gap: 8px;"></div>
                            <button onclick="submitTemplate()" style="margin-top: 15px; width: 100%; background: #2563eb; color: white; padding: 10px; border-radius: 8px; font-weight: bold; border: none; cursor: pointer;">
                                Enviar Plantilla Oficial
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        @else
            <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #6b7280;">
                <p>Seleccione un chat para comenzar a conversar</p>
            </div>
        @endif
    </div>
</div>

<script>
    let currentTemplateId = null;

    function selectTemplate(id, name, paramsMap) {
        currentTemplateId = id;
        document.getElementById('template-form').style.display = 'block';
        document.getElementById('selected-tpl-name').innerText = 'Configurar: ' + name;
        
        const container = document.getElementById('dynamic-inputs');
        container.innerHTML = '';

        // Generar inputs basados en el mapa de parámetros {{1}}, {{2}}, etc.
        Object.keys(paramsMap).forEach(key => {
        const div = document.createElement('div');
        div.innerHTML = `
            <label style="font-size: 11px; font-weight: bold; color: #374151;">
                Dato para @{{${key}}} (${paramsMap[key]}): 
            </label>
            <input type="text" class="tpl-param" data-key="${key}" style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; color: black;">
        `;
        container.appendChild(div);
    });
    }

    async function submitTemplate() {
        const inputs = document.querySelectorAll('.tpl-param');
        const customValues = {};
        inputs.forEach(input => {
            customValues[input.getAttribute('data-key')] = input.value;
        });

        const payload = {
            lead_id: @json($selectedLeadId ?? 0),
            template_id: currentTemplateId,
            custom_values: customValues
        };

        try {
            const response = await fetch('/api/whatsapp/templates/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + @json(Auth::user()->api_token ?? ''),
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify(payload)
            });

            const result = await response.json();
            if (result.success) {
                alert('Plantilla enviada con éxito');
                document.getElementById('template-modal').style.display = 'none';
                Livewire.dispatch('template-sent');
            } else {
                alert('Error: ' + result.message);
            }
        } catch (error) {
            alert('Error en el servidor al enviar la plantilla.');
        }
    }

    document.addEventListener('livewire:initialized', () => {
        const container = document.getElementById('chat-container');
        const scrollDown = () => { if (container) container.scrollTop = container.scrollHeight; }
        scrollDown();
        Livewire.on('scroll-down', () => { setTimeout(scrollDown, 50); });
    });
</script>