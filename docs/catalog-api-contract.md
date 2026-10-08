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

### 6.5 Customer Order History, Details & Timeline
- **List Orders:** `GET /api/orders`
- **Show Order:** `GET /api/orders/{id}`
  - Includes `status_histories` array (chronological timeline) with customer-visible fields: `id`, `from_status`, `to_status`, `actor_type`, `reason`, `occurred_at`.
  - **Security Guarantee:** `internal_note` and staff user details are stripped and NEVER exposed to customers.
- **Paginated History:** `GET /api/orders/{id}/history`
- **Cancel Order:** `POST /api/orders/{id}/cancel`
  - Body: `{ "reason": "Optional customer reason" }`
  - Permitted only when `status` is `'pending'` or `'awaiting_group'`.

### 6.6 Admin Orders & Fulfillment Management
- **List Orders (Admin):** `GET /api/admin/orders`
  - Query Params: `q` (search order number, customer name, phone, address), `status`, `payment_status`, `page`
- **Show Order Details (Admin):** `GET /api/admin/orders/{id}`
  - Includes full `status_histories` array with `actor_type`, `changed_by_user` (name, username), `reason`, `internal_note`, and `event_key`.
- **Paginated History (Admin):** `GET /api/admin/orders/{id}/history`
- **Execute Permitted Status Transition (Admin):** `PATCH /api/admin/orders/{id}/status`
  - Body:
  ```json
  {
    "status": "shipped",
    "payment_status": "paid",
    "reason": "Dispatched via Doratana Hub Rider #4",
    "internal_note": "Tracking ID: DH-90812, batch 4",
    "event_key": "optional-idempotency-key"
  }
  ```

### 6.7 Order Status Transition Rules & State Machine
Every transition is append-only, validated, and executed in an atomic transaction locking the order (`lockForUpdate`).

| Current Status | Target Status | Permitted Actors | Side Effects / Rules |
| :--- | :--- | :--- | :--- |
| `null` (Initial) | `awaiting_group` | `system`, `customer` | Used when joining an active, uncompleted volume drop |
| `null` (Initial) | `pending` | `system`, `customer`, `admin` | Used for standard orders or unlocked drops |
| `awaiting_group` | `pending` | `system`, `admin` | Auto-triggered when drop reaches target quota |
| `awaiting_group` | `cancelled` | `customer`, `admin`, `system` | Releases inventory & campaign reservation |
| `pending` | `processing` | `admin` | Merchant begins fulfillment |
| `pending` | `cancelled` | `customer`, `admin`, `system` | Customer cancellation allowed online |
| `processing` | `shipped` | `admin` | Dispatched to courier / local hub |
| `processing` | `pending` | `admin` | Correction regression (requires explanation `reason`) |
| `processing` | `cancelled` | `admin`, `system` | Restocks inventory |
| `shipped` | `delivered` | `admin` | Successfully delivered to customer |
| `shipped` | `processing` | `admin` | Correction regression (requires explanation `reason`) |
| `shipped` | `cancelled` | `admin`, `system` | Restocks inventory |
| `delivered` | *Terminal* | — | No further transitions |
| `cancelled` | *Terminal* | — | No further transitions |

---

## 7. Error Handling & Common Codes
- **401 Unauthorized:** Invalid or missing Bearer token.
- **403 Forbidden:** Authenticated user is not an admin on admin routes, or customer attempting access to another customer's orders.
- **422 Unprocessable Entity:** Validation errors with field-keyed array of messages.
- **409 Conflict:** Stock exhausted or group campaign capacity reached.

---

## 8. Business Log Management APIs (Centralized Audit Trail)

### 8.1 Architecture & Design
- **Append-Only Immutability:** Business logs in `business_logs` have no `updated_at` column and no edit/delete endpoints.
- **Strict Separation:** `order_status_histories` powers customer-facing order timelines; `business_logs` powers administrative audit, compliance, and governance reporting.
- **Transactional Consistency:**
  - Successful business actions write their log record within the active database transaction (`DB::transaction`). If the transaction aborts/rolls back, the success log is automatically removed.
  - Failures are captured in external `try ... catch` blocks outside the transaction, persisting the failure reason safely.
