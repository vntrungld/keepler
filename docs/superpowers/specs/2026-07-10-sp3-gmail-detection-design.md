# Orbit — Sub-project 3: Tự động phát hiện dịch vụ qua Gmail (Design Spec)

- **Ngày:** 2026-07-10
- **Dự án:** Orbit — Quản lý dịch vụ đăng ký hàng tháng
- **Sub-project:** SP3 — Tự động phát hiện dịch vụ (quét Gmail, rule-based)
- **Trạng thái:** Đã duyệt thiết kế, chờ review spec
- **Tiền đề:** SP1 (nền tảng + Google auth + CRUD subscription) và SP2 (UI vũ trụ) đã hoàn thành & merge.

---

## 1. Bối cảnh & Mục tiêu

SP1 đã có đăng nhập Google, model `Subscription`, CRUD **nhập tay** đầy đủ (form Create/Edit + `SubscriptionRequest`). SP2 đã có UI vũ trụ. SP3 hiện thực tính năng khác biệt còn lại: **tự động phát hiện** các dịch vụ đăng ký từ hộp thư Gmail bằng **luật (rule-based)** trên một catalog nhà cung cấp đã biết, để người dùng khỏi phải gõ tay từng gói.

**Nguyên tắc:** không đoán mò bằng AI, không tự tạo dữ liệu ngầm. App đọc mail, so khớp catalog, **đề xuất** ứng viên; người dùng **xác nhận/sửa** trước khi lưu.

**Nhập tay** đã giao ở SP1 nên **ngoài phạm vi** SP3 (chỉ dùng lại luồng CRUD sẵn có cho provider ngoài catalog).

---

## 2. Phạm vi SP3

### Trong phạm vi
- "Kết nối Gmail": OAuth riêng xin scope `gmail.readonly` (offline, lấy refresh token), lưu token mã hóa; "Ngắt kết nối" xóa token.
- Catalog nhà cung cấp trong `config/providers.php` (~8–12 provider), dùng chung `key` với catalog màu SP2.
- Pipeline quét: `GmailClient` (Laravel HTTP client) → `SubscriptionScanner` → `ProviderMatcher` + `ReceiptParser`.
- Phân loại **ý định** email: `payment` (đang dùng) vs `cancellation` (đã hủy).
- Trang "Kết quả quét": thẻ ứng viên sửa/tick được, chống trùng, xử lý mail hủy theo ngữ cảnh.
- Nhập hàng loạt các gói được chọn (tạo mới / cập nhật trạng thái) — scope theo user.
- Thêm cột `provider_key` cho `subscriptions`; 3 cột token cho `users` (migration **mới**).
- Dọn kỹ thuật đã hoãn từ SP1: bỏ `user_id` khỏi `Subscription::$fillable` (chuyển factory/test sang `->for()`).
- Test theo TDD.

### Ngoài phạm vi (để dành sau)
- Quét nền/định kỳ tự động (SP4 — Quản lý & Nhắc nhở).
- Xin Google verification cho production (chỉ chạy Testing mode với test users).
- Provider **ngoài** catalog → dùng nhập tay của SP1.
- Đọc file đính kèm / PDF hóa đơn.
- Nâng cấp trải nghiệm form nhập tay.

---

## 3. Quyết định thiết kế (chốt qua brainstorm)

| Chủ đề | Quyết định |
|--------|-----------|
| Trọng tâm | Chỉ tự động quét Gmail; nhập tay coi như đã giao ở SP1 |
| Quyền Gmail | `gmail.readonly`, **Testing mode** (test users), hoãn verify production |
| Trích xuất | **Best-effort**, luôn cho user sửa/xác nhận trước khi lưu |
| Catalog | File `config/providers.php`, ~8–12 provider, `key` dùng chung với màu SP2 |
| Kích hoạt | Nút **"Quét Gmail"** bấm tay (đồng bộ, có giới hạn phạm vi); tự động để dành SP4 |
| UI xác nhận | Trang "Kết quả quét" riêng, thẻ sửa/tick, chống trùng, nhập hàng loạt |
| Truy cập Gmail | **Laravel HTTP client + `GmailClient` wrapper** (không dùng google/apiclient SDK) |
| Data model | +`provider_key` cho `subscriptions`; +3 cột token cho `users` (đều migration mới) |
| Mail hủy | **Theo ngữ cảnh**: có gói active → đề xuất cập nhật `cancelled`; không có → bỏ qua (hiện mờ) |

