<?php
/**
 * Filipino Cookbook API
 * Built with Slim Framework 4 + PDO (MySQL) + Token-Based Security
 *
 * Run with XAMPP:
 *   1. Import filipino_foods_relational.sql into MySQL (creates filipino_cookbook_api DB)
 *   2. Place this whole project folder inside htdocs/ (e.g. htdocs/slim_project)
 *   3. Run "composer install" inside the project folder
 *   4. Visit http://localhost/slim_project/frontend/index.html
 *      (this API itself lives at http://localhost/slim_project/api/)
 *
 * Alternatively, run with PHP's built-in server (no Apache/XAMPP needed for
 * the API itself, though MySQL still needs to be running):
 *   From the project folder: php -S localhost:8000 -t api
 *   The API then lives at http://localhost:8000/ directly -- there is no
 *   "/slim_project/api" prefix in this mode, e.g. GET http://localhost:8000/foods
 */

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response as SlimResponse;

// Safety net: vendor/composer/autoload_static.php has been edited to no
// longer require vendor/ralouphie/getallheaders/src/getallheaders.php,
// because on this environment that require was failing outright (file
// open failure) and crashing the app before Slim could even start —
// Composer's generated loader has no existence check before requiring
// each file, so nothing downstream could recover once that require ran.
// That edit is the actual fix. This function definition below is just
// insurance: if a future `composer install` regenerates autoload_static.php
// from composer.lock (which still lists ralouphie/getallheaders as a
// dependency) and reintroduces that require, Slim will still work,
// since PHP running as an Apache module — the standard XAMPP setup —
// has shipped a native getallheaders() since PHP 7.3 and never actually
// needed this polyfill in the first place.
if (!function_exists('getallheaders')) {
    function getallheaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$header] = $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }
        if (!isset($headers['Authorization']) && isset($_SERVER['PHP_AUTH_USER'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode(
                $_SERVER['PHP_AUTH_USER'] . ':' . ($_SERVER['PHP_AUTH_PW'] ?? '')
            );
        }
        return $headers;
    }
}

require __DIR__ . '/../vendor/autoload.php';

// -----------------------------------------------------------------------
// A. DATABASE CONNECTION (PDO)
// -----------------------------------------------------------------------
$dbHost = 'localhost';
$dbName = 'filipino_cookbook_api';
$dbUser = 'root';
$dbPass = ''; // default XAMPP MySQL password is empty

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage(),
    ]);
    exit;
}

// -----------------------------------------------------------------------
// APP SETUP
// -----------------------------------------------------------------------
$app = AppFactory::create();

