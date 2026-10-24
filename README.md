# Hệ thống điểm danh lớp học

Ứng dụng PHP 8.3 + MySQL 8.4, chạy bằng Docker Compose. Quản trị viên quản lý sinh viên, môn học và lịch buổi học; sinh viên đăng nhập để điểm danh theo lớp và xem lịch sử. Prometheus/Grafana theo dõi ứng dụng, MySQL, Nginx và container; Loki/Promtail tập trung log. Múi giờ ứng dụng là `Asia/Ho_Chi_Minh`.

## Khởi chạy

1. Cài Docker Engine/Desktop và Docker Compose plugin.
2. Sao chép `.env.example` thành `.env`; thay các giá trị mật khẩu bằng chuỗi ngẫu nhiên riêng biệt. Mật khẩu quản trị ứng dụng phải có ít nhất 12 ký tự. Không commit `.env`.
3. Chạy `docker compose up -d --build`.
4. Mở ứng dụng tại `http://localhost:18270`. Tài khoản quản trị được đồng bộ từ `APP_ADMIN_EMAIL` và `APP_ADMIN_PASSWORD` trong `.env`; tài khoản đầu tiên trong cơ sở dữ liệu được dùng làm tài khoản quản trị. Để đổi email hoặc mật khẩu quản trị, sửa `.env`, sau đó chạy `docker compose up -d --build --force-recreate app` để áp dụng biến môi trường mới.

phpMyAdmin ở `http://localhost:18271`, Prometheus ở `http://localhost:19027`, Grafana ở `http://localhost:13027`. Các cổng này và cổng ứng dụng chỉ bind vào loopback của máy chủ. Các cổng dùng dải riêng để tránh xung đột với dịch vụ chạy sẵn trên máy. Grafana dùng mật khẩu `GRAFANA_ADMIN_PASSWORD` trong `.env`.

> Đây là bộ khung đồ án, không phải cấu hình production hoàn chỉnh. Trước khi public Internet, đặt TLS tại reverse proxy/ingress đáng tin cậy, bật `APP_COOKIE_SECURE=1`, thay toàn bộ mật khẩu, giới hạn hoặc tắt đăng ký mở, và cấu hình sao lưu/khôi phục MySQL. Không mở trực tiếp phpMyAdmin, Prometheus, Grafana hay Loki ra Internet.

## Tính năng và quy tắc

- Quản trị viên đăng nhập bằng tài khoản `APP_ADMIN_*`, tạo tài khoản sinh viên, gán lớp, quản lý môn học và lên lịch buổi học.
- Sinh viên chỉ thấy lịch của lớp mình; chỉ điểm danh được trong ngày diễn ra buổi học. Hệ thống tự đánh dấu đi trễ sau số phút cho phép.
- Danh sách buổi học và lịch sử điểm danh được giới hạn để dễ theo dõi; mỗi sinh viên chỉ điểm danh một lần cho mỗi buổi.
- MySQL seed và kết nối ứng dụng dùng `utf8mb4` để lưu/hiển thị tên tiếng Việt chính xác.
- Tài khoản sinh viên được tạo bởi quản trị viên, mật khẩu tối thiểu 12 ký tự.
- Tài khoản đầu tiên được tạo là tài khoản quản trị; mỗi lần khởi động ứng dụng, thông tin tài khoản này được đồng bộ theo các biến `APP_ADMIN_*`. Tài khoản sinh viên tạo sau đó không bị thay đổi.
- Khi khởi động, ứng dụng tự áp dụng migration bổ sung dữ liệu lớp học và giữ nguyên bảng/dữ liệu điểm danh nhân viên cũ.

## Quan sát hệ thống

- Dashboard **Attendance - Operations** được Grafana tự động nạp, gồm trạng thái các scrape target, số lượt điểm danh, kết nối Nginx, MySQL và mức dùng bộ nhớ container.
- Prometheus scrape endpoint metrics nội bộ của ứng dụng (`http://app:8080/metrics`, không publish ra host; `attendance_records_total`, `attendance_records_today`), MySQL exporter, Nginx exporter và cAdvisor (`container_memory_working_set_bytes`).
- Loki nhận log truy cập Nginx dạng JSON và log lỗi PHP/Apache qua Promtail. Trong Grafana > Explore > Loki, thử LogQL:
  - `{job="attendance-nginx"}`
  - `{job="attendance-nginx",status="500"}`
  - `{job="attendance-php"}`
- Dashboard/Prometheus target có thể mất vài chu kỳ scrape (mặc định 15 giây) trước khi hiện dữ liệu.

## Báo cáo

Báo cáo Word nằm ở [`docs/BAO-CAO.docx`](docs/BAO-CAO.docx); [`docs/BAO-CAO.html`](docs/BAO-CAO.html) là bản nguồn. Trước khi nộp, bổ sung tên học phần, tỉnh/thành phố, URL repository, ảnh dashboard Grafana sau khi đăng nhập và các minh chứng demo còn thiếu.

## Hardening đã áp dụng

- Truy vấn PDO dùng prepared statements; xác thực mật khẩu bằng `password_hash`/`password_verify`; token CSRF cho các form; session ID đổi sau đăng nhập; encode đầu ra HTML.
- Nginx giới hạn kích thước request, rate-limit request, ẩn phiên bản và đặt security headers.
- App/database và monitoring dùng các mạng nội bộ Compose; Nginx, phpMyAdmin, Prometheus và Grafana chỉ được publish qua cổng loopback trên host. Nginx/phpMyAdmin/monitoring chỉ được nối vào những network cần thiết.
- App và Nginx chạy bằng UID không phải root, dùng filesystem chỉ đọc, bỏ Linux capabilities và bật `no-new-privileges`; log được mount riêng để Promtail đọc.
- Tài khoản `attendance_metrics` chỉ có quyền đọc và các quyền giám sát cần thiết; exporter không dùng tài khoản root.
- cAdvisor chỉ đọc Docker/runtime filesystem và chỉ được expose trong network monitoring. Trên Docker Desktop, một số chỉ số host/container có thể khác Linux server do Docker chạy trong VM.
- Mật khẩu chỉ được cấu hình trong `.env` (đã ignore bởi Git), không lưu trong mã nguồn.

## Lưu ý vận hành

- `database/init.sql` chỉ được chạy khi khởi tạo volume MySQL mới. Nếu sửa schema ở môi trường đã chạy, hãy dùng migration có kiểm soát; không xóa volume dữ liệu chỉ để chạy lại init.
- Khi bật đăng ký, mọi người có thể tự tạo tài khoản; khi dùng thực tế, ưu tiên quy trình mời/duyệt và phân quyền quản trị.
- Khi triển khai sau TLS termination, bật cookie secure và cấu hình trusted proxy phù hợp. Chỉ tin forwarded headers từ proxy do bạn quản lý.
- Backup dữ liệu: dùng `mysqldump` theo quy trình của môi trường triển khai và kiểm tra khôi phục định kỳ.