---

## 4. Kết nối Gmail (OAuth)

- Login SP1 (`email/profile`) **không đổi**. Quyền đọc mail là *restricted scope*, không ép vào mọi lần login.
- Hành động riêng, chỉ cho user đã đăng nhập:
  - `GET /gmail/connect` → Socialite driver `google` với scope thêm `https://www.googleapis.com/auth/gmail.readonly`, `access_type=offline`, `prompt=consent` (để chắc chắn nhận **refresh token**).
  - `GET /gmail/callback` → nhận token, lưu vào `users`.
  - `DELETE /gmail/disconnect` → xóa 3 cột token (set null).
- **Lưu token** trên `users`: `gmail_refresh_token`, `gmail_access_token`, `gmail_token_expires_at`. Hai cột token dùng cast **`encrypted`** (mã hóa at-rest); `gmail_token_expires_at` cast `datetime`.
- Helper `user->hasGmailConnected()` = có `gmail_refresh_token`.

---

## 5. Catalog nhà cung cấp — `config/providers.php`

Mảng, mỗi phần tử:

```php
'netflix' => [
    'name'                  => 'Netflix',
    'sender_domains'        => ['netflix.com', 'members.netflix.com'],
    'payment_keywords'      => ['receipt', 'payment', 'hóa đơn', 'gia hạn'],
    'cancellation_keywords' => ['cancelled', 'canceled', 'membership ended', 'đã hủy'],
    'amount_regex'          => '/(?:US)?\$\s?([0-9][0-9.,]*)/',
    'default_currency'      => 'USD',
    'default_cycle'         => 'monthly',
    'cancel_url'            => 'https://www.netflix.com/cancelplan',
],
```

- `key` (khóa mảng) trùng khóa catalog màu SP2 → hành tinh nhập vào có màu đúng ngay.
- Khởi đầu ~8–12: netflix, spotify, youtube, chatgpt(openai), google (One), adobe, apple, amazon(prime), disney, … (mở rộng bằng cách thêm mảng).
- `amount_regex` và `default_currency` là *gợi ý* mỗi provider; parser vẫn best-effort.

---

## 6. Pipeline quét

### 6.1 `GmailClient` (bọc Gmail REST API)
- Dùng `Illuminate\Support\Facades\Http` (KHÔNG kéo google/apiclient) → test bằng `Http::fake()`.
- Phương thức:
  - `listMessageIds(string $query, int $max): array` — gọi `GET /gmail/v1/users/me/messages?q=...&maxResults=...`.
  - `getMessage(string $id): array` — gọi `GET /gmail/v1/users/me/messages/{id}?format=full`; trả header (From/Subject/Date) + body (giải mã base64url phần `text/plain` hoặc `text/html`→text).
  - Tự đảm bảo access token còn hạn: nếu hết hạn, dùng `gmail_refresh_token` gọi token endpoint để lấy access token mới + cập nhật `users`.
- Nhận `User` (để đọc/ghi token). Lỗi mạng/401 → ném exception có kiểm soát để scanner xử lý.

### 6.2 `SubscriptionScanner` (điều phối)
- Dựng query giới hạn để nhanh & rẻ:
  - Chỉ domain trong catalog: `from:(netflix.com OR spotify.com OR ...)`.
  - `newer_than:1y`.
  - Trần số mail (VD `maxResults` ~ 50–100) — ghi rõ nếu bị cắt.
- Với mỗi mail: `getMessage` → `ProviderMatcher` (provider nào?) → `ReceiptParser` (intent + số tiền/tiền tệ/ngày).
- **Gộp theo provider + cycle, lấy email mới nhất quyết định trạng thái hiện tại.** Trả về danh sách **candidate** (không đụng DB).

### 6.3 `ProviderMatcher` (thuần)
- Vào: header `From`, `Subject`. Ra: `provider_key | null`.
- Khớp `sender_domains` (chắc), có thể phụ trợ bằng `Subject`. Không khớp domain nào → bỏ (provider ngoài catalog → nhập tay).

### 6.4 `ReceiptParser` (thuần)
- Vào: provider config + subject + body + ngày mail. Ra:
  `{ intent, amount|null, currency|null, billing_cycle, next_renewal_date|null, confidence }`.
