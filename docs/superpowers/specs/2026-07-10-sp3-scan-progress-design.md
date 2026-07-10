# Orbit — Real-time Gmail scan progress (Design Spec)

- **Ngày:** 2026-07-10
- **Dự án:** Orbit — Quản lý dịch vụ đăng ký
- **Loại:** SP3 follow-up (nâng cấp trải nghiệm quét Gmail)
- **Trạng thái:** Đã duyệt thiết kế, chờ review spec
- **Tiền đề:** SP3 (Gmail auto-detection) đã merge. Quét hiện chạy **đồng bộ** trong một request (`GET /gmail/scan`) — chậm và không hiển thị tiến trình.

---

## 1. Mục tiêu

Thay việc quét đồng bộ bằng **job chạy nền + polling**, để hiển thị **thanh phần trăm thật** ("Đang xử lý 12/50 email — 24%") trong lúc quét, thay cho spinner không xác định hiện tại.

## 2. Quyết định (chốt qua brainstorm)

| Chủ đề | Quyết định |
|--------|-----------|
| Cơ chế | **Queued job + polling** (không SSE, không client-batching) |
| Queue driver | **database** (`QUEUE_CONNECTION=database`, bảng `jobs`) — không cần Redis |
| Vận hành | **Bắt buộc chạy `php artisan queue:work`** song song `serve`+`dev`; ghi rõ ở spec/README |
| Trạng thái quét | Bảng `gmail_scans` (per-user): status/total/processed/candidates/error |
| Kết quả | Tái dùng trang Inertia `Gmail/ScanResults.vue`, đọc `candidates` đã lưu |
| Báo tiến trình | `SubscriptionScanner::scan(User, ?callable $onProgress)` — callback optional |
| Nhịp poll | ~1 giây/lần |

## 3. Hạ tầng queue

- `php artisan queue:table` + migrate → bảng `jobs` (và `failed_jobs` nếu chưa có).
- `.env`: `QUEUE_CONNECTION=database` (và `.env.example`).
- **Yêu cầu vận hành:** phải chạy `php artisan queue:work` để job được xử lý. Với `QUEUE_CONNECTION=sync`, job chạy ngay trong request (chặn) → mất ý nghĩa tiến trình; do đó database driver + worker là bắt buộc cho tính năng này.

## 4. Bảng `gmail_scans` (migration mới)

| Cột | Kiểu | Ghi chú |
|-----|------|---------|
| `id` | bigint PK | |
| `user_id` | FK → users, cascade | scope theo user |
| `status` | string | `pending` \| `running` \| `done` \| `failed` (mặc định `pending`) |
| `total` | unsignedInteger, default 0 | số email cần xử lý (biết sau khi list) |
| `processed` | unsignedInteger, default 0 | số email đã xử lý |
| `candidates` | json, nullable | danh sách ứng viên khi `done` |
| `error` | text, nullable | thông báo lỗi khi `failed` |
| `timestamps` | | |

- Model `GmailScan` (hoặc `Scan`): `belongsTo(User)`; casts `candidates`→array. `$fillable` KHÔNG chứa `user_id` (tạo qua `$user->gmailScans()`); helper `progressPercent()` = `total>0 ? round(processed/total*100) : 0`.
- `User::gmailScans(): HasMany`.

## 5. Luồng & endpoint (tất cả `auth`)

1. **`POST /gmail/scans`** (`gmail.scans.store`) — JSON:
   - Nếu chưa kết nối Gmail → 409/redirect JSON gợi ý connect.
   - Tạo `gmailScans` (pending), **dispatch `ScanGmailJob($scan)`**, trả `{ id }`.
2. **`ScanGmailJob`** (queued):
   - `status=running`; `SubscriptionScanner->scan($user, onProgress)`:
     - callback đầu tiên đặt `total`; mỗi email xong tăng `processed` (ghi DB, throttle nhẹ).
   - Xong: lưu `candidates`, `status=done`.
   - Exception: `status=failed`, `error` = thông điệp gọn (không lộ token).