// Tell Slim what URL prefix this script is running under.
//
// This is computed from SCRIPT_NAME instead of hardcoded, because the
// correct prefix depends on how the app is being served:
//   - Apache/XAMPP, project at htdocs/slim_project/api/index.php:
//     SCRIPT_NAME = /slim_project/api/index.php -> basePath = /slim_project/api
//     URLs look like: http://localhost/slim_project/api/foods
//   - PHP's built-in server pointed at the api folder
//     (php -S localhost:8000 -t api):
//     SCRIPT_NAME = /index.php -> basePath = '' (no prefix at all)
//     URLs look like: http://localhost:8000/foods
// A hardcoded '/slim_project/api' only matches the first case; under the
// built-in server it doesn't match anything Slim receives, so every route
// -- including the welcome route at "/" -- 404s. Deriving it from
// SCRIPT_NAME means both setups work without editing this file.
$app->setBasePath(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']));

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

// Make the request body safely re-readable.
//
// PHP's raw input stream (php://input) can only be read reliably ONCE per
// request on many server setups (including typical XAMPP/Apache installs).
// Slim's body-parsing middleware performs that one read; if the
// Content-Type header it sees isn't recognized as exactly "application/json"
// for any reason, it discards what it read and returns nothing — and any
// later attempt to read the body again (e.g. a manual fallback parser in a
// route handler) silently comes back empty, even after rewinding, because
// the underlying stream has already been drained.
//
// To avoid ever losing the body, copy it into a genuine in-memory
// (php://temp) stream right here, before anything else touches it. Unlike
// php://input, php://temp supports being rewound and read multiple times,
// so both Slim's own parser and any manual fallback downstream can read it
// safely.
$app->add(function (Request $request, RequestHandlerInterface $handler): Response {
    $raw = (string) $request->getBody();

    $rewindable = fopen('php://temp', 'r+');
    fwrite($rewindable, $raw);
    rewind($rewindable);

    $request = $request->withBody(new \Slim\Psr7\Stream($rewindable));

    return $handler->handle($request);
});

// Strip a trailing slash from the request path (except for the root "/")
// before routing runs. Without this, a request to ".../api/foods/" (note
// the trailing slash) 404s even though the "/foods" route exists, because
// Slim's router treats "/foods" and "/foods/" as two different paths.
$app->add(function (Request $request, RequestHandlerInterface $handler): Response {
    $uri = $request->getUri();
    $path = $uri->getPath();

    if ($path !== '/' && str_ends_with($path, '/')) {
        $request = $request->withUri($uri->withPath(rtrim($path, '/')));
    }

    return $handler->handle($request);
});

$app->add(function (Request $request, RequestHandlerInterface $handler): Response {
    if ($request->getMethod() === 'OPTIONS') {
        $response = new SlimResponse();
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withStatus(200);
    }

    $response = $handler->handle($request);

    $origin = $request->getHeaderLine('Origin');
    if ($origin) {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
    }

    return $response;
});

// Small helper to always respond with JSON
function jsonResponse(Response $response, array $data, int $status = 200): Response
{
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
}

// -----------------------------------------------------------------------
// C. TOKEN-BASED SECURITY (middleware for the /api group)
// -----------------------------------------------------------------------
const API_TOKEN = 'dmmmsu-cookbook-token-2026';

$authMiddleware = function (Request $request, $handler) {
    $header = $request->getHeaderLine('Authorization');

    if (!$header || !preg_match('/Bearer\s+(.+)/i', $header, $matches) || $matches[1] !== API_TOKEN) {
        $response = new SlimResponse();
        return jsonResponse($response, [
            'status'  => 'error',
            'message' => 'Unauthorized access. Valid API token is required.',
        ], 401);
    }

    return $handler->handle($request);
};

// -----------------------------------------------------------------------
// PART 4.1 — PUBLIC WELCOME ROUTE (no token required)
// -----------------------------------------------------------------------
// Match both the bare root path and the slashless base-path form so the
// welcome endpoint works from Thunder Client and from Apache/XAMPP URLs.
$app->get('[/]', function (Request $request, Response $response) {
    return jsonResponse($response, [
        'message' => 'Welcome to the Secured Filipino Cookbook',
        'note'    => 'Eat right, do not get fat!.',
    ]);
});

// -----------------------------------------------------------------------
// SECURED ROUTES (this whole file already lives under /api, so no extra
// '/api' prefix here — otherwise URLs would end up as .../api/api/foods)
// -----------------------------------------------------------------------
$app->group('', function ($group) use ($pdo) {

    // ---- Helper: attach ingredients array to a food row --------------
    $attachIngredients = function (PDO $pdo, array $food): array {
        $stmt = $pdo->prepare(
            'SELECT i.ingredient_name
             FROM ingredients i
             INNER JOIN food_ingredients fi ON fi.ingredient_id = i.ingredient_id
             WHERE fi.food_id = :food_id
             ORDER BY i.ingredient_name ASC'
        );
        $stmt->execute(['food_id' => $food['food_id']]);
        $food['ingredients'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return $food;
    };

    $foodQueryBase = 'SELECT f.food_id, f.food_name, c.category_name, o.origin_name, f.instructions
                       FROM foods f
                       INNER JOIN categories c ON c.category_id = f.category_id
                       INNER JOIN origins o ON o.origin_id = f.origin_id';

    // ---- 4.2 GET ALL FOODS --------------------------------------------
    $group->get('/foods', function (Request $request, Response $response) use ($pdo, $foodQueryBase, $attachIngredients) {
        $stmt = $pdo->query($foodQueryBase . ' ORDER BY f.food_id ASC');
        $foods = $stmt->fetchAll();

        $foods = array_map(fn($food) => $attachIngredients($pdo, $food), $foods);

        return jsonResponse($response, $foods);
    });

    // ---- 4.3 GET FOOD BY ID --------------------------------------------
    $group->get('/foods/{id}', function (Request $request, Response $response, array $args) use ($pdo, $foodQueryBase, $attachIngredients) {
        $stmt = $pdo->prepare($foodQueryBase . ' WHERE f.food_id = :id');
        $stmt->execute(['id' => $args['id']]);
        $food = $stmt->fetch();

        if (!$food) {
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Food not found',
            ], 404);
        }

        $food = $attachIngredients($pdo, $food);

        return jsonResponse($response, $food);
    });

    // ---- 4.4 SEARCH FOOD BY NAME ---------------------------------------
    $group->get('/foods/search/{name}', function (Request $request, Response $response, array $args) use ($pdo, $foodQueryBase, $attachIngredients) {
        $stmt = $pdo->prepare($foodQueryBase . ' WHERE f.food_name LIKE :name ORDER BY f.food_name ASC');
        $stmt->execute(['name' => '%' . $args['name'] . '%']);
        $foods = $stmt->fetchAll();

        $foods = array_map(fn($food) => $attachIngredients($pdo, $food), $foods);

        return jsonResponse($response, $foods);
    });

    // ---- 4.5 GET ALL CATEGORIES ----------------------------------------
    $group->get('/categories', function (Request $request, Response $response) use ($pdo) {
        $stmt = $pdo->query('SELECT category_id, category_name FROM categories ORDER BY category_name ASC');
        return jsonResponse($response, $stmt->fetchAll());
    });

    // ---- 4.6 GET ALL INGREDIENTS ---------------------------------------
    $group->get('/ingredients', function (Request $request, Response $response) use ($pdo) {
        $stmt = $pdo->query('SELECT ingredient_id, ingredient_name FROM ingredients ORDER BY ingredient_name ASC');
        return jsonResponse($response, $stmt->fetchAll());
    });

    // ---- GET ALL ORIGINS ------------------------------------------------
    $group->get('/origins', function (Request $request, Response $response) use ($pdo) {
        $stmt = $pdo->query('SELECT origin_id, origin_name FROM origins ORDER BY origin_name ASC');
        return jsonResponse($response, $stmt->fetchAll());
    });

    // ---- 4.7 ADD NEW FOOD -----------------------------------------------
    $group->post('/foods', function (Request $request, Response $response) use ($pdo) {
        $data = $request->getParsedBody();

        // Fallback: if Slim's body-parsing middleware didn't populate the
        // parsed body, decode the raw body ourselves before giving up.
        if (empty($data)) {
            $body = $request->getBody();
            $body->rewind();
            $raw = $body->getContents();

            if (trim($raw) !== '') {
                $decoded = json_decode($raw, true);

                // json_decode() returns null both for a literal "null" body
                // and for invalid JSON (e.g. a trailing comma after the
                // last field). json_last_error() tells them apart. Without
                // this check, invalid JSON silently looks identical to an
                // empty body, and the field-by-field check below reports a
                // misleading "missing required field" even when every field
                // is right there in the (unparseable) body.
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return jsonResponse($response, [
                        'status'  => 'error',
                        'message' => 'Request body is not valid JSON: ' . json_last_error_msg()
                            . '. (Common cause: a trailing comma after the last field.)',
                    ], 400);
                }

                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        $data = $data ?? [];

        $required = ['food_name', 'category_id', 'origin_id', 'instructions'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return jsonResponse($response, [
                    'status'  => 'error',
                    'message' => "Missing required field: {$field}",
                ], 400);
            }
        }

        try {
            $pdo->beginTransaction();

            // foods.food_id has no AUTO_INCREMENT in the provided schema,
            // so the next id is derived manually.
            $nextId = (int) $pdo->query('SELECT COALESCE(MAX(food_id), 0) + 1 FROM foods')->fetchColumn();

            $stmt = $pdo->prepare(
                'INSERT INTO foods (food_id, food_name, category_id, origin_id, instructions)
                 VALUES (:food_id, :food_name, :category_id, :origin_id, :instructions)'
            );
            $stmt->execute([
                'food_id'      => $nextId,
                'food_name'    => $data['food_name'],
                'category_id'  => $data['category_id'],
                'origin_id'    => $data['origin_id'],
                'instructions' => $data['instructions'],
            ]);

            if (!empty($data['ingredient_ids']) && is_array($data['ingredient_ids'])) {
                $linkStmt = $pdo->prepare(
                    'INSERT INTO food_ingredients (food_id, ingredient_id) VALUES (:food_id, :ingredient_id)'
                );
                foreach ($data['ingredient_ids'] as $ingredientId) {
                    $linkStmt->execute([
                        'food_id'       => $nextId,
                        'ingredient_id' => $ingredientId,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Failed to add food: ' . $e->getMessage(),
            ], 500);
        }

        return jsonResponse($response, [
            'status'  => 'success',
            'message' => 'Food added successfully.',
            'food_id' => $nextId,
        ], 201);
    });

    // ---- DELETE FOOD ------------------------------------------------------
    $group->delete('/foods/{id}', function (Request $request, Response $response, array $args) use ($pdo) {
        $id = $args['id'];

        $stmt = $pdo->prepare('SELECT food_id FROM foods WHERE food_id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Food not found',
            ], 404);
        }

        try {
            $pdo->beginTransaction();

            // Remove ingredient links first (in case there's no ON DELETE
            // CASCADE on the foreign key in the schema).
            $linkStmt = $pdo->prepare('DELETE FROM food_ingredients WHERE food_id = :id');
            $linkStmt->execute(['id' => $id]);

            $foodStmt = $pdo->prepare('DELETE FROM foods WHERE food_id = :id');
            $foodStmt->execute(['id' => $id]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            return jsonResponse($response, [
                'status'  => 'error',
                'message' => 'Failed to delete food: ' . $e->getMessage(),
            ], 500);
        }

        return jsonResponse($response, [
            'status'  => 'success',
            'message' => 'Food deleted successfully.',
            'food_id' => $id,
        ], 200);
    });

})->add($authMiddleware);

$app->run();