- `intent`: khớp `cancellation_keywords` → `cancellation`; ngược lại (khớp payment hoặc mặc định) → `payment`.
- `amount`: `amount_regex` best-effort → chuẩn hóa số (bỏ dấu phân cách); không chắc → `null`.
- `currency`: từ ký hiệu/mã trong mail, fallback `default_currency`.
- `billing_cycle`: suy từ từ khóa ("year"/"annual"/"năm" → yearly), fallback `default_cycle`.
- `next_renewal_date`: từ ngày mail + cycle (payment) — best-effort; `null` nếu không rõ.
- `confidence`: gắn cờ trường nào chắc/không để UI đánh dấu.

---

## 7. Candidate (cấu trúc trung gian, không lưu DB)

```
{
  provider_key, name, intent,        // payment | cancellation
  amount|null, currency|null,
  billing_cycle, next_renewal_date|null,
  cancel_url, confidence,
  source_email_id,
  action,                            // create | update_status | skip  (do dedup quyết)
  duplicate_of|null                  // id gói hiện có nếu trùng
}
```

---

## 8. Trang "Kết quả quét" + chống trùng + nhập

### 8.1 Route
- `GET /gmail/scan` — chạy quét đồng bộ, render Inertia `Gmail/ScanResults` với `candidates`. Yêu cầu đã kết nối Gmail (chưa kết nối → chuyển tới bước kết nối).
- `POST /gmail/import` — nhận các ứng viên được tick + dữ liệu đã sửa, thực thi.

### 8.2 Chống trùng & hành động
So mỗi candidate với gói hiện có của user theo `provider_key` + `billing_cycle`:
- **payment, chưa có gói** → `action = create`, tick sẵn.
- **payment, đã có gói** → `action = skip`, **bỏ tick**, nhãn "🔁 đã có".
- **cancellation, có gói active** → `action = update_status`, thẻ *"Đề xuất đánh dấu đã hủy"* (tick để đổi status gói đó → `cancelled`).
- **cancellation, không có gói** → `action = skip`, hiện **mờ**, nhãn "đã hủy — không nhập".

### 8.3 UI
- Mỗi candidate là 1 thẻ: tên, provider (màu), số tiền/tiền tệ/chu kỳ/ngày **sửa tại chỗ**, ô tick, nhãn hành động/trùng. Trường "chưa chắc" tô nhấn.
- Nút **"Nhập N mục đã chọn"** → POST → tạo/cập nhật → chuyển về vũ trụ (`/dashboard`).
- Nút phụ: quét lại, về danh sách.

### 8.4 Import (server)
- Duyệt các mục được tick:
  - `create` → validate bằng luật của `SubscriptionRequest` (tái dùng), tạo qua `$user->subscriptions()->create([... , 'provider_key' => ...])`. `status` mặc định `active`. `amount_vnd` tự tính (hook SP1).
  - `update_status` → tìm gói (kiểm tra thuộc user), set `status = 'cancelled'`, save.
- **Scope chặt theo user** (không cho đụng gói user khác — dùng quan hệ/`authorize`).

---

## 9. Thay đổi data model (migration MỚI)

> Bài học SP2: **không sửa migration cũ** — luôn tạo migration mới cho bảng đã tồn tại.

- `users` (migration mới): thêm `gmail_access_token` (text, nullable), `gmail_refresh_token` (text, nullable), `gmail_token_expires_at` (timestamp, nullable). Cast: hai token `encrypted`, expires `datetime`. Thêm vào `#[Fillable]` **KHÔNG** chứa các cột token (chỉ gán nội bộ) — hoặc để ngoài fillable và set trực tiếp.
- `subscriptions` (migration mới): thêm `provider_key` (string, nullable, index). Thêm vào `Subscription::$fillable`.
- **Dọn SP1:** bỏ `user_id` khỏi `Subscription::$fillable`; cập nhật `SubscriptionFactory` và test dùng `->for($user)` thay vì gán `user_id`.

---

## 10. Kiểm thử (TDD)

### 10.1 Unit thuần (PHPUnit)
- `ProviderMatcher`: khớp đúng provider theo domain; không khớp → null; không phân biệt hoa/thường.
- `ReceiptParser`: với **mẫu email thật** của vài provider (đa định dạng, đa tiền tệ, EN/VI):
  - trích đúng số tiền + tiền tệ; trường không chắc → null.
  - phân loại `intent` payment vs cancellation đúng theo keyword.
  - suy `billing_cycle` yearly/monthly đúng.

