# BE 13 — Chunked upload và FIFO deploy

🎯 **Mục tiêu:** nhận artifact lớn qua nhiều request và xử lý nhiều deploy cùng project theo thứ tự mà không
chạy hai pipeline chồng lên cùng thư mục release.

## 1. Chunked upload lifecycle

Contract API:

```text
POST /projects/{slug}/artifacts/uploads
PUT  /projects/{slug}/artifacts/uploads/{uploadId}/chunks/{index}   (raw bytes)
POST /projects/{slug}/artifacts/uploads/{uploadId}/complete         (sha256 + version)
```

Session ID ngẫu nhiên, chunk index được validate trước khi tạo filename, các chunk hiện được ghép theo thứ tự
số học. Service hiện không nhận manifest số chunk kỳ vọng, vì vậy khi tự xây lại cần định nghĩa rõ cách phát
hiện chunk thiếu/trùng. Khi complete, assembled artifact vẫn phải qua cùng storage/hash validation như upload
đơn. Housekeeping xóa session bỏ dở đã quá tuổi bảo vệ để không chạm upload đang chạy.

**Lưu ý implementation hiện tại:** `putChunk()` chưa giới hạn bytes từng chunk hoặc tổng bytes của session;
max artifact được áp dụng ở storage sau khi ghép. Khi làm lại tính năng, kiểm tra quota trước khi ghi mỗi chunk
và đặt giới hạn số chunk/session. Xử lý lỗi I/O tường minh; không trả accepted nếu assembled hash sai.

## 2. Backlog per-project

Các Deployment queued trong DB chính là backlog. `DeployDispatcher::claimNextFor(Project)`:

1. lấy cache lock ngắn theo project;
2. kiểm tra project chưa có deployment active;
3. chọn deployment queued cũ nhất;
4. conditional update `queued → running` như compare-and-set;
5. dispatch job sau khi thắng claim.

Project khác nhau độc lập; giới hạn worker cPanel vẫn có thể tuần tự xử lý chúng. Rollback/activate chặn
deployment đang active nhưng backlog chỉ queued không nhất thiết ngăn thao tác.

## 3. Multi-worker và recovery

Per-project claim lock cộng conditional update chống hai dispatcher cùng nhận một deployment. Job có guard
terminal status để delivery lặp không chạy pipeline lần nữa. Cấu hình queue `retry_after` phải lớn hơn job
timeout; nếu nhỏ hơn, worker khác có thể reserve lại job khi job đầu còn chạy.

Housekeeping đánh dấu deployment active quá timeout là failed, nhả deploy lock rồi gọi dispatcher để drain
backlog. Không reaper queued theo tuổi đơn thuần vì deployment có thể đang chờ hợp lệ.

## 4. Test

```bash
php artisan test --filter=ChunkedUploadTest
php artisan test --filter=DeployBacklogTest
```

Bao phủ chunk order, hash mismatch, project đang bận, FIFO order, hai claimer và phục hồi backlog sau stale
deployment.

👉 Tiếp theo: [14 — Scheduler, housekeeping và retention](14-scheduler-housekeeping.md).
