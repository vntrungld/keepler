# Orbit — Sub-project 2: UI Vũ trụ hành tinh (Design Spec)

- **Ngày:** 2026-07-10
- **Dự án:** Orbit — Quản lý dịch vụ đăng ký hàng tháng
- **Sub-project:** SP2 — UI Vũ trụ hành tinh
- **Trạng thái:** Đã duyệt thiết kế, chờ review spec
- **Tiền đề:** SP1 (Nền tảng & Đăng nhập) đã hoàn thành & merge — có model `Subscription`, CRUD theo user, seeder dữ liệu mẫu.

---

## 1. Bối cảnh & Mục tiêu

SP1 đã dựng nền tảng: đăng nhập Google, model `Subscription`, CRUD tối thiểu dạng bảng, seeder dữ liệu mẫu. SP2 hiện thực **giá trị hình ảnh cốt lõi** của Orbit: một màn hình "hệ mặt trời" nơi người dùng là mặt trời ở trung tâm và mỗi dịch vụ đăng ký là một hành tinh quay quanh quỹ đạo.

Mục tiêu SP2: từ dữ liệu subscription sẵn có, vẽ ra vũ trụ tương tác đẹp mắt giúp người dùng **nhìn phát là hiểu** mình đang đăng ký những gì, tốn bao nhiêu, cái nào sắp tới hạn.

---

## 2. Phạm vi SP2

### Trong phạm vi
- Trang vũ trụ SVG tại `/dashboard` (thay màn hình dashboard trống hiện tại).
- Vẽ 2 quỹ đạo (tháng/năm), hành tinh chia đều góc, trôi xoay nhẹ liên tục.
- Mã hóa dữ liệu lên hành tinh: kích thước (số tiền), màu (thương hiệu), viền (trạng thái), vầng sáng (độ khẩn).
- Tương tác: hover tooltip, click mở panel chi tiết với nút Sửa/Xóa (dẫn tới CRUD sẵn có).
- Empty state khi chưa có dịch vụ.
- Tách toán học layout + catalog màu ra module JS thuần để unit test (Vitest).
- `DashboardController` cấp dữ liệu subscription scope theo user; Feature test PHPUnit.

### Ngoài phạm vi (để dành sub-project sau)
- Thêm/sửa dữ liệu **trực tiếp trên vũ trụ** (SP2 dùng lại form CRUD của SP1).
- Kéo-thả, sắp xếp thủ công hành tinh.
- Quét Gmail / tự động phát hiện (SP3).
- Nhắc nhở, hành động hủy/giữ nâng cao (SP4).
- Gọi API tỉ giá động.

---

## 3. Quyết định thiết kế (chốt qua brainstorm)

| Chủ đề | Quyết định |
|--------|-----------|
| Công nghệ vẽ | **SVG** co giãn theo `viewBox` (không Canvas/CSS thuần) |
| Vị trí | Thay **`/dashboard`**; bảng `Subscriptions/Index` giữ làm nơi quản lý |
| Chuyển động | **Lai**: hành tinh chia đều góc + cả cụm trôi xoay nhẹ liên tục |
| Vị trí góc | **Chia đều** (N hành tinh → cách nhau 360/N độ); **KHÔNG** mã hóa thời gian vào góc → không bao giờ chồng nhau |
| Trạng thái hiển thị | Chỉ `active` + `pending_cancel`; **`cancelled` biến mất** khỏi vũ trụ |
| Kích thước | `amount_vnd` (số tiền mỗi lần trừ, thô — không quy về tháng), map min–max px, kẹp trần |
| Màu thân | **Màu thương hiệu** (catalog tên→màu + hash fallback cho dịch vụ lạ) |
| Nhận diện | Thêm **chữ cái đầu** của tên ở giữa hành tinh |
| Kênh trạng thái | `active` = màu đầy đủ; `pending_cancel` = **viền nét đứt hổ phách** |
| Độ khẩn | `next_renewal_date` ≤ **7 ngày** → **vầng sáng nhấp nháy** quanh hành tinh |
| Tương tác | Hover → tooltip; Click → panel chi tiết + Sửa/Xóa (dùng CRUD SP1) |

---

## 4. Ánh xạ hình ảnh chi tiết

### 4.1 Mặt trời (trung tâm)
- Avatar Google của người dùng (`user.avatar`) đặt trong một vòng tròn phát sáng ở tâm.
- Nếu không có avatar → vòng tròn gradient + chữ cái đầu của tên user.

### 4.2 Quỹ đạo
- **Đúng 2 quỹ đạo** vì `billing_cycle` chỉ có `monthly` | `yearly`:
  - `monthly` → vòng **trong**, bán kính nhỏ, tốc độ trôi **nhanh** hơn.
  - `yearly` → vòng **ngoài**, bán kính lớn, tốc độ trôi **chậm** hơn.
