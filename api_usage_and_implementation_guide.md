# DiverseBD REST API & Integration Documentation

The **DiverseBD REST API** allows external applications—including mobile apps (Flutter, React Native, iOS, Android), POS billing systems, ERP/inventory tools, and courier services (Steadfast, Pathao, RedX)—to communicate with your eCommerce backend.

All endpoints return uniform JSON payloads with explicit HTTP status codes.

---

## 🛠️ 1. Authentication & Security Model

The API supports three authentication methods:
1. **Header Authentication (Standard):** Send your Public Key ID or Secret Token via `X-API-KEY`.
2. **Bearer Token Authentication:** Send your credentials via `Authorization: Bearer <TOKEN>`.
3. **Webhook URL Fallback:** Couriers that do not support custom headers can pass the key as a query parameter (`?api_key=<KEY>` or `?token=<KEY>`) or via `X-Webhook-Secret`.

### Required Request Headers

| Header Name | Required | Description | Example |
| :--- | :--- | :--- | :--- |
| `X-API-KEY` | **Yes** | Your API Key ID or generated Secret Token | `db_b397b0a1c91476071e4e568dcdbda809` |
| `Accept` | **Yes** | Instructs the server to return structured JSON | `application/json` |
| `Content-Type` | **POST / PUT** | Required whenever sending a JSON request body | `application/json` |

### Key Types & Verification
When you create a key in the admin panel (`admin/system/api-keys.php`):
* **API Key ID (`db_...`):** Stored in the database and validated directly.
* **Secret Token (64-character hex string):** Displayed once upon creation. It is stored as a secure hash (`password_hash`) and verified dynamically.

### Rate Limiting
Each key is assigned a daily request quota (default: 1,000–5,000 calls/day). The quota resets automatically at midnight every 24 hours. If an application exceeds its quota, the API responds with `429 Too Many Requests`.

---

## 🔐 2. Permission Scopes Matrix

Every API key is assigned one or more granular scopes:

| Scope Key | Scope Label | Accessible Endpoints |
| :--- | :--- | :--- |
| `*` | **Master Wildcard** | Full access to all endpoints across the platform. |
| `auth:login` | Authentication & SSO | `POST /auth/login.php`<br>`POST /auth/register.php` |
| `products:read` | Read Catalog & Stock | `GET /products/list.php`<br>`GET /products/detail.php` |
| `products:write` | Manage Catalog | `POST /inventory/adjust.php` |
| `orders:read` | Read Orders & Tracking | `GET /orders/track.php` |
| `orders:write` | Create Orders & Coupons | `POST /orders/create.php`<br>`POST /coupons/validate.php` |
| `checkout:read` | Calculate Shipping Rates | `GET /checkout/shipping-rates.php`<br>`POST /checkout/shipping-rates.php` |
| `courier:sync` | Courier Status Webhooks | `POST /courier/sync.php` |
| `customers:read` | Read Profiles & Addresses | `GET /customers/sync.php`<br>`GET /customers/addresses.php` |
| `customers:write` | Manage User Data | `POST /auth/register.php`<br>`POST /customers/addresses.php`<br>`DELETE /customers/addresses.php` |
| `inventory:write` | POS & Stock Control | `POST /inventory/adjust.php` |

---

## 🌐 3. API Endpoints Reference

### Base URL
```text
http://localhost/v2ecom/api/v1
```
*(In production, replace with your live domain: `https://yourdomain.com/api/v1`)*

---

### 1️⃣ Products List (Catalog Feed)
Retrieve a paginated catalog feed with cover photos, gallery images, available sizes, and variant stock.

* **Endpoint:** `/products/list.php`
* **Method:** `GET`
* **Required Scope:** `products:read` or `*`

