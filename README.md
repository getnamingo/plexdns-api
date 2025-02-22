# PlexDNS API Tool

The PlexDNS API Tool is a JSON API built on Swoole and the [namingo/plexdns](https://github.com/namingo/plexdns) library. It provides endpoints for managing DNS domains and records while incorporating API token authentication.

## Features

- **DNS Management:** Install/uninstall database structures, create/delete domains, and add/update/delete DNS records.
- **High Performance:** Powered by Swoole's asynchronous HTTP server.
- **Security:** API token authentication and input sanitization.
- **Flexible Database Support:** Works with MySQL, PostgreSQL, or SQLite.
- **Easy Configuration:** Environment variables managed with Dotenv.

## Requirements

- PHP 8.2 or higher with Swoole extension installed and enabled
- A supported database (MySQL, PostgreSQL, or SQLite)

## Installation

### 1. Clone the Repository

Clone the repository to your local machine:

```bash
mkdir /opt/plexdns-api
git clone https://github.com/getnamingo/plexdns-api /opt/plexdns-api
```

### 2. Install Dependencies

Install the required PHP packages using Composer:

```bash
cd /opt/plexdns-api
composer install
```

### 3. Configuration

Create a `.env` file in the project root directory with the following content. Modify the values to suit your environment:

```dotenv
# API & Provider Configuration
API_KEY=your_api_key_here
PROVIDER=YourProviderName # AnycastDNS, Bind, ClouDNS, Desec, DNSimple, Hetzner, PowerDNS, Vultr
API_TOKEN=your_secure_api_token

# ClouDNS Specific (if using ClouDNS)
AUTH_ID=your_cloudns_auth_id
AUTH_PASSWORD=your_cloudns_auth_password

# Database Configuration (choose one: mysql, pgsql, or sqlite)
DB_TYPE=mysql
DB_HOST=127.0.0.1
DB_NAME=your_database_name
DB_USER=your_database_username
DB_PASS=your_database_password

# For SQLite, use the following settings:
# DB_TYPE=sqlite
# (DB_HOST, DB_NAME, DB_USER, and DB_PASS are not required for SQLite)
```

### 4. Caddy Server

1. Install Caddy:

```bash
apt install caddy
```

2. Edit `/etc/caddy/Caddyfile` and add as new record the following, then replace the values with your own.

```bash
    plexdns.YOUR_DOMAIN {
        bind YOUR_IP_V4 YOUR_IP_V6
        reverse_proxy localhost:8500
        encode gzip
        file_server
        tls YOUR_EMAIL
        header -Server
        header * {
            Referrer-Policy "no-referrer"
            Strict-Transport-Security max-age=31536000;
            X-Content-Type-Options nosniff
            X-Frame-Options DENY
            X-XSS-Protection "1; mode=block"
            Content-Security-Policy "default-src 'none'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; img-src https:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'none'; form-action 'self'; worker-src 'none'; frame-src 'none';"
            Feature-Policy "accelerometer 'none'; autoplay 'none'; camera 'none'; encrypted-media 'none'; fullscreen 'self'; geolocation 'none'; gyroscope 'none'; magnetometer 'none'; microphone 'none'; midi 'none'; payment 'none'; picture-in-picture 'self'; usb 'none';"
            Permissions-Policy: accelerometer=(), autoplay=(), camera=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(self), usb=();
        }
    }
```

3. Restart Caddy with `systemctl restart caddy`

4. Copy `plexdnsapi.service` to `/etc/systemd/system/`. Change only User and Group lines to your user and group.

```bash
systemctl daemon-reload
systemctl start plexdnsapi.service
systemctl enable plexdnsapi.service
```

After that you can manage API via systemctl as any other service.

## Running the API Server

Ensure the Swoole extension is installed and enabled. Then, start the Swoole HTTP server with:

```bash
php start_api.php
```

The server will start and listen on http://0.0.0.0:9501.

## API Endpoints

All endpoints expect requests with JSON payloads and require an API token. Include the token in the header as either:

- Authorization: `Bearer your_secure_api_token`

- X-API-Token: `your_secure_api_token`

### Endpoints Overview

- **POST** `/install` - installs the database structure.

- **POST** `/uninstall` - uninstalls the database structure.

- **POST** `/domain` - creates a new domain.

```json
{
  "client_id": 1,
  "config": {
    "domain_name": "example.com",
    "provider": "YourProviderName",
    "apikey": "your_api_key_here"
  }
}
```

- **DELETE** `/domain` - deletes a domain.

```json
{
  "config": {
    "domain_name": "example.com",
    "provider": "YourProviderName",
    "apikey": "your_api_key_here"
  }
}
```

- **POST** `/record` - creates a new DNS record.

```json
{
  "domain_name": "example.com",
  "record_name": "www",
  "record_type": "A",
  "record_value": "192.168.1.1",
  "record_ttl": 3600
}
```

- **PUT** `/record` - updates a DNS record.

```json
{
  "domain_name": "example.com",
  "record_id": 123,
  "record_name": "www",
  "record_type": "A",
  "record_value": "192.168.1.2",
  "record_ttl": 7200
}
```

- **DELETE** `/record` - deletes a DNS record.

```json
{
  "domain_name": "example.com",
  "record_id": 123
}
```

### Logging

Logs are written to `/var/log/plexdns/plexdns.log` by default. Adjust the logging path in the code if necessary.

## Contributing

Contributions are welcome! Fork the repository, make your changes, and submit a pull request.

## License

This project is licensed under the MIT License.