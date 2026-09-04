# Orbit — Sub-project 4: Redesign giao diện di động, Danh mục/Phương thức thanh toán, Calendar & Nhắc nhở gia hạn (Design Spec)

- **Ngày:** 2026-09-04
- **Dự án:** Orbit — Quản lý dịch vụ đăng ký hàng tháng
- **Sub-project:** SP4 — Redesign UI theo app tham khảo (tryorbit.com) + tính năng mới
- **Trạng thái:** Đã duyệt thiết kế qua brainstorm, chờ review spec
- **Tiền đề:**
  - SP1–SP3 đã hoàn thành: nền tảng auth, vũ trụ hành tinh (`/dashboard`), quét Gmail.
  - Một restyle màu sắc (nền tối + accent tím violet, thay thế Laravel Breeze mặc định) đã được thực hiện **trước** SP4 này trên toàn bộ 29 file Vue hiện có, dùng bảng màu `midnight-*` + `violet-*` trong `tailwind.config.js`. SP4 xây **trên nền** giao diện tối/tím đó — không làm lại phần màu sắc cơ bản.

---

## 1. Bối cảnh & Mục tiêu

Người dùng cung cấp 3 ảnh chụp app iOS thật của "Orbit" (tryorbit.com) cho thấy một mức độ hoàn thiện UI/tính năng cao hơn nhiều so với hiện trạng: icon thương hiệu thật thay vì chữ cái, thanh tab điều hướng dưới cùng kiểu app di động, màn hình chi tiết/sửa với nhiều trường mới (List, Category, Payment Method, Free Trial, Billing/Price History), và một trang Calendar hiển thị lịch gia hạn theo tháng.

Mục tiêu SP4: đưa Orbit tới gần trải nghiệm app tham khảo, đồng thời bổ sung một tính năng thực chất mà ảnh mẫu chỉ gợi ý — **nhắc nhở gia hạn qua email thật** (ảnh gốc dùng push notification native, không khả dụng cho web app, nên thay bằng email + lịch chạy nền, chốt qua trao đổi với người dùng).

---

## 2. Phạm vi SP4

### Trong phạm vi
- Schema mới: `list`, `category`, `payment_method_id`, `is_trial`, `started_at` trên `subscriptions`; bảng `payment_methods`; bảng `subscription_events` (log Billing History / Price History).
- Cột + tính năng nhắc nhở gia hạn qua email (bảng `users` thêm 2 cột, lệnh Artisan chạy lịch, `Notification` Laravel qua Mailpit).
- Redesign trang Dashboard: nút "+", vệ tinh orbit dạng chấm tròn trơn, hàng thống kê (số lượng + lọc theo `list` + tổng chi phí/năm).
- Redesign hàng danh sách subscription: icon thương hiệu (thư viện `simple-icons`, fallback chữ cái), "Renews in X ngày", giá, chevron.
- Trang chi tiết subscription mới (`/subscriptions/{id}`) thay panel bên hông trên mobile: Billing History, nút Mark as Cancelled/Delete.
- Redesign form Tạo/Sửa subscription: List, Category, Payment Method, Free Trial, banner nhắc email, link Billing/Price History.
- Trang Calendar mới (`/calendar`): lưới tháng, chấm icon thương hiệu ở ngày gia hạn, tổng/sắp tới chi phí tháng, tất cả tính client-side từ dữ liệu subscription có sẵn.
- Mục "Nhắc nhở gia hạn" (bật/tắt + số ngày trước hạn) thêm vào trang Profile hiện có (không tạo route/trang Settings riêng).
- Bottom tab bar (Subscriptions/Calendar/Cài đặt) chỉ hiện trên mobile; desktop giữ nav ngang hiện tại + thêm link Calendar.

### Ngoài phạm vi (để dành sau, hoặc không cần thiết cho web app)
- Push notification trình duyệt/native thật (thay bằng email theo quyết định đã chốt).
- Quản lý số thẻ thật / tích hợp cổng thanh toán cho `payment_methods` (chỉ là nhãn hiển thị).
- Kéo-thả/sắp xếp thủ công trên Calendar hoặc Orbit.
- Đa tiền tệ động qua API tỉ giá (giữ nguyên `CurrencyConverter` tĩnh hiện có).
- Tuỳ biến/tạo mới giá trị `list` hay `category` qua UI quản trị riêng (dùng danh sách cố định + input tự do, xem mục 3).

---

## 3. Quyết định thiết kế (chốt qua brainstorm)