#### Query Parameters
| Parameter | Type | Required | Description | Default |
| :--- | :--- | :--- | :--- | :--- |
| `page` | integer | No | Page number offset | `1` |
| `limit` | integer | No | Number of items per page (Max: 100) | `20` |
| `category_id` | integer | No | Filter items by category ID | — |

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "pagination": {
    "page": 1,
    "limit": 10,
    "total": 35,
    "total_pages": 4,
    "count": 1
  },
  "data": [
    {
      "id": 1,
      "category_id": 2,
      "category_name": "Casual Wear",
      "name": "Oxford Cotton Shirt",
      "slug": "oxford-cotton-shirt",
      "sku": "SHIRT-OX-001",
      "price": 1250.00,
      "compare_price": 1500.00,
      "stock": 40,
      "sizes": ["M", "L", "XL"],
      "cover_image": "http://localhost/v2ecom/uploads/products/cover.jpg",
      "gallery": [
        "http://localhost/v2ecom/uploads/products/cover.jpg",
        "http://localhost/v2ecom/uploads/products/side.jpg"
      ],
      "variants": [
        { "variant_id": 10, "size": "M", "sku": "SHIRT-OX-001-M", "stock": 15 },
        { "variant_id": 11, "size": "L", "sku": "SHIRT-OX-001-L", "stock": 25 }
      ],
      "product_url": "http://localhost/v2ecom/product.php?slug=oxford-cotton-shirt"
    }
  ]
}
```

---

### 2️⃣ Product Detail
Fetch comprehensive product information including variant SKUs, additional prices, and full descriptions.

* **Endpoint:** `/products/detail.php`
* **Method:** `GET`
* **Required Scope:** `products:read` or `*`

#### Query Parameters (Provide either `id` or `slug`)
* `id` (integer): Database ID of the product.
* `slug` (string): URL slug of the product.

#### Request Example
```text
GET /api/v1/products/detail.php?slug=oxford-cotton-shirt
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "name": "Oxford Cotton Shirt",
    "slug": "oxford-cotton-shirt",
    "sku": "SHIRT-OX-001",
    "category": { "id": 2, "name": "Casual Wear", "slug": "casual-wear" },
    "price": 1250.00,
    "compare_price": 1500.00,
    "short_description": "100% breathable cotton.",
    "description": "<p>Detailed description and size guide...</p>",
    "cover_image": "http://localhost/v2ecom/uploads/products/cover.jpg",
    "stock": 40,
    "in_stock": true,
    "sizes": ["M", "L", "XL"],
    "gallery": [
      { "id": 101, "image_url": "http://localhost/v2ecom/uploads/products/cover.jpg", "is_primary": true }
    ],
    "variants": [
      {
        "variant_id": 10,
        "sku": "SHIRT-OX-001-M",
        "size": "M",
        "color": "White",
        "additional_price": 0.00,
        "stock": 15,
        "in_stock": true
      }
    ],
    "product_url": "http://localhost/v2ecom/product.php?slug=oxford-cotton-shirt"
  }
}
```

---

### 3️⃣ Shipping Rates & Delivery Zones
Fetch all delivery zones or calculate exact delivery fees for a specific city.

* **Endpoint:** `/checkout/shipping-rates.php`
* **Method:** `GET` or `POST`
* **Required Scope:** `checkout:read`, `orders:read`, or `*`

#### Request (Single City Lookup)
```text
GET /api/v1/checkout/shipping-rates.php?city=Dhaka
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "data": {
    "city_id": 1,
    "city_name": "Dhaka",
    "zone_code": "inside_dhaka",
    "shipping_cost": 60.00,
    "is_fallback": false
  }
}
```
*(If no city is passed, it returns an array of all active cities and zones).*

---

### 4️⃣ Coupon Code Validation
Validate discount codes before order placement.

* **Endpoint:** `/coupons/validate.php`
* **Method:** `POST`
* **Required Scope:** `orders:write`, `checkout:read`, or `*`

#### Request Body (JSON)
```json
{
  "code": "EID2026",
  "subtotal": 2500.00
}
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "message": "Coupon applied successfully.",
  "data": {
    "code": "EID2026",
    "type": "percent",
    "value": 10.0,
    "discount_amount": 250.00,
    "new_subtotal": 2250.00
  }
}
```

---

### 5️⃣ Order Creation (Store Checkout)
Place new customer orders. This endpoint automatically:
* Verifies item availability against variant inventory.
* Performs atomic deductions from both `product_variants.stock` and `products.stock`.
* Assigns unique tracking IDs and calculates zone shipping rates.

* **Endpoint:** `/orders/create.php`
* **Method:** `POST`
* **Required Scope:** `orders:write` or `*`

#### Request Body (JSON)
```json
{
  "customer_name": "Ashraful Alam Shihab",
  "customer_email": "alamshihab526@gmail.com",
  "customer_phone": "01982401992",
  "address": "House 12, Road 5, Dhanmondi",
  "city_id": 1,
  "payment_method": "COD",
  "notes": "Please deliver after 2 PM",
  "coupon_code": "EID2026",
  "discount_amount": 250.00,
  "items": [
    {
      "product_id": 1,
      "size": "M",
      "quantity": 2
    }
  ]
}
```

#### Success Response (`201 Created`)
```json
{
  "status": "success",
  "message": "Order created successfully.",
  "data": {
    "order_id": 142,
    "order_number": "DVBD2610269A8F12",
    "tracking_number": "TRK-98D1A3C4",
    "status": "pending",
    "subtotal": 2500.00,
    "shipping_amount": 60.00,
    "discount_amount": 250.00,
    "total": 2310.00,
    "zone_code": "inside_dhaka",
    "items_count": 1,
    "items": [
      {
        "product_id": 1,
        "variant_id": 10,
        "product_name": "Oxford Cotton Shirt",
        "size": "M",
        "sku": "SHIRT-OX-001-M",
        "quantity": 2,
        "unit_price": 1250.00,
        "line_total": 2500.00
      }
    ]
  }
}
```

---

### 6️⃣ Order Tracking & Details
Look up orders by ID, order number, or customer phone number.

* **Endpoint:** `/orders/track.php`
* **Method:** `GET`
* **Required Scope:** `orders:read` or `*`

#### Query Parameters (Provide either `order_number` OR `order_id` + `phone`)
* `order_number` (string): Public order reference (e.g. `DVBD261025EBE936`).
* `order_id` (integer): Internal primary key.
* `phone` (string): Customer contact number.

#### Request Example
```text
GET /api/v1/orders/track.php?order_number=DVBD261025EBE936&phone=01982401992
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "data": {
    "id": 2,
    "order_number": "DVBD261025EBE936",
    "customer_name": "Ashraful Alam Shihab",
    "customer_email": "alamshihab526@gmail.com",
    "customer_phone": "01982401992",
    "address": "Test Address",
    "city": "Dhaka",
    "zone_code": "inside_dhaka",
    "payment_method": "COD",
    "status": "shipped",
    "tracking_number": "STEADFAST-998822",
    "subtotal": 100.00,
    "discount_amount": 0.00,
    "shipping_amount": 60.00,
    "total": 160.00,
    "created_at": "2026-10-25 23:05:37",
    "items": [
      {
        "id": 3,
        "product_id": 2,
        "product_name": "Premium T-Shirt",
        "size": "M",
        "sku": "TSHIRT-BLK-M",
        "quantity": 1,
        "unit_price": 100.00,
        "line_total": 100.00,
        "image_url": "http://localhost/v2ecom/uploads/products/tshirt.jpg"
      }
    ],
    "status_history": [
      { "status": "pending", "note": "Order placed", "created_at": "2026-10-25 23:05:37" },
      { "status": "shipped", "note": "Courier Status Sync: Shipped via Steadfast Courier", "created_at": "2026-10-25 23:30:12" }
    ]
  }
}
```

---

### 7️⃣ Courier Status Sync & Automatic Restock
Used by delivery partners (Steadfast, Pathao, RedX) to update consignment numbers and delivery states.

> **Automatic Inventory Restock:** If an order status changes to `cancelled` or `returned`, the endpoint automatically restocks the ordered sizes back into `product_variants` and `products`.

* **Endpoint:** `/courier/sync.php`
* **Method:** `POST`
* **Required Scope:** `courier:sync`, `orders:write`, or `*`

#### Dynamic Payload Support
Accepts either `order_number`, `invoice`, or `order_id`, and `tracking_number` or `consignment_id`.

```json
{
  "order_number": "DVBD261025EBE936",
  "tracking_number": "STEADFAST-998822",
  "status": "shipped",
  "courier_name": "Steadfast Courier",
  "note": "Package picked up from hub"
}
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "message": "Courier status and consignment details synchronized successfully.",
  "data": {
    "order_id": 2,
    "order_number": "DVBD261025EBE936",
    "tracking_number": "STEADFAST-998822",
    "status": "shipped",
    "shipping_zone": "inside_dhaka",
    "restocked": false
  }
}
```

---

### 8️⃣ Customer Profile & Lifetime Value (LTV) Sync
Retrieves customer purchase metrics, saved delivery addresses, and recent order history for CRM systems.

* **Endpoint:** `/customers/sync.php`
* **Method:** `GET`
* **Required Scope:** `customers:read` or `*`

#### Query Parameters (Provide at least one)
* `id` (integer): Customer user ID.
* `phone` (string): Customer mobile phone number.
* `email` (string): Customer email address.

#### Request Example
```text
GET /api/v1/customers/sync.php?phone=01982401992
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "name": "Ashraful Alam Shihab",
    "email": "alamshihab526@gmail.com",
    "phone": "01982401992",
    "city": "Dhaka",
    "zone_code": "inside_dhaka",
    "metrics": {
      "total_orders": 4,
      "total_spent": 6450.00,
      "last_order_at": "2026-10-25 23:05:37"
    },
    "saved_addresses": [
      {
        "address_id": 5,
        "full_name": "Ashraful Alam Shihab",
        "phone": "01982401992",
        "street_address": "House 12, Road 5, Dhanmondi",
        "city": "Dhaka",
        "is_default": true
      }
    ],
    "recent_orders": [
      {
        "id": 2,
        "order_number": "DVBD261025EBE936",
        "status": "delivered",
        "total": 160.00,
        "created_at": "2026-10-25 23:05:37"
      }
    ]
  }
}
```

---

### 9️⃣ Customer Addresses Management
Add, list, or delete saved addresses for registered customers.

* **Endpoint:** `/customers/addresses.php`
* **Required Scope:** `customers:read` (for GET), `customers:write` (for POST/DELETE), or `*`

#### List Saved Addresses (`GET`)
```text
GET /api/v1/customers/addresses.php?user_id=1
```

#### Add New Address (`POST`)
```json
{
  "user_id": 1,
  "full_name": "Ashraful Alam Shihab",
  "phone": "01982401992",
  "street_address": "Block C, Bashundhara R/A",
  "city_id": 1,
  "is_default": 1
}
```

#### Delete Address (`DELETE`)
```text
DELETE /api/v1/customers/addresses.php?address_id=5&user_id=1
```

---

### 🔟 Customer Authentication & Registration

#### Customer Login (`POST /auth/login.php`)
* **Required Scope:** `auth:login` or `*`

```json
{
  "email": "alamshihab526@gmail.com",
  "password": "yourpassword"
}
```

**Success Response (`200 OK`):**
```json
{
  "status": "success",
  "message": "Authentication successful.",
  "token": "7f8b9c0d1e2f3a4b5c6d7e8f9a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b",
  "user": {
    "id": 1,
    "name": "Ashraful Alam Shihab",
    "email": "alamshihab526@gmail.com",
    "role": "customer",
    "phone": "01982401992",
    "address": "House 12, Road 5",
    "city": "Dhaka",
    "city_id": 1,
    "zone_code": "inside_dhaka",
    "shipping_cost": 60.00
  }
}
```

#### Customer Registration (`POST /auth/register.php`)
* **Required Scope:** `auth:login`, `customers:write`, or `*`

```json
{
  "name": "Tanvir Hasan",
  "email": "tanvir@example.com",
  "password": "securepassword123",
  "phone": "01711223344",
  "address": "Gulshan 2",
  "city_id": 1,
  "city": "Dhaka"
}
```

---

### 1️⃣1️⃣ POS & Inventory Adjustments
Update variant stock levels from physical cash registers or ERP software.

* **Endpoint:** `/inventory/adjust.php`
* **Method:** `POST`
* **Required Scope:** `inventory:write`, `products:write`, or `*`

#### Adjustment Modes
* `set`: Sets current stock to an absolute value.
* `increment`: Adds stock to current balance.
* `decrement`: Subtracts stock from current balance.

#### Single Item Adjustment
```json
{
  "product_id": 1,
  "size": "M",
  "mode": "increment",
  "quantity": 10
}
```

#### Batch Adjustment Payload
```json
{
  "adjustments": [
    { "sku": "SHIRT-OX-001-M", "mode": "decrement", "quantity": 1 },
    { "sku": "SHIRT-OX-001-L", "mode": "set", "quantity": 50 }
  ]
}
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "message": "Inventory adjusted successfully.",
  "count": 2,
  "data": [
    {
      "variant_id": 10,
      "sku": "SHIRT-OX-001-M",
      "size": "M",
      "mode": "decrement",
      "old_stock": 15,
      "new_stock": 14,
      "delta": -1
    },
    {
      "variant_id": 11,
      "sku": "SHIRT-OX-001-L",
      "size": "L",
      "mode": "set",
      "old_stock": 25,
      "new_stock": 50,
      "delta": 25
    }
  ]
}
```

---

## 💻 4. Integration Code Examples

### JavaScript / React Native / Node.js
```javascript
const API_URL = 'http://localhost/v2ecom/api/v1';
const API_KEY = 'db_b397b0a1c91476071e4e568dcdbda809';