- **Security & Privacy:** Passwords, tokens, OTPs, secret keys, credit card credentials, and raw request bodies are explicitly blocked via strict field allowlisting and sanitization.
- **Traceability:** Every log records a correlation `request_id` (from incoming `X-Request-ID` or server-generated UUID), client `ip_address`, `user_agent`, actor ID, captured actor role at the moment of the event, polymorphic `subject_type` and `subject_id`, sanitized JSON `metadata`, and `occurred_at`.

### 8.2 Logged Business Events
- **Authentication:**
  - `auth.login.succeeded`: User authenticated successfully.
  - `auth.login.failed`: Login rejected (invalid credentials or account suspended).
  - `auth.registration.succeeded`: New customer created.
  - `auth.registration.failed`: Registration validation failed.
  - `auth.logout.succeeded`: User token revoked.
- **Orders:**
  - `order.created`: Order placed and inventory reserved.
  - `order.status_changed`: State transition executed.
  - `order.cancelled`: Order cancelled and inventory restocked.
  - `order.creation_failed`: Checkout failed due to stock depletion or campaign limits.
- **Products:**
  - `product.created`: New product draft or item added.
  - `product.updated`: Product specifications, pricing, or variants edited.
  - `product.published`: Product published to customer catalog.
  - `product.archived`: Product soft-deleted/archived.
  - `product.creation_failed`: Product validation error during store.

### 8.3 Endpoints
All endpoints require `Authorization: Bearer <token>` and administrative role (`admin` or `super_admin`).

#### `GET /api/admin/logs`
List filterable, paginated audit logs.
- **Query Parameters:**
  - `category`: `authentication` | `order` | `product` | `all`
  - `event`: e.g. `order.created`, `auth.login.failed`
  - `outcome`: `success` | `failure` | `all`
  - `actor_id`: Numeric user ID
  - `actor_role`: `admin` | `customer` | `system` | `anonymous` | `all`
  - `subject_type`: `order` | `product` | `user` or FQCN
  - `subject_id`: Numeric entity ID
  - `request_id`: Correlation UUID
  - `start_date`: ISO datetime or YYYY-MM-DD
  - `end_date`: ISO datetime or YYYY-MM-DD
  - `q`: Free-text search matching message, event, request ID, IP
  - `page`: Page number (default: 1)
  - `per_page`: Items per page (default: 20, max: 100)

#### `GET /api/admin/logs/summary`
Get aggregated event counts and success/failure breakdown matching current query filters.
- **Query Parameters:** Supports the same filter parameters as `GET /api/admin/logs`.

#### `GET /api/admin/logs/{id}`
Retrieve complete detail of a specific business audit log including loaded relations.

---

## 9. In-App Notification System APIs

### 9.1 Architecture & Design
- **Database Notifications:** Implemented using Laravel's database notification schema (`notifications` table) with custom first-class indexed columns (`audience`, `event`, `title`, `message`, `subject_type`, `subject_id`, `action_url`, `dedup_key`, `read_at`) and JSON `data`.
- **Audience Context Isolation:** Notifications are categorized as `customer` or `admin`. Queries and unread counts can be filtered by `context=customer` or `context=admin`.
- **Strict Recipient Scoping:** Every notification query, count, and read mutation is strictly scoped to `$request->user()->notifications()`. Admin privileges never expose other users' private notification inboxes.
- **Transactional Safety:** When business actions execute inside a database transaction (`DB::transaction`), notifications are dispatched via `DB::afterCommit(...)`. Rolled-back transactions produce NO notifications.
- **Idempotency & Deduplication:** Notifications accept a deterministic `dedup_key` (e.g. `order_created_cust_{id}`). Duplicate triggers or retries never generate duplicate notification records.
- **Action Destinations:** Action routes are resolved server-side (e.g. `/orders/{id}` for customers, `/admin/orders` for admins, `/admin/catalog` for products).

### 9.2 Notification Events Matrix

