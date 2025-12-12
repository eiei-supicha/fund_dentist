FROM php:8.2-apache

# 1. ติดตั้งโปรแกรมพื้นฐาน
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# 2. ล้าง Cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 3. ติดตั้ง PHP Extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 4. เปิดใช้งาน module rewrite
RUN a2enmod rewrite

# 5. ติดตั้ง Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. ตั้งค่า Folder ทำงาน
WORKDIR /var/www/html

# 7. ก๊อปปี้ไฟล์โปรเจกต์
COPY . .

# 8. สร้างไฟล์ Database เปล่าๆ ขึ้นมา (แก้ปัญหา File not exist)
RUN touch database/database.sqlite

# 9. รันคำสั่งติดตั้ง Composer
RUN composer install --no-interaction --optimize-autoloader --no-dev

# 10. แก้สิทธิ์การเข้าถึงไฟล์ (เพิ่ม database เข้าไปเพื่อให้เขียนข้อมูลได้)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/database

# 11. ตั้งค่า Apache
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 12. เปิด Port 80
EXPOSE 80