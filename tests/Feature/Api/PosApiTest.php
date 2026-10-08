<?php

namespace Tests\Feature\Api;

use App\Models\BusinessLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $customerUser;
    protected Seller $seller;
    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->adminUser = User::factory()->create([
            'name' => 'Admin Operator',
            'email' => 'admin@jashorebro.com',
            'phone' => '01700000001',
        ]);
        $this->adminUser->roles()->attach($adminRole);

        $this->customerUser = User::factory()->create([
            'name' => 'Walkin Customer',
            'email' => 'customer@example.com',
            'phone' => '01711223344',
        ]);

        $this->seller = Seller::create([
            'user_id' => $this->adminUser->id,
            'store_name' => 'JashoreBro Official Store',
            'slug' => 'jashorebro-official',
            'contact_phone' => '01700000001',
            'status' => 'active',
        ]);

        $this->category = Category::create([
            'name' => 'Textiles',
            'slug' => 'textiles',
        ]);
    }

    public function test_non_admin_cannot_access_pos_endpoints(): void
    {
        Sanctum::actingAs($this->customerUser);

        $this->getJson('/api/admin/pos/products')->assertStatus(403);
        $this->getJson('/api/admin/pos/customers')->assertStatus(403);
        $this->postJson('/api/admin/pos/quote', [])->assertStatus(403);
        $this->postJson('/api/admin/pos/orders', [])->assertStatus(403);
    }

    public function test_admin_can_search_products_and_variants(): void
    {
        Sanctum::actingAs($this->adminUser);

        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'title' => 'Nakshi Kantha Premium',
            'slug' => 'nakshi-kantha-premium',
            'sku' => 'NK-001',
            'barcode' => '894000111222',
            'base_price' => 1200.00,
            'stock_quantity' => 15,
            'status' => 'published',
            'is_normal_purchase_enabled' => true,
            'track_inventory' => true,
        ]);

        $response = $this->getJson('/api/admin/pos/products?q=Nakshi');
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonFragment(['title' => 'Nakshi Kantha Premium']);

        // Search by barcode
        $this->getJson('/api/admin/pos/products?q=894000111222')
            ->assertStatus(200)
            ->assertJsonFragment(['barcode' => '894000111222']);
    }

    public function test_admin_can_search_customers_and_manage_addresses_on_their_behalf(): void
    {
        Sanctum::actingAs($this->adminUser);

        $searchRes = $this->getJson("/api/admin/pos/customers?q={$this->customerUser->phone}");
        $searchRes->assertStatus(200)
            ->assertJsonFragment(['name' => 'Walkin Customer']);

        // Add address on behalf of customer
        $addressPayload = [
            'recipient_name' => 'Walkin Customer',
            'recipient_phone' => '01711223344',
            'country' => 'Bangladesh',
            'district' => 'Jashore',
            'upazila' => 'Jashore Sadar',
            'street_address' => 'Station Road, Shop 14',
            'label' => 'Office',
            'is_default' => true,
        ];

        $addrRes = $this->postJson("/api/admin/customers/{$this->customerUser->id}/addresses", $addressPayload);
        $addrRes->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_id' => $this->customerUser->id,
                    'locality_district' => 'Jashore',
                    'is_default' => true,
                ],
            ]);

        $this->assertDatabaseHas('user_addresses', [
            'user_id' => $this->customerUser->id,
            'recipient_phone' => '01711223344',
            'is_default' => 1,
        ]);
    }

    public function test_pos_quote_calculates_decimal_safe_totals_and_enforces_discount_rules(): void
    {
        Sanctum::actingAs($this->adminUser);

        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'title' => 'Handmade Silk Scarf',
            'slug' => 'handmade-silk-scarf',
            'base_price' => 500.00,
            'stock_quantity' => 10,
            'status' => 'published',
            'is_normal_purchase_enabled' => true,
            'track_inventory' => true,
        ]);

        $quotePayload = [
            'customer_id' => $this->customerUser->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
            'discount_amount' => 100.00,
            'discount_reason' => 'VIP Customer Promotion',
            'shipping_fee' => 0.00,
        ];

        $res = $this->postJson('/api/admin/pos/quote', $quotePayload);
        $res->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'subtotal' => 1000.00,
                    'discount_amount' => 100.00,
                    'shipping_fee' => 0.00,
                    'total_amount' => 900.00,
                ],
            ]);

        // Discount cannot exceed subtotal
        $invalidQuote = $quotePayload;
        $invalidQuote['discount_amount'] = 1500.00;
        $this->postJson('/api/admin/pos/quote', $invalidQuote)->assertStatus(422);

        // Discount requires a reason
        $noReasonQuote = $quotePayload;
        $noReasonQuote['discount_reason'] = null;
        $this->postJson('/api/admin/pos/quote', $noReasonQuote)->assertStatus(422);
    }

    public function test_admin_completes_pos_sale_with_cash_and_change_calculation(): void
    {
        Sanctum::actingAs($this->adminUser);

        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'title' => 'Jashore Date Jaggery (Gur)',
            'slug' => 'jashore-date-jaggery',
            'base_price' => 250.00,
            'stock_quantity' => 20,
            'status' => 'published',
            'is_normal_purchase_enabled' => true,
            'track_inventory' => true,
        ]);

        $address = UserAddress::create([
            'user_id' => $this->customerUser->id,
            'label' => 'Home',
            'recipient_name' => 'Walkin Customer',
            'recipient_phone' => '01711223344',
            'country' => 'Bangladesh',
            'locality_district' => 'Jashore',
            'sub_district_thana' => 'Jashore Sadar',
            'street_address' => 'Chowrasta Market',
            'is_default' => true,
        ]);

        $orderPayload = [
            'idempotency_key' => 'pos-test-order-key-101',
            'customer_id' => $this->customerUser->id,
            'address_id' => $address->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                ],
            ],
            'payment_method' => 'cash',
            'amount_received' => 600.00, // Total is 500, change is 100
            'discount_amount' => 0.00,
            'shipping_fee' => 0.00,
            'notes' => 'Store counter pickup',
        ];

        $res = $this->postJson('/api/admin/pos/orders', $orderPayload);
        $res->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'user_id' => $this->customerUser->id,
                    'subtotal' => 500.00,
                    'total_amount' => 500.00,
                    'payment_method' => 'cash',
                    'payment_status' => 'paid',
                ],
            ]);

        // Product inventory decremented
        $this->assertEquals(18, $product->fresh()->stock_quantity);

        // Check order status history recorded
        $orderId = $res->json('data.id');
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $orderId,
            'actor_type' => 'admin',
        ]);

        // Retrying with same idempotency key returns existing order without creating duplicate
        $retryRes = $this->postJson('/api/admin/pos/orders', $orderPayload);
        $retryRes->assertStatus(200);
        $this->assertEquals($orderId, $retryRes->json('data.id'));
        $this->assertEquals(18, $product->fresh()->stock_quantity); // No double deduction
    }

    public function test_pos_sale_rejects_insufficient_cash(): void
    {
        Sanctum::actingAs($this->adminUser);

        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'title' => 'Item X',
            'slug' => 'item-x',
            'base_price' => 300.00,
            'stock_quantity' => 5,
            'status' => 'published',
            'is_normal_purchase_enabled' => true,
            'track_inventory' => true,
        ]);

        $orderPayload = [
            'customer_id' => $this->customerUser->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'payment_method' => 'cash',
            'amount_received' => 200.00, // Less than 300 total
        ];

        $res = $this->postJson('/api/admin/pos/orders', $orderPayload);
        $res->assertStatus(422)
            ->assertJsonValidationErrors(['amount_received']);

        // Stock not decremented
        $this->assertEquals(5, $product->fresh()->stock_quantity);
    }

    public function test_pos_sale_verifies_address_belongs_to_selected_customer(): void
    {
        Sanctum::actingAs($this->adminUser);

        $otherCustomer = User::factory()->create();
        $otherAddress = UserAddress::create([
            'user_id' => $otherCustomer->id,
            'label' => 'Home',
            'recipient_name' => 'Foreign User',
            'recipient_phone' => '01888888888',
            'country' => 'Bangladesh',
            'locality_district' => 'Dhaka',
            'sub_district_thana' => 'Mirpur',
            'street_address' => 'Road 1',
            'is_default' => true,
        ]);

        $product = Product::create([
            'seller_id' => $this->seller->id,
            'category_id' => $this->category->id,
            'title' => 'Item Y',
            'slug' => 'item-y',
            'base_price' => 100.00,
            'stock_quantity' => 5,
            'status' => 'published',
            'is_normal_purchase_enabled' => true,
            'track_inventory' => true,
        ]);

        // Attempting to use other customer's address for customerUser
        $orderPayload = [
            'customer_id' => $this->customerUser->id,
            'address_id' => $otherAddress->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'payment_method' => 'cod',
        ];

        $res = $this->postJson('/api/admin/pos/orders', $orderPayload);
        $res->assertStatus(404); // Address not found for this customer
    }
}
