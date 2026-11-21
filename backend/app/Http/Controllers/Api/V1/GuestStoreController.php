<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GuestStoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Validate the actual query bag, not merged request input. Malformed
        // arrays must never be cast to "Array" or used as implicit filters.
        $validator = Validator::make($request->query(), [
            'country_code' => ['nullable', 'string', 'max:16'],
            'city' => ['nullable', 'string', 'max:150'],
            'area' => ['nullable', 'string', 'max:150'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            $locale = in_array(app()->getLocale(), ['ar', 'en'], true) ? app()->getLocale() : 'en';
            foreach ($request->getLanguages() as $preferredLanguage) {
                $candidate = strtolower(substr((string) $preferredLanguage, 0, 2));
                if (in_array($candidate, ['ar', 'en'], true)) {
                    $locale = $candidate;
                    break;
                }
            }

            $errors = [];
            foreach ($validator->errors()->keys() as $field) {
                $errors[$field] = [__('guest_store_query.invalid_filter', [], $locale)];
            }

            throw ValidationException::withMessages($errors);
        }

        $filters = $validator->validated();
        $perPage = (int) ($filters['per_page'] ?? 20);
        $countryCode = strtoupper(trim((string) ($filters['country_code'] ?? '')));
        $city = trim((string) ($filters['city'] ?? ''));
        $area = trim((string) ($filters['area'] ?? ''));

        $query = Store::query()
            ->select('stores.*', 'storefront_settings.theme_code', 'storefront_settings.header_address')
            ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
            ->leftJoin('storefront_settings', 'storefront_settings.store_id', '=', 'stores.id')
            ->where('stores.is_active', true)
            ->where('store_types.code', 'B2C')
            ->when($countryCode !== '' || $city !== '' || $area !== '', function ($query) use ($countryCode, $city, $area): void {
                $query->where(function ($query) use ($countryCode, $city, $area): void {
                    $query->whereNotExists(function ($zones): void {
                        $zones->selectRaw('1')->from('store_service_zones')
                            ->whereColumn('store_service_zones.store_id', 'stores.id')
                            ->where('store_service_zones.is_active', true);
                    })->orWhereExists(function ($zones) use ($countryCode, $city, $area): void {
                        $zones->selectRaw('1')->from('store_service_zones')
                            ->whereColumn('store_service_zones.store_id', 'stores.id')
                            ->where('store_service_zones.is_active', true)
                            ->when($countryCode !== '', fn ($q) => $q->where('store_service_zones.country_code', $countryCode))
                            ->when($city !== '', fn ($q) => $q->where(function ($q) use ($city): void {
                                $q->whereNull('store_service_zones.city')->orWhere('store_service_zones.city', $city);
                            }))
                            ->when($area !== '', fn ($q) => $q->where(function ($q) use ($area): void {
                                $q->whereNull('store_service_zones.area')->orWhere('store_service_zones.area', $area);
                            }));
                    });
                });
            })
            ->orderBy('stores.id');

        $stores = $query->paginate($perPage);

        return response()->json([
            'data' => collect($stores->items())
                ->map(static fn (Store $store): array => [
                    'id' => (int) $store->id,
                    'code' => $store->code,
                    'name' => $store->name,
                    'store_type' => 'B2C',
                    'channel' => 'b2c',
                    'theme_code' => (string) ($store->getAttribute('theme_code') ?? 'retail_grocery'),
                    'address' => $store->getAttribute('header_address'),
                    'logo_url' => $store->logo_path ? url('/'.ltrim((string) $store->logo_path, '/')) : null,
                    'is_active' => (bool) $store->is_active,
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $stores->currentPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
            ],
        ]);
    }
}