| Chủ đề | Quyết định |
|--------|-----------|
| `list` (Personal/Business/Family) | Cột string đơn giản trên `subscriptions`, tập giá trị cố định `personal\|business\|family`, **không** tạo bảng `lists` riêng |
| `category` | Cột string nullable, tự do nhập với gợi ý preset (Streaming, Productivity, Utilities, Finance, Health, Education, Other) qua `<datalist>`, **không** tạo bảng `categories` riêng |
| `payment_method` | **Bảng riêng** `payment_methods` (chỉ `label` hiển thị, VD "Visa •••• 1234"), KHÔNG lưu số thẻ thật — chốt qua AskUserQuestion |
| Icon thương hiệu | Thư viện `simple-icons` (MIT) tra theo `provider_key`/tên chuẩn hoá (tái dùng `normalize()` của `brandColors.js`); fallback vòng tròn màu + chữ cái đầu như hiện tại nếu không khớp |
| Notifications (banner UI) | Đổi ý nghĩa so với ảnh gốc: không phải quyền push trình duyệt, mà phản ánh trạng thái nhắc gia hạn qua **email** (email đã verify? nhắc email đang bật/tắt?) |
| Nhắc nhở gia hạn | **Thật**, qua email (Laravel Notification + Mailpit sẵn có) + lệnh Artisan chạy lịch hàng ngày — chốt qua AskUserQuestion (người dùng từ chối phương án chỉ làm banner UI) |
| Trang Settings | **Không** tạo trang/route riêng — thêm section "Nhắc nhở gia hạn" vào `Profile/Edit.vue` hiện có; tab bar mobile trỏ nhãn "Cài đặt" vào route `profile.edit` |
| Trang chi tiết subscription | Trang Inertia đầy đủ `/subscriptions/{id}` (route mới `subscriptions.show`), thay thế `OrbitDetailPanel` trên mọi kích thước màn hình để nhất quán (panel bên hông hiện tại được thay bằng điều hướng sang trang này) |
| Tổng chi phí trong Calendar | Quy đổi hiển thị bằng **₫ (VND)**, dùng `amount_vnd` sẵn có, nhất quán với phần còn lại của app (ảnh gốc dùng £ vì là app UK) |
| Ngôn ngữ | Giữ tiếng Việt xuyên suốt (tên tháng, nhãn) như phần còn lại của Orbit, khác ảnh mẫu tiếng Anh |
| "Total spent" / "Subscribed N ngày" | Tính từ `started_at` + chu kỳ (công thức, không phụ thuộc log event) để đúng cả với dữ liệu cũ chưa có event |
| Billing/Price History | Log vào bảng `subscription_events` (`kind`: `subscribed`, `cancelled`, `price_changed`) khi tạo/sửa subscription; không cần backfill lịch sử cho bản ghi cũ |
| Chống gửi trùng nhắc nhở | Cột `last_reminder_sent_for` (date) trên `subscriptions`, so với `next_renewal_date` hiện tại |

---

## 4. Data model

### 4.1 Migration mới

**`payment_methods`**
```
id
user_id        FK -> users, cascade
label           string(100)   -- "Visa •••• 1234"
timestamps
```

**Sửa `subscriptions`** (migration thêm cột):
```
list                    string(20)   default 'personal'   -- personal|business|family
category                string(50)   nullable
payment_method_id       FK -> payment_methods, nullable, nullOnDelete
is_trial                boolean      default false
started_at              date         nullable             -- fallback: created_at->toDateString()
last_reminder_sent_for  date         nullable             -- ngày next_renewal_date đã gửi nhắc, chống trùng
```

**`subscription_events`**
```
id
subscription_id   FK -> subscriptions, cascade
kind              string(20)     -- subscribed | cancelled | price_changed
amount            decimal(12,2)  nullable
currency          string(3)      nullable
occurred_at       date
timestamps
```

**Sửa `users`** (migration thêm cột):
```
renewal_reminders_enabled  boolean      default true
reminder_days_before       unsignedTinyInteger  default 3
```

### 4.2 Model