- Vẽ mỗi quỹ đạo là một đường tròn mảnh mờ để thấy "đường ray".
- Quỹ đạo không có hành tinh nào thì vẫn có thể vẽ mờ hoặc ẩn (chi tiết để plan quyết, mặc định: ẩn nếu rỗng).

### 4.3 Vị trí hành tinh trên quỹ đạo
- Với `N` hành tinh trên một quỹ đạo, hành tinh thứ `i` (0-indexed) đặt tại góc:
  `angle_i = rotationOffset + i * (360 / N)` độ.
- `rotationOffset` tăng dần theo thời gian để tạo hiệu ứng trôi (mỗi quỹ đạo một tốc độ). Vòng trong (tháng) quay nhanh hơn vòng ngoài (năm).
- Thứ tự hành tinh trên quỹ đạo ổn định (VD sắp theo `id`) để không nhảy lung tung giữa các lần render.

### 4.4 Kích thước hành tinh
- Bán kính px của hành tinh suy từ `amount_vnd`, map vào `[R_MIN, R_MAX]`.
- Dùng thang **căn bậc hai** (theo diện tích cảm nhận) và **kẹp** ở hai đầu để hành tinh rẻ không quá nhỏ, đắt không quá to.
- Thang tương đối theo tập subscription đang hiển thị của user (min/max trong tập hiện tại) để luôn có độ tương phản hợp lý; nếu chỉ có 1 hành tinh → dùng kích thước giữa.

### 4.5 Màu & nhận diện
- **Catalog thương hiệu** `brandColors`: map **từ khóa trong tên** (chuẩn hóa lowercase, bỏ dấu/khoảng trắng) → mã màu. Ví dụ khởi tạo:
  - netflix → `#E50914`, spotify → `#1DB954`, youtube → `#FF0000`,
    chatgpt/openai → `#10A37F`, adobe → `#FF0000`, apple → `#555555`,
    google → `#4285F4`, amazon/prime → `#FF9900`, disney → `#113CCF`.
- **Fallback:** dịch vụ không khớp catalog → sinh màu **ổn định từ tên** (hash tên → hue HSL) để mỗi dịch vụ luôn cùng một màu qua các lần load.
- **Chữ cái đầu** của `name` (viết hoa) đặt ở giữa hành tinh, màu chữ tương phản với nền (trắng/đen tùy độ sáng màu nền).

### 4.6 Trạng thái
- `active` → hành tinh màu thương hiệu đầy đủ, không viền đặc biệt.
- `pending_cancel` → giữ màu thương hiệu + **viền nét đứt màu hổ phách** (amber) bao quanh.
- `cancelled` → **không vẽ** (loại khỏi dữ liệu truyền lên vũ trụ).

### 4.7 Độ khẩn (sắp tới hạn)
- Tính `daysUntilRenewal = ceil((next_renewal_date - hôm nay) / 1 ngày)`.
- Nếu `0 ≤ daysUntilRenewal ≤ 7` → hành tinh có **vầng sáng (glow) nhấp nháy** (pulse) bao ngoài, khác biệt rõ với viền nét đứt của trạng thái.
- Quá hạn (`< 0`) cũng coi là khẩn (vẫn glow) — dịch vụ đã hoặc sắp bị trừ.

---

## 5. Tương tác

- **Hover hành tinh** → **tooltip** ngắn gọn: tên dịch vụ, số tiền gốc (`amount` + `currency`), quy đổi VND (`amount_vnd`), ngày tới hạn + "còn N ngày".
- **Click hành tinh** → **panel chi tiết** trượt ra một bên (không rời màn hình vũ trụ): đầy đủ thông tin + trạng thái + ghi chú, kèm nút **Sửa** (→ `subscriptions.edit`) và **Xóa** (→ `subscriptions.destroy`, có xác nhận). Dùng lại luồng CRUD của SP1.
- **Empty state:** khi user chưa có subscription nào (hoặc tất cả đều `cancelled`) → hiển thị mặt trời đơn độc + lời mời "Thêm dịch vụ đầu tiên" (→ `subscriptions.create`).
- **Điều hướng:** có lối rõ ràng qua lại giữa vũ trụ (`/dashboard`) và bảng quản lý (`/subscriptions`).

---

## 6. Kiến trúc code

### 6.1 Backend
- **`DashboardController@index`** (route `/dashboard`, middleware `auth` + `verified`):
  - Trả về `Inertia::render('Dashboard', ['subscriptions' => ...])`.
  - Chỉ lấy subscription **của user hiện tại** (`$request->user()->subscriptions()`), **loại `cancelled`**.
  - Mỗi bản ghi cấp các field cần cho vẽ: `id, name, amount, currency, amount_vnd, billing_cycle, next_renewal_date, status`.
  - `days_until_renewal` có thể tính ở client từ `next_renewal_date` (đơn giản, không cần trả thêm) — plan chốt.

