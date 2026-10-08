# JashoreBro Catalog, Variant & Group-Buy API Contract

**Version:** 1.0.0  
**Base URL:** `/api`  
**Envelope Convention:**
All endpoints adhere strictly to the JSON response envelope:
```json
{
  "success": true,
  "message": "Optional human-readable message",
  "data": { ... } | [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 95
  }
}
```
Validation failure envelope (HTTP 422):
```json
{
  "success": false,
  "message": "The given data was invalid.",
  "errors": {
    "field_name": ["Specific validation error message"]
  }
}
```

---

## 1. Global Types, Formats & Money Representation

### Money Format
- **Rule:** All prices in the API are represented as **decimal numbers formatted with 2 decimal places in BDT** (e.g. `1200.00`, `999.50`) or as standard floating-point numbers serializable to decimals.
- **Server Internal:** MySQL `DECIMAL(10,2)`. No rounding tricks or integer cents confusion.
- **Internal Protection:** `cost_price`, `created_by`, and `updated_by` are strictly **excluded from customer-facing responses**.

### Timestamp Format
- Standard ISO-8601 strings in UTC/local timezone: `2026-10-08T12:00:00.000000Z` or `YYYY-MM-DD HH:mm:ss`.

### Enums & Defaults
| Entity | Field | Type / Enum Values | Default | Customer Visibility |
| :--- | :--- | :--- | :--- | :--- |
| Product | `status` | `'draft'`, `'published'`, `'archived'` | `'draft'` | Only `'published'` visible to customers |
| Product | `visibility` | `'public'`, `'hidden'` | `'public'` | Only `'public'` visible in feeds/search |
| Product | `currency` | string | `'BDT'` | Always visible |
| Product | `is_normal_purchase_enabled` | boolean | `true` | Governs "Buy Now / Add to Cart" button |
| Product | `is_group_buy_enabled` | boolean | `false` | Governs eligibility for Volume Drops |
| Product | `is_featured` | boolean | `false` | Highlights in featured sections |
| Product | `is_new_arrival` | boolean | `false` | Highlights in new arrival collections |
| Product | `is_bestseller` | boolean | `false` | Displays bestseller badge |
| Product | `track_inventory` | boolean | `true` | When true, enforces stock limits |
| Product | `is_cod_available` | boolean | `true` | Allows Cash on Delivery at checkout |
| Product | `is_free_shipping` | boolean | `false` | Sets shipping fee to 0 when true |
| Product | `is_returnable` | boolean | `true` | Displays return policy |
| Product | `return_window_days` | integer | `7` | Maximum days after delivery for returns |
| Product | `is_cancelable` | boolean | `true` | Allows customer cancellation |
| Product | `cancellation_cutoff_hours`| integer | `24` | Cutoff after order placement |
| Campaign| `status` | `'draft'`, `'scheduled'`, `'active'`, `'succeeded'`, `'failed'`, `'cancelled'` | `'draft'` | Public when `'active'` or `'succeeded'` |
| Order | `status` | `'pending'`, `'processing'`, `'shipped'`, `'delivered'`, `'cancelled'` | `'pending'` | Visible on order tracking |
| Order | `payment_status` | `'unpaid'`, `'paid'`, `'refunded'` | `'unpaid'` | Visible on order summary |
| Order | `payment_method` | `'cod'`, `'bkash'`, `'nagad'` | `'cod'` | Selected at checkout |

### Backward Compatibility Mapping
- `is_active` $\Leftrightarrow$ `status === 'published' && visibility === 'public'`
- `is_drop_ready` $\Leftrightarrow$ `is_group_buy_enabled === true && status === 'published'`

---

## 2. Authentication & Roles
- **Admins:** Requires `Authorization: Bearer <sanctum_token>` where user has role `admin` or `super_admin`.
- **Customers:** Requires `Authorization: Bearer <sanctum_token>` for checkout, order history, and group-buy joining.
- **Guests:** Public read-only browsing of active published products and drop campaigns.

