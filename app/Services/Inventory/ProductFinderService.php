<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ProductFinderService
{
    /**
     * List of generic/short search terms that should trigger full catalog return
     */
    private const GENERIC_SEARCH_TERMS = [
        'precio', 'price', 'costo', 'cost',
        'taller', 'workshop', 'class', 'course',
        'información', 'information', 'info', 'details',
        'catalogo', 'catalog', 'lista', 'list',
        'productos', 'products', 'servicios', 'services',
        'oferta', 'offer', 'promo', 'promotion',
        'disponible', 'available', 'que hay', 'what', 'opciones', 'options'
    ];

    /**
     * Search for products/services by query string in the store.
     * Returns array with formatted context and type information.
     * If generic/short search or no results found, returns full catalog.
     *
     * @param string $query
     * @param int $storeId
     * @param int $limit
     * @return array ['context' => string, 'products' => Collection, 'hasServices' => bool, 'hasProducts' => bool]
     */
    public function findProductsWithTypes(string $query, int $storeId, int $limit = 3): array
    {
        $queryLower = strtolower(trim($query));
        $isGenericQuery = $this->isGenericQuery($queryLower);

        Log::info("PRODUCT_FINDER: Search Parameters", [
            'store_id' => $storeId,
            'query' => $query,
            'query_length' => strlen($queryLower),
            'is_generic' => $isGenericQuery,
        ]);

        // If generic or very short query, return full catalog
        if ($isGenericQuery) {
            Log::info("PRODUCT_FINDER: Generic query detected, fetching full catalog", [
                'store_id' => $storeId,
                'query' => $query,
            ]);

            $products = Product::where('store_id', $storeId)
                ->with(['images', 'availableExtras'])
                ->limit($limit)
                ->get(['id', 'name', 'price', 'cost_price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);
        } else {
            // Specific search query - use LIKE with wildcards
            $searchTerm = "%{$query}%";
            
            // Debug: Log all available products in this store for mismatch detection
            $allProductsInStore = Product::where('store_id', $storeId)->get(['id', 'name', 'type']);
            $availableNames = $allProductsInStore->pluck('name')->toArray();
            
            Log::info("PRODUCT_FINDER: Database inventory for store", [
                'store_id' => $storeId,
                'total_products_in_store' => $allProductsInStore->count(),
                'available_product_names' => $availableNames,
            ]);
            
            Log::info("PRODUCT_FINDER: Executing specific search", [
                'store_id' => $storeId,
                'search_pattern' => $searchTerm,
                'sql_preview' => "SELECT * FROM products WHERE store_id = {$storeId} AND (name LIKE '{$searchTerm}' OR description LIKE '{$searchTerm}')",
            ]);

            $products = Product::where('store_id', $storeId)
                ->where(function (Builder $builder) use ($searchTerm) {
                    $builder->where('name', 'LIKE', $searchTerm)
                        ->orWhere('description', 'LIKE', $searchTerm);
                })
                ->with(['images', 'availableExtras'])
                ->limit($limit)
                ->get(['id', 'name', 'price', 'cost_price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

            Log::info("PRODUCT_FINDER: Search result count", [
                'store_id' => $storeId,
                'query' => $query,
                'results_found' => $products->count(),
            ]);

            // If specific search returned nothing, fall back to full catalog
            if ($products->isEmpty()) {
                Log::warning("PRODUCT_FINDER: Specific search returned no results, falling back to full catalog", [
                    'store_id' => $storeId,
                    'original_query' => $query,
                ]);

                $products = Product::where('store_id', $storeId)
                    ->with(['images', 'availableExtras'])
                    ->limit($limit)
                    ->get(['id', 'name', 'price', 'cost_price', 'description', 'stock', 'type', 'ai_sales_strategy', 'faq_context', 'required_customer_info']);

                Log::info("PRODUCT_FINDER: Fallback catalog result", [
                    'store_id' => $storeId,
                    'fallback_results' => $products->count(),
                ]);
            }
        }

        $hasServices = $products->where('type', 'service')->isNotEmpty();
        $hasProducts = $products->where('type', 'product')->isNotEmpty();

        Log::info("PRODUCT_FINDER: Final result", [
            'store_id' => $storeId,
            'total_products' => $products->count(),
            'has_services' => $hasServices,
            'has_products' => $hasProducts,
        ]);

        return [
            'context' => $this->formatProducts($products),
            'products' => $products,
            'hasServices' => $hasServices,
            'hasProducts' => $hasProducts,
        ];
    }

    /**
     * Resolve which store a customer message belongs to by matching product
     * names contained within the message text, across ALL stores.
     *
     * Used when the WhatsApp number is shared across every operator, so the
     * store can no longer be identified from Meta's phone_number_id.
     *
     * Direction matters: this checks whether a product's (short) name is
     * CONTAINED WITHIN the (longer) customer message — the inverse of
     * findProductsWithTypes()'s LIKE direction, which rarely matches full
     * customer sentences.
     *
     * @param string $message
     * @return array{store: ?Store, ambiguousStores: ?Collection, matchedProducts: Collection}
     */
    public function resolveStoreByMention(string $message): array
    {
        $text = trim($message);

        if ($text === '' || mb_strlen($text) < 3 || $this->isGenericQuery(strtolower($text))) {
            return ['store' => null, 'ambiguousStores' => null, 'matchedProducts' => collect()];
        }

        $matched = Product::query()
            ->select(['id', 'store_id', 'name'])
            ->get()
            ->filter(fn (Product $product) => filled($product->name) && mb_stripos($text, $product->name) !== false);

        $storeIds = $matched->pluck('store_id')->unique();

        Log::info('PRODUCT_FINDER: Cross-store resolution by mention', [
            'message' => $text,
            'matched_product_count' => $matched->count(),
            'matched_store_ids' => $storeIds->values()->all(),
        ]);

        if ($storeIds->count() === 1) {
            return [
                'store' => Store::find($storeIds->first()),
                'ambiguousStores' => null,
                'matchedProducts' => $matched,
            ];
        }

        if ($storeIds->count() > 1) {
            return [
                'store' => null,
                'ambiguousStores' => Store::whereIn('id', $storeIds)->get(),
                'matchedProducts' => $matched,
            ];
        }

        return ['store' => null, 'ambiguousStores' => null, 'matchedProducts' => collect()];
    }

    /**
     * Resuelve a qué tienda pertenece un mensaje usando el ID del anuncio de
     * Meta (Click-to-WhatsApp), tomado de `referral.source_id` en el webhook.
     *
     * Es más confiable que el matching por texto libre (resolveStoreByMention):
     * el anunciante configura este ID una sola vez por campaña, así que no
     * depende de que el mensaje prellenado del anuncio coincida exactamente
     * con el nombre del producto en el catálogo.
     *
     * @param  string|null $adId
     * @return array{store: ?Store, ambiguousStores: ?Collection, matchedProducts: Collection}
     */
    public function resolveStoreByAdId(?string $adId): array
    {
        if (!$adId) {
            return ['store' => null, 'ambiguousStores' => null, 'matchedProducts' => collect()];
        }

        $matched = Product::query()
            ->whereJsonContains('meta_ad_ids', $adId)
            ->get(['id', 'store_id', 'name']);

        $storeIds = $matched->pluck('store_id')->unique();

        Log::info('PRODUCT_FINDER: Resolución por ad_id de Meta', [
            'ad_id' => $adId,
            'matched_product_count' => $matched->count(),
            'matched_store_ids' => $storeIds->values()->all(),
        ]);

        if ($storeIds->count() === 1) {
            return [
                'store' => Store::find($storeIds->first()),
                'ambiguousStores' => null,
                'matchedProducts' => $matched,
            ];
        }

        if ($storeIds->count() > 1) {
            return [
                'store' => null,
                'ambiguousStores' => Store::whereIn('id', $storeIds)->get(),
                'matchedProducts' => $matched,
            ];
        }

        return ['store' => null, 'ambiguousStores' => null, 'matchedProducts' => collect()];
    }

    /**
     * Check if the search query is generic/short and should return full catalog.
     *
     * @param string $query
     * @return bool
     */
    protected function isGenericQuery(string $query): bool
    {
        // If query is very short (1-2 chars), it's too generic
        if (strlen($query) <= 2) {
            return true;
        }

        // Check against known generic terms
        foreach (self::GENERIC_SEARCH_TERMS as $term) {
            if (stripos($query, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search for products by query string in the store.
     * Legacy method for backward compatibility.
     *
     * @param string $query
     * @param int $storeId
     * @param int $limit
     * @return string Formatted product results
     */
    public function findProducts(string $query, int $storeId, int $limit = 3): string
    {
        $result = $this->findProductsWithTypes($query, $storeId, $limit);
        return $result['context'];
    }

    /**
     * Format products and services for display.
     *
     * @param Collection $products
     * @return string
     */
    private function formatProducts(Collection $products): string
    {
        if ($products->isEmpty()) {
            return "No offerings found.";
        }

        $formatted = "📦 **Available Offerings:**\n\n";

        foreach ($products as $product) {
            if ($product->type === 'service') {
                $availability = $this->getServiceAvailability($product->stock);
                $formatted .= sprintf(
                    "🔧 **%s** (Service) - $%.2f\n  📝 %s\n  %s\n",
                    $product->name,
                    $product->price,
                    $this->truncateDescription($product->description),
                    $availability
                );
            } else {
                $stockStatus = $this->getStockStatus($product->stock);
                $formatted .= sprintf(
                    "📦 **%s** (Product) - $%.2f\n  📝 %s\n  %s\n",
                    $product->name,
                    $product->price,
                    $this->truncateDescription($product->description),
                    $stockStatus
                );
            }

            // Add images if available
            if ($product->images && $product->images->count() > 0) {
                $formatted .= "  🖼️ Images:\n";
                // Sort images: primary first, then by ID
                $sortedImages = $product->images->sortByDesc('is_primary')->sortBy('id');
                foreach ($sortedImages as $image) {
                    $formatted .= "    - [IMG:{$image->id}] Product image\n";
                }
            } else {
                $formatted .= "  🖼️ Images: None\n";
            }

            // Add database fields for AI sales & rules
            if (!empty($product->ai_sales_strategy)) {
                $formatted .= "  💼 Sales Strategy: " . $product->ai_sales_strategy . "\n";
            }

            if (!empty($product->faq_context)) {
                $formatted .= "  ❓ Rules & FAQ: " . $product->faq_context . "\n";
            }

            if (!empty($product->required_customer_info)) {
                $formatted .= "  📋 Required Data: " . $product->required_customer_info . "\n";
            }

            // Extras disponibles del producto
            if ($product->availableExtras && $product->availableExtras->isNotEmpty()) {
                $formatted .= "  ➕ **Extras disponibles:**\n";
                foreach ($product->availableExtras as $extra) {
                    $formatted .= "    • " . $extra->name
                        . " — $" . number_format($extra->sale_price, 0, ',', '.')
                        . ($extra->description ? " (" . $extra->description . ")" : "")
                        . "\n";
                }
                $formatted .= "  ⚠️ Solo puedes ofrecer los extras listados. Si el cliente pide algo no listado, indica que no está disponible.\n";
            }

            $formatted .= "\n";
        }

        return $formatted;
    }

    /**
     * Get human-readable service availability status.
     *
     * @param int $stock (1 = accepting clients, 0 = fully booked)
     * @return string
     */
    private function getServiceAvailability(int $stock): string
    {
        return $stock === 1
            ? "✅ Currently Accepting New Clients"
            : "❌ Fully Booked - Not Accepting New Clients";
    }

    /**
     * Get human-readable stock status.
     *
     * @param int $stock
     * @return string
     */
    private function getStockStatus(int $stock): string
    {
        if ($stock <= 0) {
            return "❌ Out of Stock";
        } elseif ($stock < 5) {
            return "⚠️ Low Stock ({$stock} units)";
        } elseif ($stock < 20) {
            return "✅ In Stock ({$stock} units)";
        } else {
            return "✅ In Stock ({$stock} units)";
        }
    }

    /**
     * Truncate description to a reasonable length.
     *
     * @param string $description
     * @param int $maxLength
     * @return string
     */
    private function truncateDescription(string $description, int $maxLength = 80): string
    {
        if (strlen($description) <= $maxLength) {
            return $description;
        }

        return substr($description, 0, $maxLength) . '...';
    }
}