- `PaymentMethod` — `belongsTo(User)`, `hasMany(Subscription)`.
- `Subscription`:
  - Thêm vào `$fillable`: `list, category, payment_method_id, is_trial, started_at`.
  - Thêm cast: `started_at => date`, `is_trial => boolean`, `last_reminder_sent_for => date`.
  - Quan hệ mới: `belongsTo(PaymentMethod::class)`, `hasMany(SubscriptionEvent::class, ...)->orderByDesc('occurred_at')`.
  - Accessor `getSubscribedDaysAttribute()`: `now()->diffInDays($this->started_at ?? $this->created_at)`.
  - Accessor `getTotalSpentAttribute()`: số chu kỳ đã hoàn thành kể từ `started_at` (`floor(diffInDays / cycleLengthDays)`, `cycleLengthDays` = 30 cho monthly / 365 cho yearly) nhân `amount`. Trả `0` nếu chưa qua chu kỳ nào.
  - Giữ nguyên hook `saving` tính `amount_vnd` hiện có.
- `SubscriptionEvent` — `belongsTo(Subscription)`.
- `User`: thêm cast `renewal_reminders_enabled => boolean`; không thêm quan hệ mới ngoài `hasMany(PaymentMethod::class)`.

### 4.3 Backend logic thay đổi

- `SubscriptionRequest` (validation): thêm `list` (`in:personal,business,family`, default `personal`), `category` (`nullable|string|max:50`), `payment_method_id` (`nullable|exists:payment_methods,id` — scope theo user qua rule tuỳ chỉnh hoặc kiểm tra ownership trong controller), `is_trial` (`boolean`), `started_at` (`nullable|date`).
- `SubscriptionController@store`: sau khi tạo, ghi 1 `SubscriptionEvent` `kind=subscribed`, `amount, currency` theo bản ghi vừa tạo, `occurred_at = started_at ?? today`.
- `SubscriptionController@update`:
  - Nếu `amount` mới khác `amount` cũ → ghi event `kind=price_changed`, `occurred_at = today`.
  - Nếu `status` chuyển **sang** `cancelled` từ trạng thái khác → ghi event `kind=cancelled`, `occurred_at = today`.
- `SubscriptionController@show` (route mới) — trả trang chi tiết, `events` (10 gần nhất, mới nhất trước) nạp kèm qua eager load.
- `PaymentMethodController` mới — `index` (JSON hoặc Inertia partial để dropdown), `store` (tạo nhanh label mới ngay trong form Sửa/Tạo subscription qua modal nhỏ hoặc input "+ Thêm phương thức").
- `ProfileController@update` (hoặc method mới `updateNotificationPreferences`) — nhận `renewal_reminders_enabled`, `reminder_days_before`, lưu vào `User`.

---

## 5. Nhắc nhở gia hạn qua email

- `App\Notifications\SubscriptionRenewalReminder` — Laravel `Notification` implement `ShouldQueue`? (không bắt buộc queue worker mới nếu dự án chưa có, chạy đồng bộ trong lệnh Artisan là đủ cho quy mô hiện tại). Kênh `mail`. Nội dung: tên dịch vụ, số tiền, ngày gia hạn, link tới `subscriptions.show`, link hủy nhanh (`cancel_url` nếu có).
- `App\Console\Commands\SendRenewalReminders` (`subscriptions:send-renewal-reminders`):
  - Query: `Subscription::where('status', 'active')->whereHas('user', fn($q) => $q->where('renewal_reminders_enabled', true)->whereNotNull('email_verified_at'))`.
  - Với mỗi bản ghi: tính `targetDate = next_renewal_date->subDays(user->reminder_days_before)`; nếu `targetDate->isToday()` **và** `last_reminder_sent_for != next_renewal_date` → gửi notification, cập nhật `last_reminder_sent_for = next_renewal_date`.
- Đăng ký lịch trong `routes/console.php`: `Schedule::command('subscriptions:send-renewal-reminders')->dailyAt('08:00')`.
- Mail gửi qua transport hiện có (Mailpit ở môi trường dev, đã chạy sẵn trong Spin stack — không cần cấu hình thêm).

### Banner "Notifications" trên trang Sửa dịch vụ
- Nếu `!user.email_verified_at` → banner amber: "Xác thực email để nhận nhắc nhở gia hạn" + link gửi lại email xác thực (route `verification.send` có sẵn).
- Nếu email đã verify nhưng `renewal_reminders_enabled = false` → banner xám: "Nhắc gia hạn qua email đang tắt · Bật trong Cài đặt" (link tới `profile.edit#reminders`).
- Nếu đã bật và verify → không hiện banner.

---

## 6. Frontend

### 6.1 Thư viện mới
- `simple-icons` (npm) — icon SVG thương hiệu. Wrapper component `orbit/BrandIcon.vue`: nhận `name`/`providerKey`, tra `simple-icons` theo slug chuẩn hoá (dùng lại `normalize()` từ `brandColors.js`), render `<svg>` inline với `fill` = màu thương hiệu hiện có (`brandColor()`); nếu không tìm thấy icon phù hợp → fallback vòng tròn màu + chữ cái đầu (component hiện tại, giữ nguyên logic).

