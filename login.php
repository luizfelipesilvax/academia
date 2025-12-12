<?php
session_start();
error_reporting(E_ALL);

// Configuração do banco de dados SQLite
require_once 'database.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error_message = "Por favor, preencha o e-mail e a senha.";
    } else {
        $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = :email");
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $stmt->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);

        $log_file = __DIR__ . '/debug_login.log';
        file_put_contents($log_file, "Login attempt for email: " . $email . "\n", FILE_APPEND);
        file_put_contents($log_file, "Password provided: " . $password . "\n", FILE_APPEND);
        file_put_contents($log_file, "Stored hash: " . ($user['password_hash'] ?? 'N/A') . "\n", FILE_APPEND);
        $password_match = password_verify($password, $user['password_hash'] ?? '');
        file_put_contents($log_file, "Password verify result: " . ($password_match ? 'true' : 'false') . "\n", FILE_APPEND);

        if ($user && $password_match) {
            if ($user['status'] !== 'aprovado') {
                $error_message = "Seu cadastro está pendente de aprovação.";
            } else {
            $_SESSION['usuario_id'] = $user['id'];
            $_SESSION['nome_academia'] = $user['nome_academia'];
            header("Location: index.php");
            exit();
            }
        } else {
            $error_message = "E-mail ou senha inválidos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Sistema de Academia</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1a1a2e;
            --secondary: #16213e;
            --accent: #0f3460;
            --highlight: #e94560;
            --text: #f0f0f0;
            --card-bg: #1e2a47;
            --card-border: #2a3a5c;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--primary);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 2rem;
        }
        .login-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 1px solid var(--card-border);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header i {
            font-size: 3rem;
            color: var(--highlight);
        }
        .login-header h1 {
            font-size: 1.8rem;
            margin-top: 0.5rem;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-secondary);
        }
        input {
            width: 100%;
            padding: 0.8rem 1rem;
            border-radius: 6px;
            border: 1px solid var(--card-border);
            background-color: var(--secondary);
            color: var(--text);
            font-size: 1rem;
            box-sizing: border-box;
        }
        .btn {
            background: linear-gradient(45deg, var(--accent), var(--highlight));
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            width: 100%;
            font-size: 1rem;
            margin-top: 1rem;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .error-message {
            background-color: rgba(255, 85, 116, 0.15);
            color: var(--danger);
            border: 1px solid rgba(255, 85, 116, 0.3);
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .register-link a {
            color: var(--highlight);
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-fist-raised"></i>
                <h1>Acessar Sistema</h1>
            </div>

            <?php if ($error_message): ?>
                <div class="error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Senha</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn">Entrar</button>
            </form>
            <div class="register-link">
                <p>Não tem uma conta? <a href="register.php">Cadastre-se</a></p>
            </div>
        </div>
    </div>
</body>
</html>