---

## 3. Admin Product Endpoints

### 3.1 List Products (Admin)
- **Method:** `GET`
- **Path:** `/api/admin/products`
- **Headers:** `Authorization: Bearer <token>`
- **Query Params:**
  - `q` (string): Search in title, SKU, barcode, tags.
  - `status` (string): `all`, `draft`, `published`, `archived`.
  - `category_id` (int): Filter by category.
  - `is_group_buy_enabled` (boolean): `0` or `1`.
  - `page` (int): Page number (default: 1).
  - `per_page` (int): Items per page (default: 20).
- **Response (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Authentic Keshabpur Khejur Patali Gur (1kg Slab)",
      "slug": "authentic-keshabpur-khejur-patali-gur-1kg",
      "sku": "JB-GUR-01",
      "status": "published",
      "visibility": "public",
      "base_price": 650.00,
      "compare_price": 780.00,
      "cost_price": 480.00,
      "stock_quantity": 250,
      "track_inventory": true,
      "is_normal_purchase_enabled": true,
      "is_group_buy_enabled": true,
      "is_featured": true,
      "category": { "id": 1, "name": "Date Palm Jaggery" },
      "seller": { "id": 1, "store_name": "JashoreBro Direct" },
      "primary_image": { "id": 10, "image_url": "https://...", "alt_text": "..." },
      "variants_count": 3,
      "created_at": "2026-10-08T10:00:00Z"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 1 }
}
```

### 3.2 Create Product (Admin)
- **Method:** `POST`
- **Path:** `/api/admin/products`
- **Headers:** `Authorization: Bearer <token>`
- **Request Body (JSON):**
```json
{
  "title": "Keshabpur Clay Chai Cup Set of 6",
  "slug": "keshabpur-clay-chai-cup-set-6",
  "category_id": 2,
  "brand": "Heritage Clayworks",
  "short_description": "Handmade porous terracotta cups crafted in Keshabpur.",
  "description": "Full description here...",
  "sku": "JB-CUP-01",
  "barcode": "890123456789",
  "status": "draft",
  "visibility": "public",
  "currency": "BDT",
  "base_price": 450.00,
  "compare_price": 550.00,
  "cost_price": 280.00,
  "stock_quantity": 80,
  "track_inventory": true,
  "low_stock_threshold": 10,
  "min_order_quantity": 1,
  "max_order_quantity": 5,
  "is_normal_purchase_enabled": true,
  "is_group_buy_enabled": false,
  "is_featured": false,
  "is_new_arrival": true,
  "is_bestseller": false,
  "is_cod_available": true,
  "is_free_shipping": false,
  "shipping_charge": 60.00,
  "is_returnable": true,
  "return_window_days": 7,
  "is_cancelable": true,
  "cancellation_cutoff_hours": 24,
  "weight_kg": 0.85,
  "dimensions": { "length": 15, "width": 10, "height": 8, "unit": "cm" },
  "tags": ["terracotta", "handmade", "keshabpur", "tea"],
  "specification_attributes": [
    { "attribute_id": 4, "attribute_item_id": 12, "custom_value": null }
  ],
  "variants": [
    {
      "name": "Natural Terracotta",
      "sku": "JB-CUP-01-TER",
      "price_override": null,
      "compare_price": null,
      "stock_quantity": 50,
      "group_price": null,
      "attribute_item_ids": [12],
      "is_active": true
    }
  ]
}
```
- **Response (201):** Returns full product resource.

### 3.3 Get Product Detail (Admin)
- **Method:** `GET`
- **Path:** `/api/admin/products/{id}`
- **Headers:** `Authorization: Bearer <token>`
- **Response (200):** Full product with `cost_price`, `images`, `specification_attributes`, and `variants`.

### 3.4 Update Product (Admin)
- **Method:** `PUT` or `PATCH`
- **Path:** `/api/admin/products/{id}`
- **Headers:** `Authorization: Bearer <token>`
- **Behavior:**
  - Omitted fields remain unchanged.
  - Existing variants with matching `id` or matching attribute combinations are preserved along with their historical references.
  - Server-side recalculates aggregate stock if variants are present.
- **Response (200):** Updated product resource.

### 3.5 Archive / Delete Product (Admin)
- **Method:** `DELETE`
- **Path:** `/api/admin/products/{id}`
- **Headers:** `Authorization: Bearer <token>`
- **Response (200):** `{ "success": true, "message": "Product archived successfully." }`

### 3.6 Upload Product Images (Admin)
- **Method:** `POST`
- **Path:** `/api/admin/products/{id}/images`
- **Headers:** `Authorization: Bearer <token>` (Content-Type: `multipart/form-data`)
- **Body:**
  - `images[]`: File array (max 5MB each, jpeg/png/webp)
  - `is_primary`: optional boolean
- **Response (200):** Array of uploaded image objects with `id`, `image_url`, `is_primary`, `sort_order`.

### 3.7 Update / Reorder Images (Admin)
- **Method:** `PUT`
- **Path:** `/api/admin/products/{id}/images`
- **Headers:** `Authorization: Bearer <token>`
- **Body:**
```json
{
  "images": [
    { "id": 10, "is_primary": true, "sort_order": 0, "alt_text": "Front view" },
    { "id": 11, "is_primary": false, "sort_order": 1, "alt_text": "Detail view" }
  ]
}
```

### 3.8 Delete Image (Admin)
- **Method:** `DELETE`
- **Path:** `/api/admin/products/{id}/images/{imageId}`
- **Headers:** `Authorization: Bearer <token>`

---

## 4. Admin Attributes & Attribute Items Endpoints

### 4.1 List Attributes
- **Method:** `GET`
- **Path:** `/api/admin/attributes`
- **Response (200):** All attributes with their items.
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Color",
      "slug": "color",
      "type": "color",
      "sort_order": 1,
      "is_active": true,
      "is_variant": true,
      "items": [
        { "id": 1, "label": "Charcoal Black", "value": "black", "color_code": "#1a1a1a" },
        { "id": 2, "label": "Indigo Blue", "value": "indigo", "color_code": "#2e3a87" }
      ]
    }
  ]
}
```

