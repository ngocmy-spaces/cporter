# BE 03 — Domain model và migrations

🎯 **Mục tiêu:** thiết kế schema cho vòng đời project → artifact → release → deployment; hiểu vì sao migration
phải tiến hóa tuần tự và vì sao trạng thái nên được biểu diễn bằng enum.

## 1. Mô hình quan hệ

```text
Project 1 ── * Deployment * ── 0..1 Release * ── 0..1 Artifact
   │                                  │
   ├── status / health / hooks        └── release directory + state
   └── base_path + shared paths

ApiKey       token CI (hash + scope + project binding)
AuditLog     lịch sử hành động
Setting      probe/heartbeat của hệ thống
User         người dùng session admin/viewer
```

Artifact là ZIP đã upload; Release là một phiên bản đã/đang được giải nén; Deployment là một lần chạy pipeline.
Tách ba khái niệm giúp giữ lịch sử ngay cả khi ZIP được dọn sau deploy thành công.

## 2. Migration tạo schema

Tạo migration bằng Artisan:

```bash
php artisan make:migration create_projects_table
```

Mẫu bảng tối giản để hiểu foreign key và index:

```php
Schema::create('artifacts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained()->cascadeOnDelete();
    $table->string('filename');
    $table->unsignedBigInteger('size')->default(0);
    $table->string('sha256', 64)->nullable();
    $table->string('storage_path')->nullable();
    $table->string('status')->default('pending');
    $table->timestamps();
    $table->index(['project_id', 'sha256']);
});
```

Đây là mẫu học tập; hãy so với migration hiện hành trước khi copy, vì schema đã có các migration bổ sung.
Ví dụ trạng thái được lưu string thay vì MySQL enum để app quản lý vocabulary bằng PHP enum.

## 3. Tạo thêm migration, không sửa lịch sử

Khi thêm trường mới sau khi migration đã được phát hành, tạo migration mới:

```bash
php artisan make:migration add_health_status_to_projects_table
php artisan migrate
php artisan migrate:status
```

Migration cần có `up()` và `down()` đối xứng. Tránh sửa migration cũ đã chạy ở môi trường khác: máy mới và
production sẽ có lịch sử schema khác nhau.

## 4. Eloquent model, quan hệ và cast

Quan hệ đi theo khóa ngoại:

```php
// Mẫu rút gọn trong Project
public function deployments(): HasMany
{
    return $this->hasMany(Deployment::class);
}
```

Trạng thái dùng backed enum:

```php
enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case HooksPending = 'hooks_pending';
    case Success = 'success';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
}
```

Cast trường Eloquent sang enum để không rải string rời rạc:

```php
protected function casts(): array
{
    return ['status' => DeploymentStatus::class];
}
```

Implementation có thể dùng `casts()` thay vì `$casts`; hãy dùng đúng pattern model trong repo.

## 5. Migrations hiện có

Thứ tự tạo bảng nằm trong `apps/api/database/migrations`. Đọc lần lượt:

1. bảng Laravel nền tảng (users, cache, jobs);
2. bảng cPorter gốc: projects, artifacts, releases, deployments, api_keys, audit_logs, settings;
3. migration bổ sung role, disk usage, soft delete, env vars, health và rollback triggers.

Đây là lý do schema trên máy mới được dựng bằng cách replay toàn bộ migration thay vì chỉ nhìn migration
đầu tiên của mỗi bảng.

### 5.1. Từ điển bảng nghiệp vụ

Các bảng dưới đây là schema sau khi chạy **toàn bộ** migrations. Một số cột được thêm ở migration về sau,
nên không nhất thiết xuất hiện trong migration tạo bảng ban đầu.

