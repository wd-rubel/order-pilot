# Order Pilot – WooCommerce Plugin

A powerful WooCommerce plugin for **COD order management, courier automation, fraud prevention, and server-side marketing tracking**.

The plugin will help WooCommerce store owners manage orders, send orders to couriers with one click, detect fraudulent customers, and improve Meta/Facebook conversion tracking from a single dashboard.

---

## 1. Core Product Concept

The plugin will combine three major functionalities:

1. **Courier Order Automation**
2. **Fraud Detection**
3. **Marketing Tracking & Server-Side Tracking**

### Main Workflow

```text
WooCommerce Order
       │
       ├── Fraud Check
       │       │
       │       ├── High Risk → Review
       │       └── Safe → Continue
       │
       ├── Send to Courier
       │       │
       │       └── Tracking / Status
       │
       └── Marketing Tracking
               │
               ├── Meta Pixel
               └── Meta Conversions API
```

---

# 2. Free vs Pro Model

The plugin will use a **Free + Pro** business model.

The Free version should provide enough functionality to be genuinely useful, while advanced automation and fraud prevention remain premium features.

## Free Version

### Courier

- Support Steadfast
- Support Pathao
- Support RedX
- User can connect **any 2 couriers**
- One-click send order to courier
- Customer information mapping
- Product/order information mapping
- COD amount
- Consignment/tracking ID
- Basic courier settings

### Marketing Tracking

- Facebook/Meta Pixel
- PageView
- ViewContent
- AddToCart
- InitiateCheckout
- Purchase
- Basic WooCommerce event tracking
- Basic product information
- Basic value and currency tracking

### Order Management

- WooCommerce order integration
- Courier status indicator
- Basic order actions
- Basic activity logs

---

# 3. Pro Version

The Pro version unlocks advanced order automation, fraud detection, courier management, server-side tracking, and analytics.

## Courier Features

- Unlimited courier connections
- Steadfast
- Pathao
- RedX
- Future courier integrations
- Bulk order submission
- Courier status synchronization
- Automatic WooCommerce order status update
- Tracking information
- Consignment information
- Courier-wise order management
- Courier-wise analytics
- Retry failed courier requests
- Courier API logs

---

# 4. Fraud Checker

**Fraud Checker will be a Pro-only feature.**

This will be one of the primary selling points of the plugin.

## Features

- Phone number fraud check
- Customer order history
- Total previous orders
- Delivered orders
- Cancelled orders
- Returned orders
- Previous COD orders
- Fraud score
- Risk level
- Fraud history
- Order-level fraud information
- Bulk fraud checking
- Fraud check before courier submission

## Risk Levels

```text
🟢 Low Risk
🟡 Medium Risk
🔴 High Risk
```

Example:

```text
Customer: 017XXXXXXXX

Total Orders:       12
Delivered:           8
Cancelled:           3
Returned:            1

Fraud Score:        78/100
Risk Level:         HIGH
```

The fraud result should be visible directly from the WooCommerce order screen.

---

# 5. One-Click Courier Order

The plugin will add a courier action to WooCommerce orders.

Example:

```text
Order #10245

[Send to Courier]
```

When clicked:

```text
Select Courier

○ Steadfast
○ Pathao
○ RedX

[Send Order]
```

The plugin should automatically collect:

- Customer name
- Phone
- Address
- City
- Area
- Product name
- Quantity
- Order total
- COD amount
- Customer note

After successful submission:

```text
✓ Order sent successfully

Courier: Steadfast
Consignment ID: ST123456789
Tracking ID: ST123456789
```

---

# 6. Bulk Courier Submission

Pro users will be able to select multiple WooCommerce orders and send them to a courier simultaneously.

Example:

```text
☑ #10241
☑ #10242
☑ #10243
☑ #10244

Courier: Steadfast

[Send 4 Orders]
```

The system should display:

```text
Successful: 3
Failed:     1

[View Failed Orders]
```

---

# 7. Courier Status Synchronization

Pro users can synchronize courier delivery status with WooCommerce.

Example:

```text
WooCommerce
     ↓
Courier API
     ↓
Status Update
     ↓
WooCommerce Order
```

Possible statuses:

- Pending
- Picked Up
- In Transit
- Delivered
- Cancelled
- Returned
- Failed Delivery

The plugin should map courier statuses to WooCommerce statuses where appropriate.

---

# 8. Meta / Facebook Pixel

The plugin will provide automatic WooCommerce tracking.

## Standard Events

### PageView

Triggered when a page is viewed.

### ViewContent

Triggered when a product is viewed.

### AddToCart

Triggered when a product is added to cart.

### InitiateCheckout

Triggered when checkout begins.

### Purchase

Triggered after a successful order.

---

# 9. Dynamic Event Parameters

