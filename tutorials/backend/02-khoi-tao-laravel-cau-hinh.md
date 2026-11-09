# BE 02 — Khởi tạo Laravel và cấu hình

🎯 **Mục tiêu:** hiểu phần Laravel có sẵn, phần cPorter tự viết, cách request đi vào app và cách cấu hình
được nạp. Bài này không tạo một Laravel project song song: ta học cách dựng Backend bên trong `apps/api`.

## 1. Bản đồ thư mục

Từ repository root:

```text
apps/api/
├── artisan                 # CLI entrypoint
├── bootstrap/app.php       # đăng ký routing, middleware, exception behavior
├── bootstrap/providers.php # provider của ứng dụng
├── config/                 # cấu hình Laravel và config/cporter.php
├── routes/api.php          # API versioned
├── routes/console.php      # lệnh và lịch chạy
├── app/Http/               # controllers, middleware, requests
├── app/Domain/             # logic nghiệp vụ
├── app/Adapters/           # filesystem và command runner
├── app/Models/             # Eloquent models
├── database/               # migrations, seeders, factories
└── tests/                  # Pest
```

Laravel cung cấp router, dependency injection, Eloquent, queue, scheduler và test framework. Code dưới
`app/Domain` và `app/Adapters` là cấu trúc nghiệp vụ của cPorter, không phải folder bắt buộc của Laravel.

## 2. Composer và autoload

File `apps/api/composer.json` yêu cầu PHP `^8.2` và Laravel `^12.0`. PSR-4 ánh xạ namespace:

```json
{
  "autoload": {
    "psr-4": {
      "App\\": "app/",
      "Database\\Factories\\": "database/factories/",
      "Database\\Seeders\\": "database/seeders/"
    }
  }
}
```

Ví dụ `App\Domain\Deploy\DeployEngine` phải nằm tại
`apps/api/app/Domain/Deploy/DeployEngine.php`. Sau khi thay đổi mapping Composer, chạy từ `apps/api`:

```bash
composer dump-autoload
```

Không cần chạy lệnh này khi chỉ thêm class mới bên trong namespace đã được map.

## 3. Luồng request và route

`artisan` khởi động Laravel; `bootstrap/app.php` cấu hình ứng dụng, rồi router chọn route trong
`routes/api.php` hoặc `routes/web.php`. Đường dẫn thật phải được đối chiếu với middleware của route:

- API CI và API admin hiện cùng đăng ký dưới `/api/v1`; các nhóm route có middleware xác thực khác nhau.
- `/api/v1/health` là endpoint liveness không cần đăng nhập.
- API browser dùng session và CSRF; API CI dùng API key và scope.
- `routes/console.php` đăng ký lịch tác vụ cron, không phải HTTP endpoint.

Đọc mã nguồn để lần luồng cụ thể:

1. `apps/api/bootstrap/app.php`
2. `apps/api/routes/api.php`
3. `apps/api/app/Http/Controllers/Api`
4. `apps/api/app/Providers/AppServiceProvider.php`

Kiểm tra Laravel đã đăng ký route:

```bash
php artisan route:list --path=api/v1
```

Đừng suy ra quyền truy cập chỉ từ tên route hay controller; kiểm tra toàn bộ middleware group trong
`routes/api.php`.

## 4. Cấu hình theo môi trường

`.env` chứa giá trị theo máy/môi trường; các file `config/*.php` đọc env rồi cung cấp config ổn định cho app.
Ví dụ trong `config/cporter.php`:

```php
'allowed_base_paths' => array_filter(
    explode(',', (string) env('CPORTER_ALLOWED_BASE_PATHS', ''))
),
'deployment_timeout' => (int) env('CPORTER_DEPLOYMENT_TIMEOUT', 1800),
```

Trong code runtime, đọc qua `config('cporter.deployment_timeout')`, không gọi `env()` tùy tiện ngoài file
config. Quy tắc này quan trọng khi production chạy `php artisan config:cache`.

## 5. Dependency injection và service container

Controller/command nhận service qua constructor hoặc method injection. Interface cần implementation cụ thể
thì bind trong service provider. Cách này giữ Domain độc lập với HTTP và filesystem cụ thể:

```php
// Mẫu minh họa; xem binding thực tế trong AppServiceProvider.
$this->app->bind(StorageAdapter::class, CpanelFilesystemAdapter::class);
```

Không chép binding minh họa nếu class/config của project khác; bài 07 sẽ lần đúng adapter thực tế.

## 6. Chạy và kiểm tra

Chạy từ thư mục `apps/api` (hoặc dùng `docker compose exec api ...`):

```bash
php artisan about
php artisan route:list --path=api/v1
php artisan config:show cporter
php artisan test
```

`config:show` có thể hiện đường dẫn cấu hình của máy; đừng đưa secrets hay `.env` production vào log/tài liệu.

## Checklist

- [ ] Phân biệt Laravel framework với code nghiệp vụ cPorter.
- [ ] Tìm được route, controller, provider, config và console schedule.
- [ ] Hiểu Composer PSR-4 và config cache.
- [ ] Lệnh `route:list` chạy được.

👉 Tiếp theo: [03 — Domain model và migrations](03-domain-model-migrations.md).
