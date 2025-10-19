<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;

$this->router->get('/', function () {
    // Installer form view
    return view('install');
});

$this->router->post('/submit', function (Request $request) {
    $data = json_decode($request->getContent(), true);

    $errors = [];

    // Required fields validation
    if (empty($data['site_name'])) {
        $errors['site_name'] = 'Site name is required'; 
    }
    if (empty($data['timezone'])) {
        $errors['timezone'] = 'Full name is required';
    }
    if (empty($data['fullname'])) {
        $errors['fullname'] = 'Full name is required';
    }
    if (empty($data['admin_email'])) {
        $errors['admin_email'] = 'Email is required';
    } elseif (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['admin_email'] = 'Invalid email format';
    }
    if (empty($data['admin_username'])) {
        $errors['admin_username'] = 'Username is required';
    }
    if (empty($data['admin_password'])) {
        $errors['admin_password'] = 'Password is required';
    }
    if (($data['admin_password'] ?? '') !== ($data['admin_password_confirm'] ?? '')) {
        $errors['admin_password_confirm'] = 'Password confirmation does not match';
    }
    if (empty($data['database_host'])) {
        $errors['database_host'] = 'Database host is required';
    }
    if (empty($data['database_name'])) {
        $errors['database_name'] = 'Database name is required';
    }
    if (empty($data['database_username'])) {
        $errors['database_username'] = 'Database username is required';
    }

    if (!empty($errors)) {
        return new JsonResponse([
            'success' => false,
            'message' => 'Validation error',
            'errors'  => $errors
        ]);
    }

    // Offline dummy purchase verification
    $verifyResult = [
        'status' => 1,
        'product_id' => 1,
        'version' => '1.0',
        'install_path' => '',
        'download_url' => null // offline, no download
    ];

    // Optional: download & extract installer files
    $downloadUrl = $verifyResult['download_url'] ?? null;
    $installPath = base_path($verifyResult['install_path'] ?? '');
    if ($downloadUrl) {
        if (!is_dir($installPath)) {
            File::makeDirectory($installPath, 0775, true);
        }

        $tmpZip = storage_path('app/installer_' . uniqid() . '.zip');
        try {
            $fileResponse = Http::withoutVerifying()->timeout(60)->get($downloadUrl);
            if (!$fileResponse->ok()) {
                throw new \Exception('Download failed with status code: ' . $fileResponse->status());
            }
            file_put_contents($tmpZip, $fileResponse->body());

            $zip = new \ZipArchive();
            if ($zip->open($tmpZip) === TRUE) {
                $zip->extractTo($installPath);
                $zip->close();
                File::delete($tmpZip);
            } else {
                File::delete($tmpZip);
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Unable to unzip installer file!',
                    'errors'  => ['download_url' => 'Extract installer failed!']
                ]);
            }
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Download failed: ' . $e->getMessage(),
                'errors'  => ['download_url' => 'Download failed!']
            ]);
        }
    }

    // Database connection
    try {
        $dsn = "mysql:host={$data['database_host']};dbname={$data['database_name']};charset=utf8";
        $pdo = new \PDO($dsn, $data['database_username'], $data['database_password']);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    } catch (\PDOException $e) {
        return new JsonResponse([
            'success' => false,
            'message' => 'Cannot connect to database: ' . $e->getMessage(),
            'errors'  => ['database_host' => 'Database connection failed.']
        ]);
    }

    // Update .env file
    $site_url = str_replace("installer", "", url('/'));
    $site_url = str_replace("installer/", "", $site_url);
    $newVars = [
        'SITE_TITLE'    => $data['site_name'],
        'APP_NAME'      => $data['site_name'],
        'APP_URL'       => $site_url,
        'APP_TIMEZONE'  => $data['timezone'] ?? 'UTC',
        'APP_INSTALLED' => 'true',
        'DB_HOST'       => $data['database_host'],
        'DB_DATABASE'   => $data['database_name'],
        'DB_USERNAME'   => $data['database_username'],
        'DB_PASSWORD'   => $data['database_password'],
    ];
    updateEnvVars(base_path('.env'), $newVars);

    // Run migrations
    try {
        $migrator = $GLOBALS['container']->make('migrator');
        $schema = $GLOBALS['container']->make('db.schema');
        try {
            $schema->create('migrations', function ($table) {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        } catch (\PDOException $e) {
            if ($e->getCode() !== '42S01') {
                throw $e;
            }
        }

        $migrator->run(base_path('database/migrations'), ['--force' => true]);

    } catch (\Exception $e) {
        return new JsonResponse([
            'success' => false,
            'message' => 'Migrate failed: ' . $e->getMessage(),
            'errors'  => ['migrate' => 'Migrate database failed!']
        ]);
    }

    // Create admin user
    $result = createAdminUser($pdo, $data);
    if (!$result['success']) {
        return new JsonResponse($result);
    }

    // Insert dummy purchase addon
    insertPurchaseAddon($pdo, [
        'product_id'    => $verifyResult['product_id'],
        'version'       => $verifyResult['version'] ?? '1.0',
        'module_name'   => 'main',
        'purchase_code' => $data['purchase_code'] ?? 'LOCAL',
        'install_path'  => $verifyResult['install_path'] ?? '',
    ]);

    return new JsonResponse([
        'success' => true,
        'message' => 'Installation successful (Offline Mode)!'
    ]);
});
