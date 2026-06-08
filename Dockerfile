FROM php:8.2-apache

# Install required system dependencies
RUN apt-get update && apt-get install -y \
    g++ \
    make \
    tesseract-ocr \
    tesseract-ocr-hin \
    libtesseract-dev \
    libleptonica-dev \
    libicu-dev \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN docker-php-ext-install intl mbstring

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Build the C++ OCR Engine
RUN make build

# Ensure uploads directory exists and has correct permissions
RUN mkdir -p uploads && \
    chown -R www-data:www-data uploads && \
    chmod 755 uploads

# Expose port 80
EXPOSE 80
