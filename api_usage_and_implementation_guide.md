# DiverseBD REST API & Integration Documentation

Welcome to the **DiverseBD REST API** documentation. This guide details how to integrate external services (such as courier partners like Pathao/Steadfast, ERPs, CRMs, and mobile mobile apps) with your eCommerce store.

---

## 🛠️ 1. Authentication & Security Model

The API uses **Header-Based Secret Token & API Key Authentication** scoped with fine-grained access control permissions.

### Authentication Headers
Every API request made by an external client must include the following header:

| Header Name | Required | Description | Example Value |
| :--- | :--- | :--- | :--- |
| `X-API-KEY` | **Yes** | Public/Private identifier key assigned to the integration. | `db_cb33c74a66936dd3338958850d88c27b` |
| `X-API-SECRET` | Optional | HMAC signature token for high-security webhook verifications. | `f87cb1e2c37efc7e86af6289d...` |
| `Content-Type` | **POST/PUT** | Must be set to JSON for payload parsing. | `application/json` |

---

## 🔐 2. Scopes & Access Control

Permissions are granted on a per-key basis during generation in the Admin Dashboard:

* `auth:login` — Customer Single Sign-On (SSO) authentication.
* `orders:read` — Access real-time order status, customer details, and items.
* `orders:write` — Place or modify existing orders remotely.
* `courier:sync` — Update shipping tracking IDs and delivery status.
* `customers:read` — Query customer profile details for CRMs.
* `customers:write` — Create or update customer accounts.
* `products:read` — Access live inventory, prices, and catalog status.

---

## 🌐 3. API Endpoints Reference

### Base URL
```text
http://localhost/diversebd/api/v1
```
*(In production, replace `http://localhost/diversebd` with your live domain SSL `https://yourdomain.com`).*

---

### 1️⃣ Customer SSO Login Endpoint

* **Endpoint:** `/auth/login.php`
* **Method:** `POST`
* **Required Scope:** `auth:login`
* **Description:** Authenticates storefront customers for external apps or single sign-on services.

#### Request Body (JSON)
```json
{
  "email": "customer@example.com",
  "password": "customer_password"
}
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "message": "Authentication successful.",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "customer@example.com",
    "phone": "+8801700000000",
    "address": "123 Main Street",
    "city": "Dhaka"
  }
}
```

---

### 2️⃣ Product Catalog Feed Endpoint

* **Endpoint:** `/products/list.php`
* **Method:** `GET`
* **Required Scope:** `products:read`
* **Description:** Retrieves active products, category names, prices, and real-time stock balances.

#### Query Parameters
* `limit` *(optional)*: Number of records to return (Default: `20`, Max: `100`).
* `page` *(optional)*: Page offset for pagination (Default: `1`).
* `category_id` *(optional)*: Filter inventory by category ID.

#### Request Example
```text
GET {{base_url}}/api/v1/products/list.php?limit=10&page=1
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "pagination": {
    "page": 1,
    "limit": 10,
    "count": 1
  },
  "data": [
    {
      "id": 1,
      "category_id": 2,
      "category_name": "Footwear",
      "name": "Classic Leather Shoes",
      "slug": "classic-leather-shoes",
      "sku": "SHOE-001",
      "description": "Premium handcrafted shoes.",
      "price": "2500.00",
      "compare_price": "3000.00",
      "image_url": "uploads/products/shoe1.jpg",
      "stock": 45,
      "created_at": "2026-10-21 12:00:00"
    }
  ]
}
```

---

### 3️⃣ Order Status & Tracking Endpoint

* **Endpoint:** `/orders/track.php`
* **Method:** `GET`
* **Required Scope:** `orders:read`
* **Description:** Queries order details and tracking numbers generated during checkout.

#### Query Parameters (Provide at least one)
* `order_id`: Internal database primary ID.
* `order_number`: Public order reference (e.g., `DVBD261021...`).
* `phone`: Customer contact number.

#### Request Example
```text
GET {{base_url}}/api/v1/orders/track.php?order_id=101
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "data": {
    "id": 101,
    "order_number": "DVBD261021A1B2C3",
    "customer_name": "Jane Smith",
    "customer_email": "jane@example.com",
    "customer_phone": "+8801800000000",
    "address": "45 Green Road",
    "city": "Dhaka",
    "payment_method": "COD",
    "status": "pending",
    "tracking_number": "TRK-98A2B4C6",
    "total": "1800.00",
    "created_at": "2026-10-21 14:15:00"
  }
}
```

---

### 4️⃣ Courier Sync & Tracking Update Endpoint

* **Endpoint:** `/courier/sync.php`
* **Method:** `POST`
* **Required Scope:** `courier:sync`
* **Description:** Allows courier partner services (Pathao, Steadfast, RedX) to pass tracking IDs and update parcel status.

#### Request Body (JSON)
```json
{
  "order_id": 101,
  "tracking_number": "PTH-99812345",
  "status": "shipped"
}
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "message": "Courier status and tracking details updated successfully.",
  "data": {
    "order_id": 101,
    "order_number": "DVBD261021A1B2C3",
    "tracking_number": "PTH-99812345",
    "status": "shipped"
  }
}
```

---

### 5️⃣ Customer CRM Profile Lookup Endpoint

* **Endpoint:** `/customers/sync.php`
* **Method:** `GET`
* **Required Scope:** `customers:read`
* **Description:** Retrieves customer profile information for external CRM tools.

#### Query Parameters (Provide at least one)
* `id`: Customer user ID.
* `email`: Customer email address.
* `phone`: Customer phone number.

#### Request Example
```text
GET {{base_url}}/api/v1/customers/sync.php?email=customer@example.com
```

#### Success Response (`200 OK`)
```json
{
  "status": "success",
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "customer@example.com",
    "phone": "+8801700000000",
    "address": "123 Main Street",
    "city": "Dhaka",
    "status": "active",
    "created_at": "2026-01-10 10:00:00"
  }
}
```

---

## 💻 4. Code Implementation Examples

### PHP (cURL Integration)
```php
<?php
$apiKey = 'db_cb33c74a66936dd3338958850d88c27b';
$apiUrl = 'http://localhost/diversebd/api/v1/courier/sync.php';

$data = [
    'order_id' => 101,
    'tracking_number' => 'PTH-99812345',
    'status' => 'shipped'
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-API-KEY: ' . $apiKey
]);

$response = curl_exec($ch);
curl_close($ch);

echo $response;
```

### JavaScript / Node.js (Fetch API)
```javascript
const apiKey = 'db_cb33c74a66936dd3338958850d88c27b';
const apiUrl = 'http://localhost/diversebd/api/v1/orders/track.php?order_id=101';

async function getOrderDetails() {
  const response = await fetch(apiUrl, {
    method: 'GET',
    headers: {
      'X-API-KEY': apiKey
    }
  });

  const data = await response.json();
  console.log(data);
}

getOrderDetails();
```

---

## 🛑 5. HTTP Error Codes & Troubleshooting

| HTTP Code | Error Message | Cause & Resolution |
| :--- | :--- | :--- |
| `400` | `Bad Request` | Missing required JSON body parameters or invalid format. |
| `401` | `Unauthorized` | Missing or invalid `X-API-KEY` header. |
| `403` | `Forbidden` | API key lacks required scope permission (e.g., calling courier endpoint without `courier:sync`). |
| `404` | `Not Found` | Requested order ID or resource does not exist in database. |
| `405` | `Method Not Allowed` | Incorrect HTTP verb used (e.g., using `GET` instead of `POST`). |
| `500` | `Internal Error` | Server-side or database query failure. |