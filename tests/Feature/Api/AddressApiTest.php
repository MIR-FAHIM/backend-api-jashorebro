<?php

namespace Tests\Feature\Api;

use App\Models\BusinessLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_addresses(): void
    {
        $response = $this->getJson('/api/addresses');
        $response->assertStatus(401);

        $response = $this->postJson('/api/addresses', []);
        $response->assertStatus(401);
    }

    public function test_authenticated_user_retrieves_empty_address_list(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/addresses');
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [],
            ]);
    }

    public function test_first_created_address_automatically_becomes_default(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = [
            'recipient_name' => 'Fahim Ahmed',
            'recipient_phone' => '01712345678',
            'country' => 'Bangladesh',
            'district' => 'Jashore',
            'upazila' => 'Jashore Sadar',
            'street_address' => 'House 12, Road 4, Mujib Sarak',
            'landmark' => 'Near Municipal Park',
            'postal_code' => '7400',
            'label' => 'Home',
            'is_default' => false, // Even if requested false, first address becomes default
        ];

        $response = $this->postJson('/api/addresses', $payload);
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'recipient_name' => 'Fahim Ahmed',
                    'locality_district' => 'Jashore',
                    'sub_district_thana' => 'Jashore Sadar',
                    'is_default' => true,
                ],
            ]);

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $user->id,
            'is_default' => 1,
            'locality_district' => 'Jashore',
        ]);

        // Verify audit log
        $this->assertDatabaseHas('business_logs', [
            'event' => 'address.created',
            'outcome' => 'success',
            'actor_user_id' => $user->id,
        ]);
    }

    public function test_creating_second_default_address_unsets_previous_default(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $addr1 = UserAddress::create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => 'Fahim Ahmed',
            'recipient_phone' => '01712345678',
            'country' => 'Bangladesh',
            'locality_district' => 'Jashore',
            'sub_district_thana' => 'Jashore Sadar',
            'street_address' => 'Old Address',
            'is_default' => true,
        ]);

        $response = $this->postJson('/api/addresses', [
            'recipient_name' => 'Fahim Work',
            'recipient_phone' => '01799887766',
            'district' => 'Jashore',
            'upazila' => 'Kotwali',
            'street_address' => 'Office Tower, Floor 4',
            'label' => 'Office',
            'is_default' => true,
        ]);

        $response->assertStatus(201);
        $newId = $response->json('data.id');

        $this->assertTrue(UserAddress::find($newId)->is_default);
        $this->assertFalse($addr1->fresh()->is_default);
    }

    public function test_setting_address_as_default_works(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $addr1 = UserAddress::create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => 'Fahim Ahmed',
            'recipient_phone' => '01712345678',
            'country' => 'Bangladesh',
            'locality_district' => 'Jashore',
            'sub_district_thana' => 'Jashore Sadar',
            'street_address' => 'Home address',
            'is_default' => true,
        ]);

        $addr2 = UserAddress::create([
            'user_id' => $user->id,
            'label' => 'Office',
            'recipient_name' => 'Fahim Ahmed',
            'recipient_phone' => '01712345678',
            'country' => 'Bangladesh',
            'locality_district' => 'Jashore',
            'sub_district_thana' => 'Chaugachha',
            'street_address' => 'Office address',
            'is_default' => false,
        ]);

        $response = $this->patchJson("/api/addresses/{$addr2->id}/default");
        $response->assertStatus(200);

        $this->assertFalse($addr1->fresh()->is_default);
        $this->assertTrue($addr2->fresh()->is_default);
    }

    public function test_deleting_default_address_promotes_remaining_address_to_default(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $addr1 = UserAddress::create([
            'user_id' => $user->id,
            'label' => 'Home',
            'recipient_name' => 'Fahim 1',
            'recipient_phone' => '01712345678',
            'country' => 'Bangladesh',
            'locality_district' => 'Jashore',
            'sub_district_thana' => 'Jashore Sadar',
            'street_address' => 'Addr 1',
            'is_default' => false,
        ]);

        $addr2 = UserAddress::create([
            'user_id' => $user->id,
            'label' => 'Office',
            'recipient_name' => 'Fahim 2',
            'recipient_phone' => '01712345678',
            'country' => 'Bangladesh',
            'locality_district' => 'Jashore',
            'sub_district_thana' => 'Keshabpur',
            'street_address' => 'Addr 2',
            'is_default' => true,
        ]);

        $response = $this->deleteJson("/api/addresses/{$addr2->id}");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('user_addresses', ['id' => $addr2->id]);
        $this->assertTrue($addr1->fresh()->is_default);
    }

    public function test_user_cannot_access_or_modify_another_users_address(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $addressB = UserAddress::create([
            'user_id' => $userB->id,
            'label' => 'Private',
            'recipient_name' => 'User B',
            'recipient_phone' => '01800000000',
            'country' => 'Bangladesh',
            'locality_district' => 'Dhaka',
            'sub_district_thana' => 'Dhanmondi',
            'street_address' => 'Road 27',
            'is_default' => true,
        ]);

        Sanctum::actingAs($userA);

        $this->getJson("/api/addresses/{$addressB->id}")->assertStatus(404);
        $this->putJson("/api/addresses/{$addressB->id}", ['recipient_name' => 'Hacker'])->assertStatus(404);
        $this->deleteJson("/api/addresses/{$addressB->id}")->assertStatus(404);
        $this->patchJson("/api/addresses/{$addressB->id}/default")->assertStatus(404);
    }

    public function test_order_creation_snapshots_saved_address(): void
    {
        $sellerUser = User::factory()->create();
        $seller = Seller::create([
            'user_id' => $sellerUser->id,
            'store_name' => 'Official Store',
            'slug' => 'official-store',
            'contact_phone' => '01711223344',
            'status' => 'active',
        ]);

        $category = \App\Models\Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Test Phone',
            'slug' => 'test-phone',
            'base_price' => 500.00,
            'stock_quantity' => 10,
            'status' => 'published',
            'is_normal_purchase_enabled' => true,
            'track_inventory' => true,
        ]);

        $customer = User::factory()->create();
        Sanctum::actingAs($customer);

        $address = UserAddress::create([
            'user_id' => $customer->id,
            'label' => 'Home',
            'recipient_name' => 'Customer Snapshot',
            'recipient_phone' => '01700112233',
            'country' => 'Bangladesh',
            'locality_district' => 'Jashore',
            'sub_district_thana' => 'Jashore Sadar',
            'street_address' => 'Snapshot Street 99',
            'is_default' => true,
        ]);

        $orderPayload = [
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'purchase_mode' => 'normal',
                ],
            ],
            'payment_method' => 'cod',
            'address_id' => $address->id,
        ];

        $response = $this->postJson('/api/orders', $orderPayload);
        $response->assertStatus(201);

        $orderId = $response->json('data.id');
        $order = Order::find($orderId);

        $this->assertEquals('Customer Snapshot', $order->shipping_name);
        $this->assertEquals('01700112233', $order->shipping_phone);
        $this->assertEquals('Jashore', $order->shipping_district);
        $this->assertEquals('Jashore Sadar', $order->shipping_upazila);
        $this->assertEquals('Snapshot Street 99', $order->shipping_address);

        // Modifying the address later must NOT change the historic order
        $address->update(['street_address' => 'Altered Future Street']);
        $this->assertEquals('Snapshot Street 99', $order->fresh()->shipping_address);
    }
}