### 6.2 Component/trang mới
- `orbit/calendar.js` — module thuần (giống phong cách `layout.js`):
  - `projectOccurrences(subscriptions, year, monthIndex, today)` → mảng `{date, subscription}` cho mọi lần gia hạn rơi vào tháng, loại `cancelled`, chặn dưới bởi `started_at`.
  - `groupByDate(occurrences)` → map `date -> [{subscription, ...}]`.
  - `monthTotals(occurrences, today)` → `{ total, upcoming }` (VND, dùng `amount_vnd`).
- `Pages/Calendar/Index.vue` — trang mới, dùng `orbit/calendar.js`, state `viewedMonth` (ref), nút lùi/tiến/"Hôm nay", lưới Mon–Sun, click ngày → mở danh sách nhỏ bên dưới (tái dùng style hàng của `SubscriptionList`).
- `Pages/Subscriptions/Show.vue` — trang chi tiết mới: icon + tên + giá; hàng Billing/Next payment/Total spent/Subscribed days/Category; section **Lịch sử** gộp chung hiển thị mọi `events` theo thời gian (nhãn theo `kind`: "Đã đăng ký" / "Đổi giá thành X" / "Đã hủy") — gộp Billing History + Price History làm một danh sách duy nhất thay vì hai section riêng, tránh trùng lặp với phần liên kết ở trang Sửa (xem 6.3); nút Mark as Cancelled (PATCH status) + Delete (dùng lại confirm hiện có).
- `Components/BottomTabBar.vue` — 3 tab (Subscriptions→`dashboard`, Calendar→`calendar.index`, Cài đặt→`profile.edit`), ẩn ở `sm:` trở lên, cố định `fixed bottom-0`, style pill nổi giống ảnh mẫu, tôn trọng safe-area (`env(safe-area-inset-bottom)`).
- `Pages/Profile/Partials/NotificationPreferencesForm.vue` — mới, thêm vào `Profile/Edit.vue`: toggle `renewal_reminders_enabled`, select `reminder_days_before` (1/3/7 ngày).

### 6.3 Component/trang sửa đổi
- `orbit/Planet.vue` — bỏ `<text>` chữ cái đầu, giữ nguyên màu/viền/glow.
- `orbit/SubscriptionList.vue` — đổi bố cục hàng: `BrandIcon` bên trái, tên + "Renews in X ngày · ngày" ở giữa, giá + chevron bên phải; bấm hàng → điều hướng `subscriptions.show` (thay vì các nút Sửa/Xóa inline hiện tại, các hành động này chuyển vào trang chi tiết).
- `Pages/Dashboard.vue` — thêm nút "+" nổi góc phải trên khối orbit (→ `subscriptions.create`); thêm hàng thống kê dưới orbit: dropdown lọc `list` (mặc định "Tất cả") lọc **client-side** trên mảng `subscriptions` đã nạp sẵn (không gọi lại server), số lượng + tổng chi phí/năm tính lại theo kết quả lọc; thêm toggle 2 chế độ sắp xếp phía trên `SubscriptionList`, **client-side**: "Active" (nhóm `active` lên đầu, rồi `pending_cancel`, rồi `cancelled`) và "Next" (sắp thuần theo `next_renewal_date` tăng dần, bỏ qua trạng thái).
- `Pages/Subscriptions/Create.vue` / `Edit.vue` — thêm field List (select), Category (input + datalist), Payment Method (select, options nạp từ prop `paymentMethods` truyền sẵn theo user; ô "+ Thêm phương thức" gọi `axios.post` tới `PaymentMethodController@store` — theo đúng pattern axios một-lần đã dùng cho Gmail scan ở `Dashboard.vue` — nhận về bản ghi mới, tự thêm vào danh sách option tại chỗ, không reload trang), Free Trial (toggle), banner Notifications (Edit only). Link "Xem lịch sử" (Edit only) điều hướng sang `subscriptions.show#history` (dùng section Lịch sử đã có ở trang chi tiết, không tạo route/section trùng lặp).
- `Layouts/AuthenticatedLayout.vue` — thêm link "Calendar" vào nav ngang (desktop); nhúng `BottomTabBar` (ẩn `sm:hidden` ở nav ngang khi mobile, hoặc ngược lại — nav ngang `hidden sm:flex`, `BottomTabBar` chỉ render `sm:hidden`); thêm `padding-bottom` cho `<main>` trên mobile để nội dung không bị tab bar che.

