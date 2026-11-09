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

## Kiểm tra

```bash
php artisan migrate:fresh --seed
php artisan migrate:status
php artisan test --filter=DomainModelTest
```

> `migrate:fresh` xóa toàn bộ bảng của database đang kết nối. Chỉ chạy với database local/test đã xác nhận,
> tuyệt đối không chạy trên production.

## Checklist

- [ ] Vẽ được quan hệ bốn thực thể deploy chính.
- [ ] Thêm schema bằng migration mới có rollback.
- [ ] Trạng thái được cast sang enum và test.
- [ ] Factory tạo được project tối thiểu.

👉 Tiếp theo: [04 — Session, đăng nhập và vai trò](04-session-dang-nhap-role.md).