#### `projects` — cấu hình project được quản lý

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `name` | Tên hiển thị. |
| `slug` | Định danh duy nhất, đồng thời dùng làm route key trong API. |
| `base_path` | Đường dẫn tuyệt đối đến thư mục project trên máy chủ. |
| `type` | Loại ứng dụng (`static`, `laravel`, `php`, `node`, `wordpress`), quyết định cách deploy. |
| `docroot_subpath` | Thư mục con làm document root, ví dụ `public` cho Laravel. |
| `keep_releases` | Số release cần giữ để có thể rollback. |
| `auto_rollback_on` | Danh sách lỗi được phép kích hoạt rollback tự động; rỗng nghĩa là tắt. |
| `disk_usage` | Dung lượng hiện tại của release đang chạy và thư mục shared, tính bằng byte. |
| `releases_disk_usage` | Dung lượng các release được giữ lại, tính bằng byte. |
| `disk_usage_status` | Trạng thái tính lại dung lượng. |
| `disk_usage_started_at` | Thời điểm bắt đầu tính lại dung lượng. |
| `disk_usage_calculated_at` | Thời điểm tính dung lượng gần nhất hoàn tất. |
| `shared_disk_usage` | Dung lượng theo từng đường dẫn shared, dạng JSON. |
| `health_check_url` | URL dùng để kiểm tra sức khỏe ứng dụng. |
| `health_status` | Tình trạng gần nhất: `healthy`, `unhealthy`, `unknown` hoặc `paused`. |
| `health_checked_at` | Thời điểm kiểm tra sức khỏe gần nhất. |
| `health_last_ok_at` | Thời điểm gần nhất kiểm tra thành công. |
| `shared_paths` | Danh sách file/thư mục dùng chung giữa các release, ví dụ `.env` và `storage`. |
| `hooks` | Các lệnh chạy ở giai đoạn `pre_activate` hoặc `post_activate`. |
| `env_vars` | Biến môi trường được quản lý; giá trị được mã hóa trước khi lưu. |
| `status` | Trạng thái project: `active`, `disabled` hoặc `deleting`. |
| `created_at`, `updated_at` | Thời điểm tạo và cập nhật. |
| `deleted_at` | Thời điểm xóa mềm; project đã xóa mềm được ẩn khỏi truy vấn mặc định. |

#### `artifacts` — gói build được tải lên

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `project_id` | Project sở hữu artifact; xóa project sẽ xóa artifact liên quan. |
| `filename` | Tên file upload. |
| `size` | Kích thước file, tính bằng byte. |
| `sha256` | Hash SHA-256 để kiểm tra tính toàn vẹn; có index cùng `project_id`. |
| `storage_path` | Đường dẫn file trên máy chủ; có thể được đặt về `null` sau khi file được dọn. |
| `status` | Trạng thái upload/xác minh: `pending`, `verified` hoặc `failed`. |
| `uploaded_at` | Thời điểm upload. |
| `pruned_at` | Thời điểm file ZIP được dọn; bản ghi vẫn giữ lại để báo cáo. |
| `created_at`, `updated_at` | Thời điểm tạo và cập nhật. |

#### `releases` — các phiên bản đã được chuẩn bị để kích hoạt

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `project_id` | Project mà release thuộc về. |
| `artifact_id` | Artifact dùng để tạo release; thành `null` nếu artifact bị xóa. |
| `version` | Nhãn phiên bản, thường là ref, tag hoặc commit SHA rút gọn. |
| `path` | Đường dẫn thư mục release trên máy chủ. |
| `state` | Trạng thái: `pending`, `extracting`, `ready`, `active`, `superseded`, `failed` hoặc `pruned`. |
| `created_by` | User tạo deploy; thành `null` nếu user bị xóa hoặc không có user liên kết. |
| `activated_at` | Thời điểm release được kích hoạt, kể cả khi được kích hoạt lại khi rollback. |
| `created_at`, `updated_at` | Thời điểm tạo và cập nhật. |

#### `deployments` — mỗi lần chạy deploy hoặc rollback

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `project_id` | Project được deploy; có index cùng `status`. |
| `release_id` | Release mà lần chạy liên quan; có thể `null` nếu release bị xóa. |
| `trigger` | Nguồn chạy: `api`, `manual`, `cron` hoặc `webhook`. |
| `status` | Trạng thái: `queued`, `running`, `hooks_pending`, `success`, `failed` hoặc `rolled_back`. |
| `steps` | Nhật ký các bước pipeline, lưu dạng JSON. |
| `actor` | Danh tính khởi chạy, thường là email user, tên API key hoặc `cron`. |
| `idempotency_key` | Khóa chống tạo deploy trùng khi client gửi lại request; duy nhất trong phạm vi project khi có giá trị. |
| `started_at`, `finished_at` | Thời điểm bắt đầu và kết thúc xử lý. |
| `created_at`, `updated_at` | Thời điểm tạo và cập nhật. |

#### `api_keys` — token cho CI và client API

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `name` | Tên dễ nhận biết của key. |
| `prefix` | Phần đầu không bí mật của token, dùng tra cứu nhanh. |
| `hash` | SHA-256 của token đầy đủ để xác minh; duy nhất và không thể dùng để khôi phục token. |
| `scopes` | Quyền được cấp: `read`, `deploy`, `rollback`, `admin`. |
| `project_id` | Project bị giới hạn bởi key; `null` nghĩa là không gắn với một project cụ thể. |
| `last_used_at` | Thời điểm key được sử dụng gần nhất. |
| `expires_at` | Thời điểm hết hạn; `null` nghĩa là không đặt hạn. |
| `revoked_at` | Thời điểm thu hồi; `null` nghĩa là chưa thu hồi. |
| `created_at`, `updated_at` | Thời điểm tạo và cập nhật. |