// Place an order from a mobile app
async function placeOrder() {
  const payload = {
    customer_name: "Ashraful Alam Shihab",
    customer_phone: "01982401992",
    address: "Dhanmondi, Dhaka",
    city_id: 1,
    payment_method: "COD",
    items: [
      { product_id: 1, size: "M", quantity: 1 }
    ]
  };

  try {
    const res = await fetch(`${API_URL}/orders/create.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-API-KEY': API_KEY
      },
      body: JSON.stringify(payload)
    });

    const data = await res.json();
    console.log('Order Result:', data);
  } catch (err) {
    console.error('API Error:', err);
  }
}

placeOrder();
```

### Python (POS / Barcode Scanner Integration)
```python
import requests

API_URL = "http://localhost/v2ecom/api/v1/inventory/adjust.php"
HEADERS = {
    "X-API-KEY": "db_b397b0a1c91476071e4e568dcdbda809",
    "Content-Type": "application/json",
    "Accept": "application/json"
}

# Decrement variant stock on POS register scan
payload = {
    "sku": "SHIRT-OX-001-M",
    "mode": "decrement",
    "quantity": 1
}

response = requests.post(API_URL, json=payload, headers=HEADERS)
print("POS Response:", response.json())
```

---

## 🛑 5. HTTP Status Codes & Error Handling

All responses return standard HTTP status codes accompanied by a JSON payload containing `'status': 'error'` and a descriptive `'message'`.

| Status Code | Meaning | Cause & Resolution |
| :--- | :--- | :--- |
| `200 OK` | Success | The request succeeded. |
| `201 Created` | Created | A new resource (order, address, account) was created. |
| `400 Bad Request` | Bad Request | Missing required parameters or malformed JSON syntax. |
| `401 Unauthorized` | Unauthorized | Missing, invalid, or revoked `X-API-KEY` header. |
| `403 Forbidden` | Forbidden | API key lacks the required permission scope or account is blocked. |
| `404 Not Found` | Not Found | The requested product, order, or variant does not exist. |
| `409 Conflict` | Conflict | Email already registered, or ordered variant is out of stock. |
| `422 Unprocessable` | Unprocessable Entity | Invalid status value, unrecognized size, or minimum order threshold not met. |
| `429 Too Many Requests`| Rate Limited | Daily request limit reached. Resets at midnight. |
| `500 Server Error` | Internal Server Error | Database connection failure or unhandled exception. |