WooCommerce data should automatically be mapped to Meta events.

Example:

```text
Purchase

value: 1250
currency: BDT
content_ids: [123, 456]
content_type: product
num_items: 2
```

For WooCommerce products, the plugin should support parameters such as:

- Product ID
- Product name
- Product price
- Quantity
- Product category
- Content type
- Order value
- Currency
- Order ID

---

# 10. Meta Conversions API

Meta Conversions API will be a **Pro-only feature**.

The plugin will send conversion events from the server instead of relying only on browser-side Pixel tracking.

## Supported Server Events

- PageView
- ViewContent
- AddToCart
- InitiateCheckout
- Purchase

The architecture should allow additional events in future versions.

---

# 11. Event Deduplication

The plugin must prevent duplicate events when the same event is sent through:

```text
Browser Pixel
      +
Server CAPI
```

Both events should use the same `event_id`.

Example:

```text
Browser:

event_name = Purchase
event_id   = order_10245
```

```text
Server:

event_name = Purchase
event_id   = order_10245
```

Meta can then deduplicate the events.

---

# 12. Advanced Customer Matching

Pro version should support advanced customer information for Meta CAPI.

Possible parameters:

- Hashed email
- Hashed phone
- External ID
- Client IP
- User Agent
- FBP
- FBC

Sensitive customer information must be handled according to applicable privacy requirements and Meta's policies.

---

# 13. COD Purchase Tracking

One of the important differentiators of the plugin should be **COD-aware Purchase tracking**.

Instead of always treating an order as a successful purchase immediately:

```text
Order Created
      ↓
Courier Sent
      ↓
Delivered
      ↓
Purchase Event
```

The Pro version should provide an option to trigger the final `Purchase` event based on WooCommerce/courier order status.

Example:

```text
Purchase Event Trigger

○ Order Created
○ Payment Completed
● Order Delivered
```

This is particularly useful for COD businesses where an order placed does not necessarily mean a completed sale.

---

# 14. Dashboard

The admin dashboard will be built using **React**.

No Tailwind CSS will be required.

Recommended UI approach:

- React
- WordPress REST API
- WordPress authentication/nonce
- CSS Modules or dedicated CSS
- Reusable React components
- WordPress-compatible UI patterns

---

## Dashboard Overview

Example:

```text
┌─────────────────────────────────────────────┐
│ Commerce Dashboard                          │
├─────────────┬─────────────┬─────────────────┤
│ Orders      │ Revenue     │ Fraud Orders    │
│ 1,284       │ ৳845,200    │ 47              │
├─────────────┼─────────────┼─────────────────┤
│ Courier     │ Delivered   │ Meta Events     │
│ 96.4%       │ 82.5%       │ 1,842           │
└─────────────┴─────────────┴─────────────────┘
```

---

# 15. React Admin Structure

Recommended structure:

```text
src/
│
├── app/
│   ├── App.jsx
│   └── routes.jsx
│
├── components/
│   ├── Button/
│   ├── Card/
│   ├── Table/
│   ├── Modal/
│   ├── Badge/
│   └── Loading/
│
├── pages/
│   ├── Dashboard/
│   ├── Orders/
│   ├── Fraud/
│   ├── Couriers/
│   ├── Tracking/
│   ├── Analytics/
│   └── Settings/
│
├── features/
│   ├── courier/
│   ├── fraud/
│   └── tracking/
│
├── services/
│   ├── api.js
│   ├── courierApi.js
│   └── trackingApi.js
│
├── hooks/
│
├── utils/
│
└── styles/
    ├── variables.css
    ├── components.css
    └── admin.css
```

---

# 16. Admin Menu

Recommended WordPress admin menu:

```text
Plugin Name

├── Dashboard
├── Orders
├── Fraud Checker
├── Couriers
├── Tracking
├── Analytics
├── Logs
└── Settings
```

For Free users, Pro-only features should still be visible but clearly marked:

```text
Fraud Checker
       PRO
```

Clicking the feature should show a clean upgrade screen instead of completely hiding the feature.

---

# 17. Courier Architecture

Courier integrations should use an adapter/interface-based architecture.

```php
interface CourierInterface
{
    public function create_order($order);

    public function get_status($tracking_id);

    public function cancel_order($tracking_id);
}
```

Each courier will have its own implementation:

```text
CourierInterface
      │
      ├── Steadfast
      ├── Pathao
      ├── RedX
      └── Future Couriers
```

This will make it easier to add additional courier services without modifying the core plugin architecture.

---

# 18. Courier Connection Model

Free:

```text
Available Couriers

✓ Steadfast
✓ Pathao
✓ RedX

You can connect any 2 couriers.
```

Pro:

```text
Available Couriers

✓ Steadfast
✓ Pathao
✓ RedX
✓ Future Courier
✓ Future Courier

Unlimited courier connections.
```

