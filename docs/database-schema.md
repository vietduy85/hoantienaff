# Database Schema

## Tables (22 migrations)

### Core Laravel Tables

| Table | Key Fields | Purpose |
|-------|------------|---------|
| `users` | id, name, email, password, referral_code, referred_by, wallet_balance, total_earned, total_withdrawn, phone, avatar, google_id, status | User accounts with affiliate fields |
| `password_reset_tokens` | email, token | Password resets |
| `sessions` | id, user_id, ip_address, user_agent, payload, last_activity | Session storage |
| `cache` | key, value, expiration | Cache store |
| `cache_locks` | key, owner, expiration | Cache locking |
| `jobs` | id, queue, payload, attempts, reserved_at, available_at, created_at | Queue jobs |
| `job_batches` | id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at | Job batching |
| `failed_jobs` | id, uuid, connection, queue, payload, exception, failed_at | Failed job tracking |

### Spatie Permission Tables

| Table | Key Fields |
|-------|------------|
| `permissions` | id, name, guard_name, created_at, updated_at |
| `roles` | id, name, guard_name, created_at, updated_at |
| `model_has_permissions` | permission_id, model_type, model_id |
| `model_has_roles` | role_id, model_type, model_id |
| `role_has_permissions` | permission_id, role_id |

### Affiliate System Tables

#### `link_requests`
Core table for user-submitted URL → affiliate link tracking.

| Field | Type | Notes |
|-------|------|-------|
| id | bigint PK | Auto-increment |
| user_id | bigint FK→users | Owner |
| original_url | varchar(2048) | User-submitted URL |
| platform | varchar(50) | Shopee, Lazada, TikTok Shop, Tiki, Khác |
| affiliate_url | varchar(2048) | Generated short link |
| estimated_cashback | decimal(12,2) | Seller commission |
| user_estimated_cashback | decimal(12,2) | User's cashback after platform fee |
| cashback_rate | decimal(5,2) | Percentage applied |
| status | varchar(20) | pending → processing → completed / failed / rejected |
| is_pinned | boolean | Max 5 pinned links |
| pinned_at | timestamp | When pinned |
| item_id | bigint | Shopee product ID |
| shop_id | bigint | Shopee shop ID |
| product_name | varchar(500) | From AddLiveTag API |
| product_price | decimal(15,2) | Product price |
| product_link | varchar(2048) | Shopee product page |
| seller_commission | decimal(5,2) | Seller commission rate |
| shopee_commission | decimal(5,2) | Shopee commission rate |
| rating | decimal(3,2) | Product rating |
| is_xtra | boolean | Xtra program flag |
| product_image | varchar(2048) | Product image URL |
| shop_name | varchar(500) | Shop name |
| sales | int | Sales count |
| data_source | varchar(50) | API source |
| notes | text | Admin notes |
| created_at / updated_at | timestamps | |

**Status flow:** `pending` → `processing` (when Worker picks up job) → `completed`/`failed`

#### `affiliate_cache`
Daily cache of product data keyed by (item_id, cache_date).

| Field | Type | Notes |
|-------|------|-------|
| item_id | bigint | Composite PK part 1 |
| cache_date | date | Composite PK part 2 (added in migration 2026_06_30_150000) |
| shop_id | bigint | |
| product_name | varchar(500) | |
| product_price | decimal(15,2) | |
| seller_commission | decimal(5,2) | |
| shopee_commission | decimal(5,2) | |
| estimated_cashback | decimal(12,2) | |
| user_estimated_cashback | decimal(12,2) | |
| cashback_rate | decimal(5,2) | |
| affiliate_url | varchar(2048) | Filled when Worker creates link |
| last_affiliate_created_at | timestamp | When affiliate URL was generated |
| rating | decimal(3,2) | |
| sales | int | |
| product_image | varchar(2048) | |
| product_link | varchar(2048) | |
| shop_name | varchar(500) | |
| is_xtra | boolean | |
| data_source | varchar(50) | |

#### `campaigns`
Marketing campaigns from merchants.

| Field | Notes |
|-------|-------|
| id, merchant_id (FK), category_id (FK) | |
| type | store / product |
| name, slug, description, image | |
| cashback_type / cashback_value | |
| commission_type / commission_value | |
| affiliate_share | |
| url, tracking_url | |
| start_date, end_date | |
| is_featured, is_verified, sort_order | |
| status | draft / active / paused / expired |

#### `campaign_categories`
| Field | Notes |
|-------|-------|
| id, name, slug (unique), description, icon, sort_order, is_active | |

#### `merchants`
| Field | Notes |
|-------|-------|
| id, user_id (unique FK), store_name, slug (unique) | |
| website, description, logo | |
| commission_rate | Default 15% |
| status | pending / active / suspended |

#### `clicks`
| Field | Notes |
|-------|-------|
| id, campaign_id (FK), affiliate_id (FK→users), member_id (FK→users) | |
| ip_address, user_agent, referrer_url | |
| clicked_at, converted | |

#### `purchases`
| Field | Notes |
|-------|-------|
| id, click_id (FK), campaign_id (FK), member_id (FK), affiliate_id (FK) | |
| order_id, order_amount | |
| cashback_amount, commission_amount, affiliate_commission | |
| status | pending / approved / confirmed / cancelled / refunded |
| confirmed_at, admin_note | |

#### `transactions`
| Field | Notes |
|-------|-------|
| id, user_id (FK) | |
| type | cashback_earned / commission_earned / withdrawal / referral_bonus / adjustment |
| amount, balance_before, balance_after | |
| description, reference (morph), status | |

#### `withdrawals`
| Field | Notes |
|-------|-------|
| id, user_id (FK) | |
| amount, fee, net_amount | |
| payment_method | bank / momo / vnpay |
| bank_name, bank_account, bank_holder | |
| status | pending / processing / completed / rejected |
| processed_at, processed_by (FK) | |

#### `settings`
| Field | Notes |
|-------|-------|
| id, key (unique), value (text) | Cached key-value store |

### Roles and Permissions (Seeded)

**Roles:** Admin, Merchant, Affiliate, Member

**Permissions:**
- `users.view`, `users.manage`
- `campaigns.view`, `campaigns.manage`
- `cashback.view`, `cashback.manage`
- `withdrawals.view`, `withdrawals.manage`
- `settings.manage`

**Default admin:** `admin@hoantien.local` / `password`
