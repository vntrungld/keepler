# Orbit — Sub-project 1: Nền tảng & Đăng nhập (Design Spec)

- **Ngày:** 2026-07-09
- **Dự án:** Orbit — Quản lý dịch vụ đăng ký hàng tháng
- **Sub-project:** SP1 — Nền tảng & Đăng nhập
- **Trạng thái:** Đã duyệt thiết kế, chờ review spec

---

## 1. Bối cảnh & Tầm nhìn sản phẩm

**Orbit** là sản phẩm web nhiều người dùng giúp người dùng quản lý các dịch vụ đăng ký định kỳ (Netflix, Spotify, ChatGPT, Adobe...), để dễ dàng quyết định **hủy hoặc tiếp tục**.

Điểm khác biệt là giao diện lấy cảm hứng từ **hệ mặt trời**: người dùng là mặt trời ở trung tâm, mỗi dịch vụ đăng ký là một hành tinh quay quanh quỹ đạo.

### Ánh xạ hình ảnh (định hướng chung cho SP2)
- **Mặt trời (trung tâm)** = người dùng.
- **Mốc cố định trên màn hình** (ví dụ vị trí 12 giờ) = thời điểm bị trừ tiền. Một vòng quay = một chu kỳ thanh toán; vị trí góc của hành tinh cho biết còn bao lâu tới hạn. Hành tinh càng gần mốc = càng sắp thanh toán.
- **Bán kính quỹ đạo** = chu kỳ: quỹ đạo nhỏ + quay nhanh cho gói tháng, quỹ đạo lớn + quay chậm cho gói năm.
- **Kích thước hành tinh** = số tiền/tháng (quy đổi VND).
- **Màu sắc / vòng nhẫn** = trạng thái (hoạt động / sắp hủy / đã hủy).

## 2. Phân rã toàn dự án

Dự án được chia thành 4 sub-project, mỗi cái có spec + plan + implement riêng:

1. **SP1 — Nền tảng & Đăng nhập** *(spec này)*: scaffold Laravel + Vue/Inertia, Sign in with Google, model User & Subscription, CRUD tối thiểu.
2. **SP2 — UI Vũ trụ hành tinh**: visualization Vue theo ánh xạ hình ảnh ở trên.
3. **SP3 — Thêm dịch vụ đăng ký**: (a) tự động — quét Gmail rule-based; (b) thủ công — form nhập liệu đầy đủ. Cả hai là tính năng chính thức lâu dài.
4. **SP4 — Quản lý & Nhắc nhở**: hành động hủy/giữ, nhắc trước ngày gia hạn, lưu link hủy.

**Thứ tự:** SP1 → SP2 → SP3 → SP4. Lý do: dựng nền tảng + UI hành tinh trước (dùng dữ liệu nhập tay/seed) để thấy giá trị hình ảnh sớm; phần Gmail nặng về OAuth verification nên để sau.

---

## 3. Phạm vi SP1

### Trong phạm vi
- Scaffold ứng dụng Laravel + Vue 3 + Inertia.js + Vite + Tailwind CSS.
- "Sign in with Google" qua Laravel Socialite (chỉ scope `email`, `profile`).
- Model & migration cho `users` (bổ sung Google) và `subscriptions`.
- CRUD subscription tối thiểu (bảng đơn giản + form Inertia), phân quyền theo `user_id`.
- Quy đổi tiền tệ tĩnh (config) sang VND.
- Seeder dữ liệu mẫu để SP2 vẽ được ngay.
- Test theo TDD.

### Ngoài phạm vi (để dành sub-project sau)
- Quét/đọc Gmail và lưu Gmail token (SP3).
- UI vũ trụ hành tinh (SP2).
- Nhập liệu thủ công hoàn chỉnh với đầy đủ trải nghiệm (SP3).
- Nhắc nhở, hành động hủy/giữ (SP4).
- Gọi API tỉ giá động (nâng cấp sau).

---

## 4. Kiến trúc & Stack

- **Backend:** Laravel (bản mới nhất), Laravel Socialite.
- **Frontend:** Vue 3 + Inertia.js, Vite, Tailwind CSS.
- **Database:** SQLite cho môi trường dev.
- **Thư mục dự án:** `/home/trungld/work/trungld/orbit`.