| Domain | Event | Target Audience | Title / Message | Destination |
| :--- | :--- | :--- | :--- | :--- |
| **Auth** | `auth.customer_welcome` | Customer | Welcome to JashoreBro! 🎉 | `/profile` |
| **Auth** | `auth.new_customer_registered` | Admin | New Customer Registered | `/admin/users` |
| **Orders** | `order.created` | Customer | Order Placed: #{order_number} | `/orders/{id}` |
| **Orders** | `order.created_admin` | Admin | New Order: #{order_number} | `/admin/orders` |
| **Orders** | `order.status_updated` | Customer | Order #{order_number} Update | `/orders/{id}` |
| **Orders** | `order.cancelled` | Customer | Order #{order_number} Cancelled | `/orders/{id}` |
| **Orders** | `order.cancelled_admin` | Admin | Order #{order_number} Cancelled | `/admin/orders` |
| **Products** | `product.created_admin` | Admin | New Product Draft Added | `/admin/catalog` |
| **Products** | `product.published_admin` | Admin | Product Published | `/admin/catalog` |
| **Group Buy** | `group_buy.joined` | Customer | Joined Drop: {title} 🎯 | `/orders/{id}` |
| **Group Buy** | `group_buy.unlocked` | Customer | Drop Goal Unlocked! 🎉 | `/orders` |
| **Group Buy** | `group_buy.target_reached_admin` | Admin | Drop Goal Achieved: {title} | `/admin/drops` |
| **Group Buy** | `group_buy.cancelled` | Customer | Volume Drop Update: {title} | `/orders` |

### 9.3 Endpoints

#### `GET /api/notifications`
Get paginated in-app notifications for the authenticated user.
- **Headers:** `Authorization: Bearer <token>`
- **Query Parameters:**
  - `context`: `customer` | `admin` (default: `customer`)
  - `filter`: `all` | `unread` (default: `all`)
  - `page`: Page number (default: 1)
  - `per_page`: Items per page (default: 15, max: 50)

#### `GET /api/notifications/unread-count`
Get real-time unread notification badge count.
- **Headers:** `Authorization: Bearer <token>`
- **Query Parameters:** `context`: `customer` | `admin`

#### `PATCH /api/notifications/{id}/read`
Idempotently mark a single notification as read.
- **Headers:** `Authorization: Bearer <token>`

#### `POST /api/notifications/mark-all-read`
Mark all unread notifications in the specified context as read.
- **Headers:** `Authorization: Bearer <token>`
- **Body:** `{ "context": "customer" }` (or `"admin"`)

---

## 10. Customer Delivery Address Management APIs

### 10.1 Architecture & Design
- **Authenticated Tenancy:** All address operations (`GET`, `POST`, `PUT`, `DELETE`, `PATCH`) are protected by `auth:sanctum`. Ownership is derived exclusively from `$request->user()->id`; client-submitted `user_id` payloads are strictly ignored.
- **Single Default Invariance:** Exactly zero or one default address is maintained per customer. When a customer adds their first address, it automatically becomes the default (`is_default = true`). Setting or creating an address with `is_default = true` updates all other customer addresses to `false` in an atomic database transaction.
- **Automatic Default Fallback:** If the customer deletes their default address, the system automatically designates another existing address as the default if one remains.
- **Immutable Order Fulfillment Snapshot:** During checkout (`POST /api/orders`), customers can provide `address_id`. The backend verifies customer ownership and snapshots recipient details, street address, upazila, and district into the `orders` table. Any subsequent edits or deletions to the customer's address book have zero effect on historical orders.
- **Audit Logging:** Every address mutation triggers `LogService::record` with safe metadata:
  - `address.created`: Logs address ID, label, and district.
  - `address.updated`: Logs address ID, label, and district.
  - `address.deleted`: Logs address ID, label, and was_default flag.
  - `address.default_changed`: Logs new default address ID and label.
  - Raw street addresses, payment details, and full personal telephone numbers are never logged in metadata.

### 10.2 Endpoints

#### `GET /api/addresses`
List all saved delivery addresses for the authenticated customer.
- **Headers:** `Authorization: Bearer <token>`
- **Response `200 OK`:**
  ```json
  {
    "success": true,
    "data": [
      {
        "id": 1,
        "user_id": 4,
        "label": "Home",
        "recipient_name": "Fahim Ahmed",
        "recipient_phone": "01712345678",
        "country": "Bangladesh",
        "district": "Jashore",
        "locality_district": "Jashore",
        "upazila": "Jashore Sadar",
        "sub_district_thana": "Jashore Sadar",
        "street_address": "House 12, Road 4, Mujib Sarak",
        "landmark": "Near Municipal Park",
        "postal_code": "7400",
        "delivery_instructions": "Call before arrival",
        "is_default": true,
        "created_at": "2026-10-08T07:30:00.000000Z",
        "updated_at": "2026-10-08T07:30:00.000000Z"
      }
    ]
  }
  ```