API key **không có `user_id`**: nó gắn tùy chọn với project, còn danh tính thao tác được ghi ở `actor` trong audit/deployment.

#### `audit_logs` — nhật ký kiểm toán chỉ ghi thêm

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `actor` | Người hoặc thành phần thực hiện, ví dụ email, tên API key hoặc `system`. |
| `action` | Loại hành động, ví dụ `project.updated` hoặc `apikey.revoked`; có index. |
| `subject_type`, `subject_id` | Loại và ID đối tượng chịu tác động, tạo thành quan hệ polymorphic; có thể để trống. |
| `meta` | Thông tin bổ sung dạng JSON. |
| `ip` | Địa chỉ IP của request, có thể để trống. |
| `created_at` | Thời điểm ghi log. Bảng không có `updated_at` vì log là append-only. |

#### `settings` — cấu hình runtime dạng key/value

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `key` | Tên thiết lập, duy nhất. |
| `value` | Giá trị JSON, ví dụ dữ liệu probe capability hoặc heartbeat. |
| `created_at`, `updated_at` | Thời điểm tạo và cập nhật. |

#### `users` — tài khoản đăng nhập

| Cột | Tác dụng |
|---|---|
| `id` | Khóa chính. |
| `name` | Tên người dùng. |
| `email` | Email đăng nhập, duy nhất. |
| `role` | Vai trò (`admin` hoặc `viewer`); migration bổ sung sau, mặc định `admin`. |
| `email_verified_at` | Thời điểm xác nhận email, có thể trống. |
| `password` | Mật khẩu đã hash. |
| `remember_token` | Token hỗ trợ tính năng ghi nhớ đăng nhập. |
| `created_at`, `updated_at` | Thời điểm tạo và cập nhật. |

### 5.2. Bảng Laravel và bảng vận hành

Các bảng này phục vụ xác thực, cache và queue; việc sử dụng cụ thể phụ thuộc cấu hình Laravel.

| Bảng | Các cột và tác dụng |
|---|---|
| `password_reset_tokens` | `email` là khóa chính và email yêu cầu đặt lại; `token` xác minh yêu cầu; `created_at` là thời điểm tạo token. |
| `sessions` | `id` định danh phiên; `user_id` là user đăng nhập (nullable); `ip_address`, `user_agent` mô tả client; `payload` chứa dữ liệu phiên; `last_activity` là thời điểm hoạt động gần nhất. |
| `cache` | `key` là khóa cache; `value` là nội dung; `expiration` là thời điểm hết hạn. |
| `cache_locks` | `key` định danh khóa; `owner` nhận diện tiến trình giữ khóa; `expiration` là thời điểm hết hạn. |
| `jobs` | `id` là khóa chính; `queue` là hàng đợi; `payload` chứa dữ liệu job; `attempts` là số lần thử; `reserved_at` là lúc worker nhận job; `available_at` là lúc job sẵn sàng; `created_at` là lúc tạo. |
| `job_batches` | `id` định danh batch; `name` là tên; `total_jobs`, `pending_jobs`, `failed_jobs` là số lượng; `failed_job_ids` liệt kê job lỗi; `options` chứa tùy chọn; `cancelled_at`, `created_at`, `finished_at` lưu các mốc thời gian. |
| `failed_jobs` | `id` là khóa chính; `uuid` định danh duy nhất; `connection`, `queue`, `payload` mô tả job; `exception` lưu lỗi; `failed_at` là thời điểm thất bại. |
| `migrations` | Bảng Laravel tự quản lý lịch sử migration đã chạy và batch tương ứng; không phải dữ liệu nghiệp vụ. |

## 6. Factory và kiểm thử model

Factory tạo dữ liệu có ý nghĩa, không rải `Project::create()` giống nhau trong nhiều test:

```php
$project = Project::factory()->create([
    'type' => ProjectType::Static,
    'status' => ProjectStatus::Active,
]);
```

Kiểm tra mối quan hệ, cast và constraint trong test hiện có tại
`apps/api/tests/Feature/DomainModelTest.php`.

## 7. Kiểm tra

```bash
php artisan migrate:fresh --seed
php artisan migrate:status
php artisan test --filter=DomainModelTest
```

> `migrate:fresh` xóa toàn bộ bảng của database đang kết nối. Chỉ chạy với database local/test đã xác nhận,
> tuyệt đối không chạy trên production.

## 8. Checklist

- [ ] Vẽ được quan hệ bốn thực thể deploy chính.
- [ ] Thêm schema bằng migration mới có rollback.
- [ ] Trạng thái được cast sang enum và test.
- [ ] Factory tạo được project tối thiểu.

👉 Tiếp theo: [04 — Session, đăng nhập và vai trò](04-session-dang-nhap-role.md).
