<?php
session_start();
error_reporting(E_ALL);

// Configuração do banco de dados SQLite
require_once 'database.php';

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];
    $nome_academia = trim($_POST['nome_academia']);

    if (empty($email) || empty($password) || empty($nome_academia)) {
        $error_message = "Todos os campos são obrigatórios.";
    } elseif ($password !== $password_confirm) {
        $error_message = "As senhas não coincidem.";
    } elseif (strlen($password) < 6) {
        $error_message = "A senha deve ter pelo menos 6 caracteres.";
    } else {
        // Verificar se o e-mail já existe
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM usuarios WHERE email = :email");
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row['count'] > 0) {
            $error_message = "Este e-mail já está cadastrado.";
        } else {
            // Verifica se este é o primeiro usuário a ser cadastrado.
            $total_users_stmt = $db->query("SELECT COUNT(*) as count FROM usuarios");
            $total_users = $total_users_stmt->fetchArray(SQLITE3_ASSOC)['count'];
            
            // Se for o primeiro usuário (admin), o status é 'aprovado', senão 'pendente'.
            $status_inicial = ($total_users == 0) ? 'aprovado' : 'pendente';
            // Se for o primeiro usuário, ele é o 'admin' principal.
            $tipo_usuario = ($total_users == 0) ? 'admin' : 'professor';

            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO usuarios (email, password_hash, nome_academia, status, tipo) VALUES (:email, :password, :nome, :status, :tipo)");
            $stmt->bindValue(':email', $email, SQLITE3_TEXT);
            $stmt->bindValue(':password', $password_hash, SQLITE3_TEXT);
            $stmt->bindValue(':nome', $nome_academia, SQLITE3_TEXT);
            $stmt->bindValue(':status', $status_inicial, SQLITE3_TEXT);
            $stmt->bindValue(':tipo', $tipo_usuario, SQLITE3_TEXT);

            if ($stmt->execute()) {
                $success_message = "Cadastro realizado com sucesso! Você já pode fazer o login.";
                $novo_usuario_id = $db->lastInsertRowID();

                // Inserir professores padrão para o novo usuário
                $professores_padrao = ['Professor A', 'Professor B'];
                $stmt_prof = $db->prepare("INSERT INTO professores (usuario_id, nome) VALUES (:uid, :nome)");
                $stmt_prof->bindValue(':uid', $novo_usuario_id, SQLITE3_INTEGER);
                foreach ($professores_padrao as $nome_prof) {
                    $stmt_prof->bindValue(':nome', $nome_prof, SQLITE3_TEXT);
                    $stmt_prof->execute();
                }
            } else {
                $error_message = "Ocorreu um erro ao realizar o cadastro. Tente novamente.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Sistema de Academia</title>
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
            --success: #00cc88;
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
        .register-container {
            width: 100%;
            max-width: 450px;
            padding: 2rem;
        }
        .register-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 2.5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 1px solid var(--card-border);
        }
        .register-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .register-header i {
            font-size: 3rem;
            color: var(--highlight);
        }
        .register-header h1 {
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
        .message {
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .error-message {
            background-color: rgba(255, 85, 116, 0.15);
            color: var(--danger);
            border: 1px solid rgba(255, 85, 116, 0.3);
        }
        .success-message {
            background-color: rgba(0, 204, 136, 0.15);
            color: var(--success);
            border: 1px solid rgba(0, 204, 136, 0.3);
        }
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
        }
        .login-link a {
            color: var(--highlight);
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <i class="fas fa-user-plus"></i>
                <h1>Crie sua Conta</h1>
            </div>

            <?php if ($error_message): ?>
                <div class="message error-message"><?php echo $error_message; ?></div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="message success-message"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="nome_academia">Nome da Academia</label>
                    <input type="text" id="nome_academia" name="nome_academia" required>
                </div>
                <div class="form-group">
                    <label for="email">E-mail</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Senha (mínimo 6 caracteres)</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirme a Senha</label>
                    <input type="password" id="password_confirm" name="password_confirm" required>
                </div>
                <button type="submit" class="btn">Cadastrar</button>
            </form>
            <div class="login-link">
                <p>Já tem uma conta? <a href="login.php">Faça o login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