#### `POST /api/addresses`
Create a new delivery address for the authenticated customer.
- **Headers:** `Authorization: Bearer <token>`
- **Body:**
  ```json
  {
    "recipient_name": "Fahim Ahmed",
    "recipient_phone": "01712345678",
    "country": "Bangladesh",
    "district": "Jashore",
    "upazila": "Jashore Sadar",
    "street_address": "House 12, Road 4, Mujib Sarak",
    "landmark": "Near Municipal Park",
    "postal_code": "7400",
    "delivery_instructions": "Gate code #1234",
    "label": "Home",
    "is_default": true
  }
  ```
- **Response `201 Created`:** Address object with assigned `id` and `is_default`.

#### `GET /api/addresses/{id}`
Retrieve a specific address belonging to the authenticated customer.
- **Headers:** `Authorization: Bearer <token>`
- **Response:** `200 OK` or `404 Not Found` if address belongs to another user.

#### `PUT /api/addresses/{id}`
Update an existing address belonging to the authenticated customer.
- **Headers:** `Authorization: Bearer <token>`
- **Response `200 OK`:** Updated address record.

#### `DELETE /api/addresses/{id}`
Delete a delivery address belonging to the authenticated customer.
- **Headers:** `Authorization: Bearer <token>`
- **Response `200 OK`:** `{ "success": true, "message": "Delivery address removed successfully." }`

#### `PATCH /api/addresses/{id}/default`
Atomically mark the given address as the customer's primary default address.
- **Headers:** `Authorization: Bearer <token>`
- **Response `200 OK`:** `{ "success": true, "message": "Default delivery address updated.", "data": { ... } }`

### 10.3 Frontend UX & Session Behavior
- **Auto-Prompt on Zero Addresses:** Upon logging in, if the customer has 0 saved addresses, an "Add your delivery address" dialog appears automatically.
- **Resilient Error Handling:** Network errors or loading states do not trigger empty address behavior.
- **Session Dismissal ("Later"):** If the customer selects "Later", `jb_address_setup_dismissed_{user_id}` is stored in `sessionStorage` to prevent re-opening while browsing during the same session.
- **Strict Checkout Validation:** Checkout checks that a valid delivery address is selected or entered before permitting order submission.
- **Customer Profile Hub:** Dedicated "Saved Addresses" tab at `/profile` allows customers to add, edit, delete, and set default addresses anytime.

---

## 11. Admin Point of Sale (POS) System

### 11.1 Overview & Architecture
The Admin Point of Sale (POS) terminal enables authorized store administrators (`admin`, `super_admin`) to process walk-in customer purchases directly from physical retail counters or hub stores in Jashore.

Key architectural requirements:
- **Authorized Execution:** Endpoints protected by `auth:sanctum` and `role:admin,super_admin`.
- **Customer Assignment:** Every sale is bound to a registered customer. Admins can search existing customers or register new delivery addresses on their behalf.
- **Strict Address Tenancy:** Delivery addresses submitted during POS checkout must belong strictly to the selected customer.
- **Server-Side Pricing & Quotes:** The backend computes subtotal, discounts, shipping, tax, and grand totals with decimal precision. Client-submitted prices are never trusted.
- **Discount Controls:** Manual discounts require a mandatory explanation/reason (`discount_reason`) and cannot exceed the cart subtotal.
- **Payment Tendering & Cash Change:** Supports Cash, bKash, Nagad, and COD. For Cash transactions, `amount_received` must be $\ge$ `total_amount`, and change (`change_amount = amount_received - total_amount`) is calculated server-side and recorded.
- **Inventory Concurrency:** Uses `lockForUpdate()` during checkout to atomically deduct product and variant inventory and prevent race conditions.
- **Idempotency Guarantee:** Client submits a unique `idempotency_key` (UUID). Repeated submissions return the previously created order without deducting inventory twice.
- **Audit Logging & Notifications:** Emits structured events to `LogService` (`pos.order_created`, `pos.payment_confirmed`, `pos.discount_applied`, `address.created_by_admin`) and sends an in-app notification to the customer via `NotificationService`.