### 6.2 Frontend — module thuần (test được bằng Vitest)
- **`resources/js/orbit/layout.js`** — hàm thuần, không phụ thuộc DOM:
  - `distributeAngles(count, rotationOffset)` → mảng góc chia đều.
  - `planetRadius(amountVnd, minVnd, maxVnd)` → bán kính px (thang sqrt, kẹp `[R_MIN,R_MAX]`).
  - `polarToXy(cx, cy, orbitRadius, angleDeg)` → tọa độ tâm hành tinh.
  - `daysUntil(renewalDateISO, todayISO)` → số ngày (nhận `today` như tham số để test tất định).
  - `isUrgent(days)` → boolean (`-∞ < days ≤ 7`).
- **`resources/js/orbit/brandColors.js`** — hàm thuần:
  - `brandColor(name)` → mã màu (tra catalog, fallback hash→HSL).
  - `initial(name)` → chữ cái đầu viết hoa.
  - `contrastText(hexOrHsl)` → `'#fff'` | `'#000'`.

### 6.3 Frontend — component Vue
- **`Orbit.vue`** — khung SVG tổng: mặt trời, 2 quỹ đạo, vòng lặp animation `rotationOffset` (requestAnimationFrame hoặc CSS), phân nhóm hành tinh theo `billing_cycle`, quản lý trạng thái hover/selected. Nhận `subscriptions` qua props.
- **`Planet.vue`** — một hành tinh: vòng tròn (màu, kích thước), chữ cái đầu, viền trạng thái, glow khẩn; phát sự kiện hover/click.
- **`PlanetTooltip.vue`** — tooltip nổi khi hover.
- **`OrbitDetailPanel.vue`** — panel chi tiết khi click, kèm nút Sửa/Xóa.
- **`Dashboard.vue`** — trang, nhận props `subscriptions` từ Inertia, đặt `Orbit.vue` bên trong `AuthenticatedLayout`, xử lý empty state + link tới bảng quản lý.

---

## 7. Kiểm thử

### 7.1 Unit (Vitest — thêm mới vào dự án)
- `layout.js`:
  - `distributeAngles`: 1→[offset]; 2→cách 180°; 3→cách 120°; 4→cách 90°.
  - `planetRadius`: đơn điệu tăng theo tiền; kẹp đúng `R_MIN`/`R_MAX`; trường hợp min==max (một hành tinh) → giá trị giữa hợp lệ.
  - `daysUntil`: tính đúng với `today` cố định; qua mốc tháng/năm; số âm khi quá hạn.
  - `isUrgent`: đúng biên tại 0, 7, 8, và số âm.
- `brandColors.js`:
  - `brandColor`: netflix→`#E50914` (khớp catalog, không phân biệt hoa/thường); tên lạ → màu ổn định (gọi 2 lần cùng kết quả) và hợp lệ.
  - `initial`, `contrastText` đúng.

### 7.2 Feature (PHPUnit — **class style**, KHÔNG Pest)
- `Dashboard` route:
  - Khách chưa đăng nhập → redirect `login`.
  - User đăng nhập chỉ nhận **subscription của mình**, **không** thấy của user khác.
  - Subscription `cancelled` **không** nằm trong props truyền cho vũ trụ.
  - Props chứa đúng các field cần thiết cho mỗi subscription.

---

## 8. Ràng buộc & lưu ý kỹ thuật

- **PHPUnit class style** (dự án dùng PHPUnit, KHÔNG Pest — như SP1).
- Không đụng model/migration/CRUD của SP1 trừ khi thật cần; SP2 chủ yếu là đọc + hiển thị.
- Thêm Vitest + cấu hình tối thiểu; không kéo theo phụ thuộc thừa.
- Animation phải nhẹ (ưu tiên transform/GPU), không gây nghẽn với vài chục hành tinh; tôn trọng `prefers-reduced-motion` (giảm/tắt trôi khi user chọn).
- Giữ file component tập trung một trách nhiệm; toán học nằm ở module thuần, không nhét vào `.vue`.

---

## 9. Tiêu chí hoàn thành SP2

- `/dashboard` hiển thị vũ trụ SVG: mặt trời = avatar user, 2 quỹ đạo, hành tinh chia đều & trôi nhẹ.
- Hành tinh mã hóa đúng: kích thước theo tiền, màu theo thương hiệu, chữ cái đầu, viền `pending_cancel`, glow khi ≤7 ngày; `cancelled` không hiện.
- Hover ra tooltip; click mở panel chi tiết với nút Sửa/Xóa hoạt động (qua CRUD SP1).
- Empty state hiển thị khi chưa có dịch vụ.
- Dữ liệu scope đúng theo user.
- Toàn bộ test mục 7 (Vitest + PHPUnit) pass.