### 10.2 `GmailClient` với `Http::fake()`
- `listMessageIds`/`getMessage` gọi đúng endpoint, parse đúng body base64url.
- Access token hết hạn → tự refresh (fake token endpoint) + cập nhật `users`.
- 401/lỗi mạng → ném exception xử lý được.

### 10.3 Feature (PHPUnit class style, KHÔNG Pest)
- Connect callback lưu token (mã hóa) vào user; disconnect xóa token.
- `GET /gmail/scan` (fake Gmail) trả candidates đúng: create/skip(trùng)/update_status/skip(hủy) đúng nhãn.
- `POST /gmail/import`: tạo đúng gói được tick (kèm `provider_key`, `amount_vnd`); `update_status` đổi status; **user A không import/cập nhật vào gói user B** (403/không đổi).
- Chưa kết nối Gmail mà vào `/gmail/scan` → chuyển tới kết nối.

---

## 11. Kiến trúc & file (dự kiến)

- `config/providers.php` — catalog.
- `app/Support/Gmail/GmailClient.php` — bọc REST API + refresh token.
- `app/Support/Gmail/ProviderMatcher.php` — thuần.
- `app/Support/Gmail/ReceiptParser.php` — thuần.
- `app/Support/Gmail/SubscriptionScanner.php` — điều phối, trả candidates.
- `app/Http/Controllers/GmailController.php` — connect/callback/disconnect/scan/import.
- `app/Http/Requests/GmailImportRequest.php` — validate payload import (tái dùng luật subscription).
- Migrations mới cho `users` (token) và `subscriptions` (`provider_key`).
- `resources/js/Pages/Gmail/ScanResults.vue` + component thẻ ứng viên.
- Nút "Kết nối Gmail"/"Quét Gmail" trên dashboard hoặc trang danh sách.

---

## 11b. Lộ trình "tự động cập nhật khi hủy" (ngoài phạm vi SP3)

**Giới hạn cốt lõi:** Orbit không có kết nối trực tiếp tới nhà cung cấp (Netflix…). Khi user hủy trên web của dịch vụ, tín hiệu **duy nhất** mà app bắt được là **email "đã hủy / membership ended"** nhà cung cấp gửi về Gmail. Do đó "biết user đã hủy" ⇒ phải đọc email đó ⇒ phải quét Gmail. Nguồn sự thật luôn là trường `status`; Orbit đọc `status` **live** mỗi lần tải dashboard (không phải ảnh chụp lúc quét), nên mọi thay đổi `status` tự phản ánh mà không cần quét lại.

Ba mức độ tự động, làm dần:
1. **SP3 (mục này) — Quét tay:** user bấm "Quét Gmail" → bắt mail hủy → cập nhật `status` → Orbit tự cập nhật. Chạy được local, không hạ tầng thêm.
2. **SP4 — Quét ngầm định kỳ:** scheduler + queue/worker quét tự động (VD mỗi ngày), refresh token nền → user không cần bấm; trễ tối đa = chu kỳ quét. Đơn giản, chạy mọi nơi.
3. **SP5+ — Gmail push (webhook qua Cloud Pub/Sub):** `users.watch` → Gmail đẩy thông báo vào Pub/Sub → push tới endpoint HTTPS của app → `users.history.list` lấy mail mới → cập nhật gần real-time. Cần deploy công khai (HTTPS) + Pub/Sub + xác thực push OIDC + gia hạn `watch` (≤7 ngày). Nâng cấp khi lên production.

SP3 **không** hiện thực mức 2/3; chỉ ghi nhận lộ trình để kiến trúc (đặc biệt `SubscriptionScanner` + xử lý `cancellation` intent) tái dùng được cho các mức sau.

## 12. Tiêu chí hoàn thành SP3

- Kết nối/ngắt Gmail hoạt động; token lưu mã hóa.
- Bấm "Quét Gmail" trả về danh sách ứng viên đúng theo catalog, phân loại payment/cancellation, đánh dấu trùng.
- Trang Kết quả quét cho sửa/tick và nhập hàng loạt; tạo mới & cập nhật "đã hủy" đúng, scope theo user.
- Gói nhập vào có `provider_key` + màu đúng, hiện trên vũ trụ (SP2).
- Dọn xong `user_id` khỏi `$fillable`.
- Toàn bộ test mục 10 pass; app vẫn xanh suite cũ.