### 11.2 API Endpoints

#### `GET /api/admin/pos/products`
Search active marketplace catalog items by keyword, SKU, or barcode for adding to the POS cart.
- **Query Parameters:** `q` (string, min 1 char)
- **Response `200 OK`:** Returns matching active, published, normal-purchase eligible products with `activeVariants` and `primaryImage`.

#### `GET /api/admin/pos/customers`
Search registered customer accounts by name, phone, email, or username.
- **Query Parameters:** `q` (string, min 2 chars)
- **Response `200 OK`:** Returns matching customer user profiles.

#### `GET /api/admin/customers/{id}/addresses`
Retrieve all delivery addresses belonging to a specific customer.
- **Response `200 OK`:** List of customer's saved addresses.

#### `POST /api/admin/customers/{id}/addresses`
Create a new delivery address on behalf of the customer. Automatically maintains single-default invariance if `is_default: true`.
- **Request Body:** Delivery address payload (`recipient_name`, `phone`, `district`, `upazila`, `address`, `label`, `is_default`).
- **Response `201 Created`:** Created address object.

#### `POST /api/admin/pos/quote`
Compute authoritative subtotal, discounts, shipping, tax, and grand totals for the POS cart without saving an order.
- **Request Body:** `{ items: [...], discount_amount, discount_reason, shipping_fee }`
- **Response `200 OK`:** Authoritative pricing summary with line totals.

#### `POST /api/admin/pos/orders`
Complete and record a Point of Sale checkout atomically.
- **Request Body:** `{ customer_id, address_id, items: [...], discount_amount, discount_reason, shipping_fee, payment_method, amount_received, note, idempotency_key }`
- **Response `201 Created`:** Created order object with order number, receipt details, tender breakdown, and change amount.

#### `GET /api/admin/pos/orders/{id}`
Retrieve order details formatted for printing thermal POS receipts and review.
- **Response `200 OK`:** Full POS order record with customer, items, and tender breakdown.

### 11.3 Orders Channel Filtering
In `GET /api/admin/orders`:
- **Query Parameter:** `channel` (`all`, `pos`, `web`)
- Orders table renders a dedicated `POS` badge for in-store transactions.
- Order details modal displays tender breakdown (amount received, change returned) and discount reason.


---

## 12. Community Commerce Engine APIs

### 12.1 Concept & Capability Architecture
"Every user can turn their community into a small online business: Discover → Pick → Sell → Start a Drop → Earn."

- **Cumulative Account Capabilities:** A single user account can simultaneously act as a **Customer** (purchases items), a **Recommender** (curates picks & shares referral links for fixed commissions), a **Community Seller** (operates a personal branded storefront at `/shop/:slug`), and a **Drop Organizer** (initiates volume buying campaigns).
- **Master Catalog & Central Inventory:** Community storefronts reference master catalog products/variants directly (`shop_listings`). Inventory is tracked once at the platform/warehouse level; sellers never duplicate products or hold stock.
- **Fulfillment & Trust Guarantee:** JashoreBro handles warehousing, packaging, shipping, delivery, and cash/bKash collections directly. Community sellers bear zero shipping liability.
- **Commercial Formulas & Bounds:**
  - Master catalog products define:
    - $S$: `supplier_allocation_price` (wholesale base allocated to artisan producer/supplier)
    - $P_{min}$: `min_selling_price` (minimum retail floor price)
    - $P_{max}$: `max_selling_price` (maximum retail ceiling price)
    - $fee\%$: `platform_fee_percent` (retained by platform on gross markup, default 5%)
    - $C$: `recommendation_commission` (fixed unit amount for recommenders)
  - When seller sets custom retail price $P$ ($P_{min} \le P \le P_{max}$):
    - Gross Markup: $M = P - S$
    - Platform Fee: $F = M \times \frac{fee\%}{100}$
    - Seller Net Earning: $E = M - F$
- **Line-Item Attribution Precedence:**
  1. **Group Drop Campaign:** (`earning_model: group_drop_organizer`)
  2. **Community Storefront Listing:** (`earning_model: community_shop`)
  3. **7-Day Recommendation Code:** (`earning_model: recommendation`, referral code `REC-U{id}-P{id}`)
  4. **Direct Catalog Sale:** (`earning_model: none`)
