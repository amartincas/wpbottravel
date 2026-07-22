<x-layouts::app :title="__('Dashboard')">
    {{-- Setup Incomplete Banner --}}
    @if (session('store_setup_incomplete') && auth()->user()->store)
        <div class="mb-4 bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-yellow-800">
                        ⚠️ <strong>Store Configuration Required</strong>
                    </p>
                    <p class="text-sm text-yellow-700 mt-1">
                        Your store still needs a system prompt configured before the bot can respond to customers. Please configure your store settings.
                    </p>
                    <a href="{{ route('filament.yes.pages.dashboard') }}" class="inline-block mt-2 bg-yellow-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-yellow-700">
                        Go to Store Settings
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- Platform Setup Incomplete Banner (superadmin only) --}}
    @if (session('platform_setup_incomplete') && auth()->user()->is_super_admin)
        <div class="mb-4 bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
            <div class="flex">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-yellow-800">
                        ⚠️ <strong>Platform WhatsApp/AI Configuration Required</strong>
                    </p>
                    <p class="text-sm text-yellow-700 mt-1">
                        The shared WhatsApp number and/or AI credentials are not configured yet. No store can receive messages until this is set.
                    </p>
                    <a href="{{ route('filament.admin.pages.whatsapp-platform-settings') }}" class="inline-block mt-2 bg-yellow-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-yellow-700">
                        Go to Platform Settings
                    </a>
                </div>
            </div>
        </div>
    @endif

    <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
            <div class="relative aspect-video overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
            </div>
        </div>
        <div class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
        </div>
    </div>
</x-layouts::app>
