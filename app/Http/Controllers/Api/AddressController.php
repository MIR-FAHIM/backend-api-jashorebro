<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserAddress;
use App\Services\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddressController extends Controller
{
    public function __construct(
        protected LogService $logService
    ) {}

    /**
     * List all saved delivery addresses for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $addresses = UserAddress::where('user_id', $user->id)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $addresses,
        ]);
    }

    /**
     * Store a new delivery address for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'recipient_name' => 'required|string|max:100',
            'recipient_phone' => 'required|string|max:30',
            'country' => 'nullable|string|max:100',
            'district' => 'required_without:locality_district|nullable|string|max:100',
            'locality_district' => 'nullable|string|max:100',
            'upazila' => 'required_without:sub_district_thana|nullable|string|max:100',
            'sub_district_thana' => 'nullable|string|max:100',
            'street_address' => 'required|string|max:500',
            'landmark' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'delivery_instructions' => 'nullable|string|max:500',
            'label' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
        ]);

        $district = $validated['district'] ?? $validated['locality_district'] ?? 'Jashore';
        $upazila = $validated['upazila'] ?? $validated['sub_district_thana'] ?? '';
        $country = $validated['country'] ?? 'Bangladesh';
        $label = ! empty($validated['label']) ? trim($validated['label']) : 'Home';

        try {
            $address = DB::transaction(function () use ($user, $validated, $district, $upazila, $country, $label, $request) {
                $existingCount = UserAddress::where('user_id', $user->id)->count();

                // First saved address automatically becomes default
                $isDefault = ($existingCount === 0) ? true : $request->boolean('is_default', false);

                if ($isDefault) {
                    UserAddress::where('user_id', $user->id)->update(['is_default' => false]);
                }

                $newAddress = UserAddress::create([
                    'user_id' => $user->id,
                    'label' => $label,
                    'recipient_name' => $validated['recipient_name'],
                    'recipient_phone' => $validated['recipient_phone'],
                    'country' => $country,
                    'locality_district' => $district,
                    'sub_district_thana' => $upazila,
                    'street_address' => $validated['street_address'],
                    'landmark' => $validated['landmark'] ?? null,
                    'postal_code' => $validated['postal_code'] ?? null,
                    'delivery_instructions' => $validated['delivery_instructions'] ?? null,
                    'is_default' => $isDefault,
                ]);

                // Record audit log inside transaction
                $this->logService->record(
                    event: 'address.created',
                    outcome: 'success',
                    actor: $user,
                    subject: $newAddress,
                    metadata: [
                        'address_id' => $newAddress->id,
                        'label' => $newAddress->label,
                        'district' => $newAddress->locality_district,
                        'is_default' => $newAddress->is_default,
                    ]
                );

                return $newAddress;
            });

            return response()->json([
                'success' => true,
                'message' => 'Delivery address saved successfully.',
                'data' => $address,
            ], 201);
        } catch (\Throwable $e) {
            $this->logService->record(
                event: 'address.created',
                outcome: 'failure',
                actor: $user,
                subject: null,
                metadata: [
                    'failure_reason' => $e->getMessage(),
                ]
            );
            throw $e;
        }
    }

    /**
     * Show a single address belonging to the user.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $address,
        ]);
    }

    /**
     * Update an existing address belonging to the authenticated user.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        $validated = $request->validate([
            'recipient_name' => 'sometimes|required|string|max:100',
            'recipient_phone' => 'sometimes|required|string|max:30',
            'country' => 'nullable|string|max:100',
            'district' => 'sometimes|nullable|string|max:100',
            'locality_district' => 'nullable|string|max:100',
            'upazila' => 'sometimes|nullable|string|max:100',
            'sub_district_thana' => 'nullable|string|max:100',
            'street_address' => 'sometimes|required|string|max:500',
            'landmark' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'delivery_instructions' => 'nullable|string|max:500',
            'label' => 'sometimes|nullable|string|max:50',
            'is_default' => 'nullable|boolean',
        ]);

        try {
            DB::transaction(function () use ($user, $address, $validated, $request) {
                $wantDefault = $request->has('is_default') ? $request->boolean('is_default') : null;

                if ($wantDefault === true && ! $address->is_default) {
                    UserAddress::where('user_id', $user->id)
                        ->where('id', '!=', $address->id)
                        ->update(['is_default' => false]);
                    $address->is_default = true;
                } elseif ($wantDefault === false && $address->is_default) {
                    // If demoting current default, assign default to another address if exists
                    $nextAddress = UserAddress::where('user_id', $user->id)
                        ->where('id', '!=', $address->id)
                        ->latest('id')
                        ->first();

                    if ($nextAddress) {
                        $nextAddress->update(['is_default' => true]);
                        $address->is_default = false;
                    }
                    // If it's the only address, it must stay default
                }

                if (isset($validated['recipient_name'])) {
                    $address->recipient_name = $validated['recipient_name'];
                }
                if (isset($validated['recipient_phone'])) {
                    $address->recipient_phone = $validated['recipient_phone'];
                }
                if (array_key_exists('country', $validated)) {
                    $address->country = $validated['country'] ?? 'Bangladesh';
                }
                if (isset($validated['district']) || isset($validated['locality_district'])) {
                    $address->locality_district = $validated['district'] ?? $validated['locality_district'];
                }
                if (isset($validated['upazila']) || isset($validated['sub_district_thana'])) {
                    $address->sub_district_thana = $validated['upazila'] ?? $validated['sub_district_thana'];
                }
                if (isset($validated['street_address'])) {
                    $address->street_address = $validated['street_address'];
                }
                if (array_key_exists('landmark', $validated)) {
                    $address->landmark = $validated['landmark'];
                }
                if (array_key_exists('postal_code', $validated)) {
                    $address->postal_code = $validated['postal_code'];
                }
                if (array_key_exists('delivery_instructions', $validated)) {
                    $address->delivery_instructions = $validated['delivery_instructions'];
                }
                if (isset($validated['label'])) {
                    $address->label = trim($validated['label']) ?: 'Home';
                }

                $address->save();

                $this->logService->record(
                    event: 'address.updated',
                    outcome: 'success',
                    actor: $user,
                    subject: $address,
                    metadata: [
                        'address_id' => $address->id,
                        'label' => $address->label,
                        'district' => $address->locality_district,
                        'is_default' => $address->is_default,
                    ]
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Delivery address updated successfully.',
                'data' => $address->fresh(),
            ]);
        } catch (\Throwable $e) {
            $this->logService->record(
                event: 'address.updated',
                outcome: 'failure',
                actor: $user,
                subject: $address,
                metadata: [
                    'failure_reason' => $e->getMessage(),
                ]
            );
            throw $e;
        }
    }

    /**
     * Delete an address belonging to the authenticated user.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        try {
            DB::transaction(function () use ($user, $address) {
                $wasDefault = $address->is_default;
                $addressId = $address->id;
                $addressLabel = $address->label;
                $addressDistrict = $address->locality_district;

                $address->delete();

                // If deleted address was default, promote another saved address to default
                if ($wasDefault) {
                    $nextDefault = UserAddress::where('user_id', $user->id)
                        ->latest('id')
                        ->first();

                    if ($nextDefault) {
                        $nextDefault->update(['is_default' => true]);
                    }
                }

                $this->logService->record(
                    event: 'address.deleted',
                    outcome: 'success',
                    actor: $user,
                    subject: null,
                    metadata: [
                        'address_id' => $addressId,
                        'label' => $addressLabel,
                        'district' => $addressDistrict,
                        'was_default' => $wasDefault,
                    ]
                );
            });

            return response()->json([
                'success' => true,
                'message' => 'Delivery address removed successfully.',
            ]);
        } catch (\Throwable $e) {
            $this->logService->record(
                event: 'address.deleted',
                outcome: 'failure',
                actor: $user,
                subject: $address,
                metadata: [
                    'failure_reason' => $e->getMessage(),
                ]
            );
            throw $e;
        }
    }

    /**
     * Atomically set the given address as the user's primary default address.
     */
    public function setDefault(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $address = UserAddress::where('user_id', $user->id)->findOrFail($id);

        DB::transaction(function () use ($user, $address) {
            UserAddress::where('user_id', $user->id)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);

            $address->update(['is_default' => true]);

            $this->logService->record(
                event: 'address.default_changed',
                outcome: 'success',
                actor: $user,
                subject: $address,
                metadata: [
                    'address_id' => $address->id,
                    'label' => $address->label,
                    'district' => $address->locality_district,
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Default delivery address updated.',
            'data' => $address->fresh(),
        ]);
    }
}