- **Self-Purchase Protection:** If buyer user ID equals the beneficiary user ID, community commissions and markups are strictly zeroed server-side.
- **Financial Ledger & Balances:**
  - Append-only ledger in `earnings_ledgers`.
  - Balances: `pending` (held during transit) $\rightarrow$ automatically released to `available` when order status transitions to `delivered`.
  - Cancellations/refunds: automatically revert pending earnings to `reversed`.
  - Zero balances are strictly displayed as `৳0.00` (no mock data).

### 12.2 API Endpoints Matrix

#### A. My Picks (Curated Product Recommendations)
- `GET /api/community/picks`: Retrieve authenticated user's picks list.
- `POST /api/community/picks/{productId}`: Curate product to user's picks. Body: `{ personal_caption, is_featured }`. Returns assigned `recommendation_code` (`REC-U{id}-P{id}`).
- `DELETE /api/community/picks/{pickId}`: Remove product from user's picks.
- `GET /api/community/users/{userId}/picks`: Publicly view another user's curated picks.

#### B. Community Storefront Studio & Public Store
- `GET /api/community/shop/me`: Authenticated user's storefront details and listings.
- `POST /api/community/shop`: Initialize new community store. Body: `{ name, bio, logo_url }`.
- `PUT /api/community/shop`: Update store branding, bio, and logo.
- `GET /api/community/shop/{slug}`: Public storefront by slug. Returns store profile, verified fulfillment guarantee, active listings with seller custom prices, follower count, and follow state.
- `POST /api/community/shop/listings`: Add product to storefront. Body: `{ product_id, selling_price, curator_note }`. Validates $P_{min} \le selling\_price \le P_{max}$.
- `PUT /api/community/shop/listings/{id}`: Update listing price or active status. Body: `{ selling_price, curator_note, is_active }`.
- `DELETE /api/community/shop/listings/{id}`: Remove product from storefront.
- `GET /api/community/shop/sales`: Attributed orders for seller. Privacy-safe: excludes buyer name, telephone, delivery address, and payment credentials.

#### C. Community Volume Drops
- `POST /api/community/drops`: Launch organizer group buy campaign for permitted products (`is_group_drop_enabled`). Body: `{ product_id, title, target_participants, group_price, duration_hours }`.
- `GET /api/community/drops/{id}`: View active group campaign details and participant progress.

#### D. Earnings Wallet & Payouts
- `GET /api/community/earnings/balance`: Returns real financial balances (`pending`, `available`, `reserved_for_payout`, `paid`).
- `GET /api/community/earnings/ledger`: Paginated append-only ledger transaction history with source models and order references.
- `POST /api/community/earnings/payouts`: Submit withdrawal request. Body: `{ amount, payout_method, account_identifier }`. Moves funds to `reserved_for_payout`.
- `GET /api/community/earnings/payouts`: History of user payout requests with disbursement status.

#### E. Social Graph
- `POST /api/community/users/{userId}/follow`: One-way follow a community seller.
- `DELETE /api/community/users/{userId}/follow`: Unfollow a community seller.
- `GET /api/community/users/{userId}/followers`: List followers of a community seller.

#### F. Community Leaderboards
- `GET /api/community/leaderboards/top-sellers`: Top community storefronts by delivered GMV and net earnings.
- `GET /api/community/leaderboards/top-curators`: Top product recommenders by delivered units and commissions.
- `GET /api/community/leaderboards/top-organizers`: Top group drop organizers by fulfilled volume campaigns.

#### G. Admin Community Oversight
- `GET /api/admin/community/shops`: List and search all community storefronts with listing counts and statuses.
- `PATCH /api/admin/community/shops/{id}/status`: Moderate store status (`active` | `suspended`).
- `GET /api/admin/community/payouts`: Review pending and historical withdrawal requests. Filter by status (`requested`, `processing`, `paid`, `rejected`).
- `PATCH /api/admin/community/payouts/{id}`: Process withdrawal request:
  - `{ action: "paid", transaction_reference: "TRX123" }`: Finalizes payout and records audit reference.
  - `{ action: "rejected", rejection_reason: "..." }`: Rejects request and refunds reserved balance back to available balance.
- `GET /api/admin/community/settlement`: Aggregate GMV, supplier allocations, platform fees retained, seller margins, and pending vs available liability balances.