Important: the limitation should be on **active connected courier accounts**, not on the number of courier integrations available in the plugin.

---

# 19. Logs

The plugin should maintain useful logs for debugging.

## Courier Logs

```text
Order #10245
Courier: Steadfast
Action: Create Order
Status: Success
Response: 200
Time: 10:42 AM
```

## Tracking Logs

```text
Event: Purchase
Event ID: order_10245
Browser: Sent
Server: Sent
Deduplicated: Yes
```

Logs should have filtering and search capabilities.

---

# 20. Settings

## General

- Enable/disable plugin features
- Currency
- Default settings

## Courier

- Connected couriers
- API credentials
- Default courier
- Order mapping
- Status mapping

## Fraud

- Fraud settings
- Risk thresholds
- Automatic checking
- Blocking/review rules

## Meta Pixel

- Pixel ID
- Events
- Event parameters

## Conversions API

- Pixel ID
- Access Token
- Test Event Code
- Server-side events
- Deduplication

---

# 21. Recommended Free vs Pro Positioning

## Free

> **Get started with essential WooCommerce courier automation and Meta tracking.**

Includes:

- Any 2 courier connections
- One-click courier order
- Basic order management
- Meta Pixel
- Standard WooCommerce events

## Pro

> **Automate COD orders, detect fraud, and maximize conversion tracking.**

Includes:

- Advanced Fraud Checker
- Unlimited courier connections
- Bulk courier orders
- Courier status synchronization
- Advanced automation
- Meta Conversions API
- Event deduplication
- Advanced customer matching
- Advanced event parameters
- Delivered-order Purchase tracking
- Analytics
- Priority support

---

# 22. Initial Courier Roadmap

### Version 1.0

- Steadfast
- Pathao
- RedX

### Future

Potential integrations:

- eCourier
- Paperfly
- CarryBee
- Other Bangladesh courier services
- International courier services

The courier system should be designed so new integrations can be added as separate modules.

---

# 23. Development Priority

## Phase 1 — Foundation

- Plugin architecture
- WooCommerce integration
- REST API
- React admin dashboard
- Settings system
- Logging system
- Licensing structure

## Phase 2 — Courier

- Courier interface
- Steadfast
- Pathao
- RedX
- One-click order
- Tracking
- Status handling

## Phase 3 — Fraud

- Customer history
- Fraud API integration
- Fraud score
- Risk level
- Order-level fraud UI

## Phase 4 — Tracking

- Meta Pixel
- WooCommerce events
- Dynamic event parameters
- Meta CAPI
- Event deduplication
- FBP/FBC
- Advanced matching

## Phase 5 — Automation

- Bulk orders
- Automatic fraud check
- Automatic courier selection

## Phase 6 — GA4 and Tiktok Pixel
- GA4 Pixel
- Tiktok pixel


---

# 24. Product Differentiator

The plugin should not be positioned as only a courier plugin or only a tracking plugin.

The main positioning should be:

> **All-in-One WooCommerce COD Automation**

### Core Value Proposition

```text
🛡️ Prevent Fraud
      +
🚚 Automate Courier Orders
      +
📊 Improve Meta Tracking
      =
⚡ Better COD Store Management
```

The strongest Pro combination will be:

**Fraud Detection + Courier Automation + Meta CAPI + COD-aware Purchase Tracking**

This combination gives the plugin a strong reason to upgrade from Free to Pro while keeping the Free version genuinely useful.



Now start implementing Phase 5 automation features in OrderPilot.

### 1. Bulk Orders
- Add bulk "Send to Courier" action to the WooCommerce Orders page.
- Allow selecting multiple orders and choosing a courier.
- Process orders safely in the background/queue.
- Show success and failed order results.
- Add retry support for failed orders.

### 2. Automatic Fraud Check
- Add an option to automatically check fraud before creating a new WooCommerce order when the user enters a phone number in the checkout phone field.
- Send the customer's phone number to the configured fraud API (BDCourier, direct courier APIs, and store history).
- Save the fraud result, score (0–100), risk level (Low, Medium, High), and policy action taken.
- Allow merchants to enable/disable automatic checking from Settings → Fraud Checker.
- Add configurable **Auto-Block Fraud Score Threshold (%)**:
  - e.g., if fraud risk score/ratio reaches or exceeds the threshold (e.g. 80%), automatically block the order at checkout and prevent courier dispatch. Otherwise, the order is automatically approved.

### 3. Automatic Courier Selection
- Allow merchants to create courier selection rules.
- Support rules based on conditions such as:
  - Customer location
  - Delivery area
  - Order amount
  - Product/category
  - Courier priority
- Automatically select the appropriate connected courier when an order is ready to ship.
- Support fallback courier selection if the primary courier fails.
