<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use PlexDNS\Service;

// Autoload dependencies via Composer
require_once __DIR__ . '/vendor/autoload.php';
require_once 'helpers.php';

// Load environment variables from .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Required configuration from .env
$apiKey    = $_ENV['API_KEY']    ?? null;
$provider  = $_ENV['PROVIDER']   ?? null;
$apiToken  = $_ENV['API_TOKEN']  ?? null; // our API token for authenticating requests

if (!$apiKey || !$provider || !$apiToken) {
    die("Error: Missing required environment variables (API_KEY, PROVIDER or API_TOKEN) in .env\n");
}

// ClouDNS specific credentials (if using ClouDNS)
if ($provider === 'ClouDNS') {
    $cloudnsAuthId       = $_ENV['AUTH_ID']       ?? null;
    $cloudnsAuthPassword = $_ENV['AUTH_PASSWORD'] ?? null;
    if (!$cloudnsAuthId || !$cloudnsAuthPassword) {
        die("Error: Missing ClouDNS credentials (AUTH_ID and AUTH_PASSWORD) in .env\n");
    }
}

// Database configuration
$dbType   = $_ENV['DB_TYPE'] ?? 'mysql';
$host     = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbName   = $_ENV['DB_NAME'] ?? '';
$username = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASS'] ?? '';
$sqlitePath = __DIR__ . '/database.sqlite';

if ($dbType !== 'sqlite' && (!$dbName || !$username || !$password)) {
    die("Error: Missing required database configuration in .env\n");
}

try {
    // Build the PDO DSN based on the configured DB type
    if ($dbType === 'mysql') {
        $dsn = "mysql:host=$host;dbname=$dbName;charset=utf8mb4";
    } elseif ($dbType === 'pgsql') {
        $dsn = "pgsql:host=$host;dbname=$dbName";
    } elseif ($dbType === 'sqlite') {
        $dsn = "sqlite:$sqlitePath";
    } else {
        throw new Exception("Unsupported database type: $dbType");
    }

    // Create PDO instance with proper error handling
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT         => false,
    ]);

    if ($dbType === 'sqlite') {
        $pdo->exec("PRAGMA foreign_keys = ON;");
    }
} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}

// Initialize the PlexDNS Service with the PDO connection
$service = new Service($pdo);

// Create a Swoole HTTP server listening on 0.0.0.0:9501
$server = new Swoole\Http\Server("0.0.0.0", 9501);

$server->on("start", function (Swoole\Http\Server $server) {
    echo "Swoole HTTP server started at http://0.0.0.0:9501\n";
});

// Main request handler
$server->on("request", function (Swoole\Http\Request $request, Swoole\Http\Response $response) use ($apiToken, $service, $provider, $apiKey) {
    // Set default JSON header
    $response->header("Content-Type", "application/json");

    // API Token Authentication: check the "Authorization" or "X-API-Token" header.
    $headers = $request->header;
    $clientToken = '';
    if (isset($headers['authorization'])) {
        // Expecting header format "Bearer <token>"
        if (preg_match('/Bearer\s+(\S+)/i', $headers['authorization'], $matches)) {
            $clientToken = $matches[1];
        }
    } elseif (isset($headers['x-api-token'])) {
        $clientToken = $headers['x-api-token'];
    }

    if ($clientToken !== $apiToken) {
        $response->status(401);
        $response->end(json_encode(['error' => 'Unauthorized']));
        return;
    }

    // Get the request URI and method
    $uri    = $request->server['request_uri'] ?? '/';
    $method = strtoupper($request->server['request_method'] ?? 'GET');

    // Read and decode JSON input if present
    $input = [];
    if (!empty($request->rawContent())) {
        $input = json_decode($request->rawContent(), true);
        if (!is_array($input)) {
            $response->status(400);
            $response->end(json_encode(['error' => 'Invalid JSON payload']));
            return;
        }
    }

    try {
        // Routing: define endpoints and methods
        if ($uri === '/install' && $method === 'POST') {
            // Install database structure
            $service->install();
            $result = ['status' => 'success', 'message' => 'Database structure installed'];
        }
        elseif ($uri === '/uninstall' && $method === 'POST') {
            // Uninstall database structure
            $service->uninstall();
            $result = ['status' => 'success', 'message' => 'Database structure uninstalled'];
        }
        elseif ($uri === '/domain' && $method === 'POST') {
            // Create a domain
            if (!isset($input['client_id'], $input['config'])) {
                throw new Exception("Missing parameters: client_id and config are required");
            }
            // Sanitize client_id as integer
            $clientId = intval($input['client_id']);
            // Assume config is an associative array; re-encode safely.
            $config = json_encode($input['config']);
            $domain = $service->createDomain(['client_id' => $clientId, 'config' => $config]);
            $result = ['status' => 'success', 'domain' => $domain];
        }
        elseif ($uri === '/record' && $method === 'POST') {
            // Add a DNS record
            $requiredFields = ['domain_name', 'record_name', 'record_type', 'record_value', 'record_ttl'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Missing field: $field");
                }
            }
            // Basic sanitization/validation
            $recordTtl = filter_var($input['record_ttl'], FILTER_VALIDATE_INT);
            if ($recordTtl === false) {
                throw new Exception("Invalid record_ttl");
            }
            $input['record_ttl'] = $recordTtl;
            // Use global provider and API key if not provided
            $input['provider'] = $provider;
            $input['apikey']   = $apiKey;
            $recordId = $service->addRecord($input);
            $result = ['status' => 'success', 'record_id' => $recordId];
        }
        elseif ($uri === '/record' && $method === 'PUT') {
            // Update a DNS record
            $requiredFields = ['domain_name', 'record_id', 'record_name', 'record_type', 'record_value', 'record_ttl'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Missing field: $field");
                }
            }
            $recordTtl = filter_var($input['record_ttl'], FILTER_VALIDATE_INT);
            if ($recordTtl === false) {
                throw new Exception("Invalid record_ttl");
            }
            $input['record_ttl'] = $recordTtl;
            $input['provider']   = $provider;
            $input['apikey']     = $apiKey;
            $service->updateRecord($input);
            $result = ['status' => 'success', 'message' => 'DNS record updated'];
        }
        elseif ($uri === '/record' && $method === 'DELETE') {
            // Delete a DNS record: require domain_name and record_id
            $requiredFields = ['domain_name', 'record_id'];
            foreach ($requiredFields as $field) {
                if (empty($input[$field])) {
                    throw new Exception("Missing field: $field");
                }
            }
            $input['provider'] = $provider;
            $input['apikey']   = $apiKey;
            $service->delRecord($input);
            $result = ['status' => 'success', 'message' => 'DNS record deleted'];
        }
        elseif ($uri === '/domain' && $method === 'DELETE') {
            // Delete a domain: require config parameter containing domain_name, etc.
            if (empty($input['config'])) {
                throw new Exception("Missing field: config");
            }
            // Ensure config is properly JSON-encoded
            $input['config'] = json_encode($input['config']);
            $service->deleteDomain($input);
            $result = ['status' => 'success', 'message' => 'Domain deleted'];
        }
        else {
            $response->status(404);
            $result = ['error' => 'Endpoint not found'];
        }
    } catch (Exception $e) {
        $response->status(400);
        $result = ['error' => $e->getMessage()];
    }

    // Send the JSON-encoded response
    $response->end(json_encode($result));
});

$server->start();