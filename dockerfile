FROM php:8.2-apache

# 1. ติดตั้งโปรแกรมพื้นฐานที่จำเป็น (Git, Unzip, Zip)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# 2. ล้าง Cache เพื่อลดขนาดไฟล์
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# 3. ติดตั้ง PHP Extensions ที่ Laravel ต้องใช้
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# 4. เปิดใช้งาน module rewrite ของ Apache (เพื่อให้ URL สวยๆ)
RUN a2enmod rewrite

# 5. ติดตั้ง Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 6. ตั้งค่า Folder ทำงาน
WORKDIR /var/www/html

# 7. ก๊อปปี้ไฟล์โปรเจกต์
COPY . .

# 8. รันคำสั่งติดตั้ง (เพิ่ม flag เพื่อป้องกัน error บางอย่าง)
RUN composer install --no-interaction --optimize-autoloader --no-dev

# 9. แก้สิทธิ์การเข้าถึงไฟล์ (สำคัญมาก!)
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 10. ตั้งค่า Apache ให้ชี้ไปที่ public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# 11. เปิด Port 80
EXPOSE 80