### 4.2 Create Attribute
- **Method:** `POST`
- **Path:** `/api/admin/attributes`
- **Body:** `{ "name": "Size", "slug": "size", "type": "button", "is_variant": true }`

### 4.3 Add / Update Attribute Items
- **Method:** `POST`
- **Path:** `/api/admin/attributes/{id}/items`
- **Body:** `{ "label": "Extra Large", "value": "XL", "sort_order": 4 }`

---

## 5. Group-Buy / Volume Drops Endpoints

### 5.1 List Campaigns (Admin)
- **Method:** `GET`
- **Path:** `/api/admin/drop-campaigns`
- **Query Params:** `status`, `product_id`, `page`

### 5.2 Create Campaign (Admin)
- **Method:** `POST`
- **Path:** `/api/admin/drop-campaigns`
- **Body:**
```json
{
  "product_id": 1,
  "product_variant_id": null,
  "title": "Winter Khejur Gur Collective Drop",
  "group_price": 520.00,
  "target_participants": 20,
  "max_participants": 50,
  "quantity_limit_per_customer": 2,
  "start_at": "2026-10-08T00:00:00Z",
  "end_at": "2026-10-15T23:59:59Z"
}
```

### 5.3 Public Active Campaign Details
- **Method:** `GET`
- **Path:** `/api/drop-campaigns/{id}` or `/api/products/{slug}/active-campaign`
- **Response (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "title": "Winter Khejur Gur Collective Drop",
    "status": "active",
    "group_price": 520.00,
    "target_participants": 20,
    "current_distinct_participants": 14,
    "remaining_needed": 6,
    "progress_percent": 70,
    "quantity_limit_per_customer": 2,
    "start_at": "2026-10-08T00:00:00Z",
    "end_at": "2026-10-15T23:59:59Z",
    "product": { ... }
  }
}
```

### 5.4 Join Group-Buy Campaign (Customer)
- **Method:** `POST`
- **Path:** `/api/drop-campaigns/{id}/join`
- **Headers:** `Authorization: Bearer <token>`
- **Body:**
```json
{
  "quantity": 1,
  "product_variant_id": null,
  "shipping_name": "Fahim Ahmed",
  "shipping_phone": "+8801712345678",
  "shipping_district": "Jashore",
  "shipping_upazila": "Jashore Sadar",
  "shipping_address": "Holding 42, Mujib Sarak"
}
```
- **Response (201):** Pending group reservation order created.

---

## 6. Customer Catalog & Checkout Endpoints

### 6.1 Public Products List
- **Method:** `GET`
- **Path:** `/api/products`
- **Rules:** Only returns `status === 'published'` and `visibility === 'public'`. Excludes `cost_price`.

### 6.2 Public Product Detail
- **Method:** `GET`
- **Path:** `/api/products/{slug}`
- **Response (200):** Detailed product with images, specification attributes, active variants, seller info, and active group-buy campaign if available.

### 6.3 Checkout Quote / Revalidation
- **Method:** `POST`
- **Path:** `/api/checkout/quote`
- **Body:**
```json
{
  "items": [
    {
      "product_id": 1,
      "variant_id": 2,
      "quantity": 2,
      "purchase_mode": "normal"
    }
  ],
  "shipping_upazila": "Jashore Sadar"
}
```
- **Response (200):**
```json
{
  "success": true,
  "data": {
    "subtotal": 1440.00,
    "shipping_fee": 60.00,
    "discount_amount": 0.00,
    "total_amount": 1500.00,
    "currency": "BDT",
    "items": [ ... ]
  }
}
```

### 6.4 Place Order (Customer)
- **Method:** `POST`
- **Path:** `/api/orders`
- **Headers:** `Authorization: Bearer <token>`
- **Body:**
```json
{
  "items": [
    {
      "product_id": 1,
      "variant_id": 2,
      "quantity": 2,
      "purchase_mode": "normal",
      "campaign_id": null
    }
  ],
  "payment_method": "cod",
  "shipping_name": "Fahim Ahmed",
  "shipping_phone": "+8801712345678",
  "shipping_district": "Jashore",
  "shipping_upazila": "Jashore Sadar",
  "shipping_address": "Holding 42, Mujib Sarak",
  "notes": "Please call before arrival"
}
```
- **Response (201):** Full order record with status `'pending'`, payment_status `'unpaid'`.

### 6.5 Customer Order History & Cancellation
- **List Orders:** `GET /api/orders`
- **Show Order:** `GET /api/orders/{id}`
- **Cancel Order:** `POST /api/orders/{id}/cancel` (Only allowed if `status === 'pending'`)

### 6.6 Admin Orders & Fulfillment Management
- **List Orders (Admin):** `GET /api/admin/orders`
  - Query Params: `q` (search order number, customer name, phone, address), `status`, `payment_status`, `page`
- **Show Order Details (Admin):** `GET /api/admin/orders/{id}`
- **Update Dispatch / Payment Status (Admin):** `PATCH /api/admin/orders/{id}/status`
  - Body:
  ```json
  {
    "status": "shipped",
    "payment_status": "paid",
    "notes": "Dispatched via Doratana Hub Rider #4"
  }
  ```
  - Note: Transitions to `cancelled` automatically restock reserved inventory and release group campaign participant counts.

---

## 7. Error Handling & Common Codes
- **401 Unauthorized:** Invalid or missing Bearer token.
- **403 Forbidden:** Authenticated user is not an admin on admin routes, or customer attempting access to another customer's orders.
- **422 Unprocessable Entity:** Validation errors with field-keyed array of messages.
- **409 Conflict:** Stock exhausted or group campaign capacity reached.