---

## 7. Kiểm thử

### 7.1 Unit (Vitest)
- `orbit/calendar.js`:
  - Subscription monthly xuất hiện đúng số lần trong tháng dựa trên `next_renewal_date` gốc (kể cả khi gốc nằm ở tháng khác — lùi/tiến đúng).
  - Subscription yearly chỉ xuất hiện đúng 1 lần trong năm khớp tháng.
  - Subscription `cancelled` bị loại khỏi kết quả.
  - Occurrence trước `started_at` bị loại (chặn dưới).
  - `monthTotals`: tháng ở quá khứ → `upcoming = 0`; tháng tương lai → `upcoming = total`; tháng hiện tại → chỉ tính occurrence `>= today`.
  - Nhiều subscription cùng ngày → `groupByDate` gộp đúng.
- `brandColors.js` / `BrandIcon` mapping: giữ test hiện có; thêm test cho hàm tra `simple-icons` (nếu tách thành hàm thuần) trả về slug hợp lệ hoặc `null` khi không khớp.

### 7.2 Feature (PHPUnit — class style)
- **Migration/model**: tạo subscription mới → tự sinh `SubscriptionEvent` kind=`subscribed` đúng `occurred_at`.
- Sửa `amount` → sinh event `price_changed`; sửa các field khác không sinh event thừa.
- Chuyển `status` sang `cancelled` → sinh event `cancelled`; chuyển `cancelled -> active` không sinh event `subscribed` lần 2.
- `PaymentMethodController@store` — chỉ tạo trong phạm vi user hiện tại; user khác không thấy/dùng được payment method của user A (authorization).
- `SubscriptionController@show` — 403/404 nếu subscription không thuộc user hiện tại (theo policy sẵn có).
- **Lệnh `subscriptions:send-renewal-reminders`**:
  - Gửi mail đúng cho subscription có `next_renewal_date - reminder_days_before = hôm nay`, `status=active`, user đã verify email, `renewal_reminders_enabled=true`.
  - Không gửi nếu đã gửi cho đúng `next_renewal_date` đó rồi (`last_reminder_sent_for` trùng).
  - Không gửi nếu `renewal_reminders_enabled=false` hoặc email chưa verify hoặc `status != active`.
  - Dùng `Notification::fake()` để assert, không gửi mail thật trong test.

---

## 8. Ràng buộc & lưu ý kỹ thuật

- PHPUnit class style (giữ nguyên convention dự án).
- Không phá vỡ route/tên route Breeze hiện có (`profile.edit`, v.v.) — chỉ bổ sung, không đổi tên.
- `simple-icons` chỉ thêm như devDependency/dependency frontend thuần SVG-inline, không kéo icon font hay CDN ngoài.
- Toàn bộ tính toán Calendar chạy client-side từ props đã có — không thêm endpoint gọi lại mỗi lần chuyển tháng.
- Giữ nguyên toàn bộ giao diện tối/tím đã restyle trước đó (`midnight-*`, `violet-*`) — SP4 chỉ thêm cấu trúc/tính năng, không đổi lại bảng màu.
- Lệnh nhắc nhở chạy đồng bộ (không bắt buộc queue) — nếu số lượng user/subscription tăng lớn, cân nhắc `ShouldQueue` sau, không cần trong phạm vi SP4.
- Sau khi migrate, chạy `vendor/bin/pint --dirty --format agent` cho các file PHP thay đổi.

---

## 9. Tiêu chí hoàn thành SP4

- Migration mới chạy sạch (`php artisan migrate`), model/relationship hoạt động đúng.
- Dashboard, Subscriptions list/detail/edit, Calendar, Profile (mục nhắc nhở) hiển thị đúng theo mục 6, dùng icon thương hiệu thật với fallback hợp lệ.
- Bottom tab bar hiện đúng trên mobile, ẩn trên desktop; nav ngang desktop có thêm Calendar.
- Tạo/sửa subscription sinh đúng `subscription_events`; trang chi tiết hiển thị section Lịch sử (gộp billing + price) đúng thứ tự thời gian.
- Lệnh `subscriptions:send-renewal-reminders` chạy đúng logic chống trùng, gửi mail thấy được qua Mailpit ở dev.
- Toàn bộ test mục 7 (Vitest + PHPUnit) pass; suite Vitest hiện có (33 test) không bị vỡ.