Nguyên tắc: SP1 chỉ dựng khung + đăng nhập + model + CRUD tối thiểu. Không đụng Gmail, không dựng UI hành tinh.

---

## 5. Mô hình dữ liệu

### Bảng `users` (chuẩn Laravel + bổ sung Google)
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `name` | string | |
| `email` | string, unique | |
| `avatar` | string, nullable | URL ảnh Google |
| `google_id` | string, nullable, index | định danh Google, dùng để tránh tạo trùng |
| `timestamps` | | |

- *Không* lưu Gmail access token ở SP1 (để dành SP3).
- Cột `password` của Laravel giữ nullable (đăng nhập chỉ qua Google).

### Bảng `subscriptions`
| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `user_id` | bigint FK → users | |
| `name` | string | tên dịch vụ, VD "Netflix" |
| `amount` | decimal | số tiền gốc, VD 260000 hoặc 9.99 |
| `currency` | string(3) | mã tiền gốc, VD "VND", "USD" |
| `amount_vnd` | decimal | số tiền quy đổi ra VND (tính khi lưu) |
| `billing_cycle` | enum | `monthly` \| `yearly` |
| `next_renewal_date` | date | ngày gia hạn kế tiếp (quyết định góc quay hành tinh) |
| `status` | enum | `active` \| `pending_cancel` \| `cancelled` |
| `cancel_url` | string, nullable | link hủy dịch vụ (dùng ở SP4) |
| `notes` | text, nullable | ghi chú |
| `timestamps` | | |

### Tỉ giá tiền tệ
- File config tĩnh `config/currency.php`, ví dụ: `['USD' => 26000, 'VND' => 1]`.
- Helper/service quy đổi `amount (currency) → amount_vnd` được gọi khi tạo/sửa subscription.
- Nếu gặp `currency` chưa cấu hình: coi như lỗi validation (từ chối) để tránh dữ liệu sai lệch.

---

## 6. Luồng đăng nhập Google (Socialite)

1. Trang landing có nút "Đăng nhập với Google".
2. `GET /auth/google/redirect` → chuyển hướng tới Google.
3. `GET /auth/google/callback`:
   - Lấy thông tin user từ Google.
   - Tìm user theo `google_id`; nếu chưa có thì tạo mới (lưu `name`, `email`, `avatar`, `google_id`).
   - Nếu đã có → cập nhật thông tin cơ bản, không tạo trùng.
   - Đăng nhập và chuyển về dashboard.
4. Scope chỉ `email`, `profile` — chưa xin quyền Gmail.

---

## 7. CRUD subscription tối thiểu

- `GET /subscriptions` — danh sách dạng bảng đơn giản (chưa phải UI hành tinh).
- Tạo / Sửa / Xóa qua form Inertia cơ bản.
- Middleware `auth` cho toàn bộ route subscription.
- Policy/scope theo `user_id`: mỗi user chỉ xem/sửa/xóa subscription của mình.
- `amount_vnd` được tính tự động từ `amount` + `currency`, không cho nhập tay.
- Seeder tạo vài subscription mẫu (Netflix, Spotify, ChatGPT...) với chu kỳ & tiền tệ đa dạng để SP2 có dữ liệu vẽ.

---

## 8. Kiểm thử (TDD)

- **Feature — Đăng nhập Google (mock Socialite):**
  - Callback tạo user mới đúng thông tin khi `google_id` chưa tồn tại.
  - Callback với `google_id` đã tồn tại: đăng nhập lại, không tạo user trùng.
- **Feature — CRUD subscription:**
  - Tạo subscription tính đúng `amount_vnd` theo tỉ giá config.
  - User A không xem/sửa/xóa được subscription của user B (trả 403/404).
- **Unit — Quy đổi tiền tệ:**
  - USD → VND đúng theo config.
  - Currency chưa cấu hình → báo lỗi.

---

## 9. Tiêu chí hoàn thành SP1

- Đăng nhập bằng Google chạy được, tạo/không-trùng user đúng.
- Tạo/sửa/xóa subscription hoạt động, phân quyền theo user.
- `amount_vnd` được quy đổi chính xác.
- Có seeder dữ liệu mẫu.
- Toàn bộ test ở mục 8 pass.
