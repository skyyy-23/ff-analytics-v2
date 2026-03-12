# Use official PHP CLI image
FROM php:8.2-cli

# Set working directory inside container
WORKDIR /app

# Copy all project files into container
COPY . .

# Install Composer if you have composer.json
RUN apt-get update && apt-get install -y unzip git && \
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    composer install || true

# Expose port for Render
EXPOSE 10000

# Start PHP built-in server, root folder = loader index
CMD ["php", "-S", "0.0.0.0:10000", "-t", "."]