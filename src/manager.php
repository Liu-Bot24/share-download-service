<?php
declare(strict_types=1);

function manager_state(ShareStore $store, string $path): array
{
    $storage = dirname(__DIR__) . '/storage';
    $sessions = $storage . '/sessions';
    if (!is_dir($sessions) && !mkdir($sessions, 0700, true) && !is_dir($sessions)) {
        throw new RuntimeException('Could not create session directory.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '43200');
    session_save_path($sessions);
    session_name('share_manager');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $admin = isset($_SESSION['admin_until']) && $_SESSION['admin_until'] > time();
    $message = $_SESSION['message'] ?? '';
    unset($_SESSION['message']);
    $error = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $json = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        try {
            $csrf = $_POST['csrf'] ?? '';
            if (!is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
                http_response_code(403);
                throw new InvalidArgumentException('页面已过期或请求无效，请刷新后重试。');
            }
            if ($path === '/manage/login') {
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                if (!is_string($username) || !is_string($password) || !manager_login($storage, $username, $password)) {
                    http_response_code(401);
                    throw new InvalidArgumentException('账号或密码不正确。');
                }
                session_regenerate_id(true);
                $_SESSION['admin_until'] = time() + 43200;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $message = '已登录，可以上传和管理文件。';
            } else {
                if (!$admin) {
                    http_response_code(403);
                    throw new InvalidArgumentException('请先登录管理账号。');
                }
                switch ($path) {
                    case '/manage/upload':
                        $upload = $_FILES['file'] ?? [];
                        if (!is_array($upload)) throw new InvalidArgumentException('请选择文件。');
                        $name = $store->upload($upload);
                        $message = '上传成功：' . $name;
                        break;
                    case '/manage/delete':
                        $name = $_POST['name'] ?? '';
                        if (!is_string($name)) throw new InvalidArgumentException('无效的文件名。');
                        $store->trash($name);
                        $message = '已删除：' . $name . '（已移入回收目录）';
                        break;
                    case '/manage/logout':
                        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
                        session_regenerate_id(true);
                        $message = '已退出管理。';
                        break;
                    default:
                        http_response_code(404);
                        throw new InvalidArgumentException('操作不存在。');
                }
            }
            $_SESSION['message'] = $message;
            session_write_close();
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'message' => $message], JSON_UNESCAPED_UNICODE);
            } else {
                header('Location: /', true, 303);
            }
            exit;
        } catch (InvalidArgumentException | OutOfBoundsException $exception) {
            if (http_response_code() < 400) http_response_code(400);
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Share manager: ' . $exception->getMessage());
            http_response_code(500);
            $error = '操作未完成，请稍后重试。';
        }
        if ($json) {
            session_write_close();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => $error], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    $state = ['admin' => $admin, 'csrf' => $_SESSION['csrf'], 'message' => $message, 'error' => $error];
    session_write_close();
    return $state;
}

function manager_login(string $storage, string $username, string $password): bool
{
    $directory = $storage . '/login-attempts';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create login protection directory.');
    }
    // Bound the number of rate-limit files even with changing client addresses.
    $bucket = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 3);
    $handle = fopen($directory . '/' . $bucket . '.json', 'c+');
    if (!$handle) throw new RuntimeException('Could not open login protection.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Could not lock login protection.');
        $data = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
        if (($data['until'] ?? 0) <= time()) $data = ['attempts' => 0, 'until' => time() + 900];
        if ($data['attempts'] >= 8) {
            http_response_code(429);
            throw new InvalidArgumentException('登录失败次数过多，请 15 分钟后重试。');
        }
        $config = json_decode(file_get_contents($storage . '/manager.json'), true, 512, JSON_THROW_ON_ERROR);
        $validPassword = password_verify($password, $config['password_hash']);
        $valid = hash_equals($config['username'], $username) && $validPassword;
        $data['attempts'] = $valid ? 0 : $data['attempts'] + 1;
        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, json_encode($data)) === false) throw new RuntimeException('Could not save login protection.');
        fflush($handle);
        return $valid;
    } finally {
        fclose($handle);
    }
}