3. **`GET /gmail/scans/{scan}`** (`gmail.scans.show`) — JSON, scope user: `{ status, processed, total, percent }`.
4. **`GET /gmail/scans/{scan}/results`** (`gmail.scans.results`) — Inertia `Gmail/ScanResults` với `candidates` từ row (chỉ khi `done`; nếu chưa xong → về dashboard).

- **Scope:** mọi endpoint dùng `$request->user()->gmailScans()` (route-model-binding qua quan hệ hoặc kiểm `user_id`); user A không xem được scan của B.

## 6. `SubscriptionScanner` — thêm callback tiến trình

- Đổi chữ ký: `scan(User $user, ?callable $onProgress = null): array`.
- Sau khi `listMessageIds`: gọi `$onProgress(0, count($ids))` (đặt total).
- Trong vòng lặp mỗi email: sau khi xử lý xong tăng đếm và gọi `$onProgress($processed, $total)`.
- Callback optional → **các lời gọi cũ (không callback) giữ nguyên hành vi**; logic gom nhóm + dedup không đổi.

## 7. Frontend (Dashboard + popup)

- Nút "Quét Gmail" → `POST /gmail/scans` (axios) → nhận `id` → mở popup **có thanh %**.
- Poll `GET /gmail/scans/{id}` mỗi ~1s:
  - cập nhật thanh: `width = percent%`, chữ "Đang xử lý {processed}/{total} email — {percent}%".
  - `total===0` (đang list) → hiển thị trạng thái "Đang tìm email…".
  - `done` → `router.visit('/gmail/scans/{id}/results')`.
  - `failed` → hiện `error` + nút "Thử lại" (đóng popup) và "Đóng".
- Dừng poll khi rời trang/đóng popup (clear interval).

## 8. Xử lý lỗi

- Job try/catch toàn bộ → `failed` + `error` ("Không đọc được Gmail, thử lại sau" cho lỗi mạng/401; gợi ý kết nối lại nếu token hỏng). Không ghi token/nội dung nhạy cảm vào `error`.
- Frontend hiển thị lỗi thân thiện, không kỹ thuật.

## 9. Kiểm thử (TDD, PHPUnit class style)

- **Store:** `POST /gmail/scans` khi đã kết nối → tạo 1 row `pending` của user + dispatch `ScanGmailJob` (`Queue::fake`/`Bus::fake`); chưa kết nối → không tạo, trả tín hiệu connect; guest → login.
- **Job:** với `Http::fake` Gmail, chạy `ScanGmailJob` → row `done`, `processed == total`, `candidates` khớp (VD 1 receipt Netflix → 1 candidate `create`). Lỗi Gmail (`Http::fake` 401) → `failed` + `error` set.
- **Progress:** callback tăng `processed` đúng số email (kiểm qua job hoặc test scanner với callback đếm).
- **Show:** `GET /gmail/scans/{scan}` trả `status/processed/total/percent`; user A **không** đọc được scan của B (404).
- **Results:** `GET .../results` khi `done` render `Gmail/ScanResults` với candidates; khi chưa `done` → redirect dashboard; scope user.

## 10. Ngoài phạm vi

- Quét ngầm **định kỳ** (SP4 — hạ tầng job này tái dùng được).
- Retry tự động, SSE/websocket, prune scan cũ.
- Hủy scan giữa chừng.

## 11. Tiêu chí hoàn thành

- Bấm "Quét Gmail" → popup thanh % tăng thật theo số email xử lý.
- Quét chạy ở worker (`queue:work`), không chặn UI.
- Xong → hiển thị trang kết quả (candidates) như hiện tại; lỗi → thông báo thân thiện.
- Dữ liệu scope theo user.
- Toàn bộ test mục 9 pass; suite cũ vẫn xanh.
