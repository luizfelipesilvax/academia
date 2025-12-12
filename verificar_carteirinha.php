 <?php
session_start(); // Necessário para a mensagem de confirmação

error_reporting(E_ALL);
ini_set('display_errors', 1);

$aluno = null;
$aluno_id = null;
$status_verificacao = 'INVÁLIDO';
$mensagem = 'O código QR é inválido ou não contém um ID de aluno.';
$nome_academia = 'Elite Martial Arts'; // Valor padrão
$confirmacao_presenca = '';

// Processar o formulário de presença se enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_presenca'])) {
    $aluno_id_presenca = $_POST['aluno_id'];
    $status_presenca = $_POST['status_presenca'];
    $usuario_id_aluno = $_POST['usuario_id_aluno'];

    date_default_timezone_set('America/Sao_Paulo'); // Garante o fuso horário correto
    $data_hoje = date('Y-m-d'); // Pega a data atual com o fuso horário definido

    require_once 'database.php';
    
    $stmt = $db->prepare("INSERT OR REPLACE INTO presencas (aluno_id, usuario_id, data_presenca, status) VALUES (:aluno_id, :usuario_id, :data_presenca, :status)");
    $stmt->bindValue(':aluno_id', $aluno_id_presenca, SQLITE3_TEXT);
    $stmt->bindValue(':usuario_id', $usuario_id_aluno, SQLITE3_INTEGER);
    $stmt->bindValue(':data_presenca', $data_hoje, SQLITE3_TEXT);
    $stmt->bindValue(':status', $status_presenca, SQLITE3_TEXT);
    
    if ($stmt->execute()) {
        $_SESSION['confirmacao_presenca'] = "Presença marcada como '{$status_presenca}' com sucesso!";
    }
    // Redireciona para a mesma página via GET para evitar reenvio do formulário
    header("Location: verificar_carteirinha.php?id=" . urlencode($aluno_id_presenca));
    exit();
}

// Verificar se o ID do aluno foi fornecido na URL
if (isset($_GET['id']) && ctype_alnum($_GET['id'])) { // ctype_alnum é uma boa validação
    $aluno_id = $_GET['id'];

    // Conectar ao banco de dados
    $db_path = 'academia.db';
    $db = new SQLite3($db_path);

    // Buscar dados do aluno e o ID do seu proprietário (usuário)
    $stmt = $db->prepare("SELECT a.*, p.nome as plano_nome FROM alunos a LEFT JOIN planos p ON a.plano_id = p.id WHERE a.id = :id");
    $stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
    $resultado = $stmt->execute();
    $aluno = $resultado->fetchArray(SQLITE3_ASSOC);

    // Buscar turmas do aluno
    $turmas_aluno = [];
    $stmt_turmas = $db->prepare("
        SELECT t.tipo, t.dias_semana, t.horario_inicio 
        FROM alunos_turmas at 
        JOIN turmas t ON at.turma_id = t.id
        WHERE at.aluno_id = :aluno_id
    ");
    $stmt_turmas->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
    $res_turmas = $stmt_turmas->execute();
    while($turma_row = $res_turmas->fetchArray(SQLITE3_ASSOC)) { $turmas_aluno[] = $turma_row; }


    // Buscar nome da academia
    $logo_path = '';
    $nome_academia = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'nome_academia' AND usuario_id = " . ($aluno['usuario_id'] ?? 0)) ?: $nome_academia;
    $logo_path_db = $db->querySingle("SELECT valor FROM configuracoes WHERE chave = 'logo_path' AND usuario_id = " . ($aluno['usuario_id'] ?? 0));
    if ($logo_path_db && file_exists($logo_path_db)) {
        $logo_path = $logo_path_db;
    }

    if ($aluno) {
        // Aluno encontrado, verificar o status
        if ($aluno['status'] === 'ativo') {
            $status_verificacao = 'VÁLIDO';
            $mensagem = 'Acesso liberado.';
        } elseif ($aluno['status'] === 'bloqueado') {
            $status_verificacao = 'BLOQUEADO';
            $mensagem = 'Acesso negado. Aluno bloqueado administrativamente.';
        } else {
            $status_verificacao = 'EXPIRADO';
            $mensagem = 'Acesso negado. Por favor, regularize sua situação.';
        }
    } else {
        // Aluno não encontrado
        $status_verificacao = 'INVÁLIDO';
        $mensagem = 'Nenhum aluno encontrado com este ID.';
    }
    $db->close();
}

// Verificar se há uma mensagem de confirmação na sessão
if (isset($_SESSION['confirmacao_presenca'])) {
    $confirmacao_presenca = $_SESSION['confirmacao_presenca'];
    unset($_SESSION['confirmacao_presenca']); // Limpa a mensagem para não aparecer novamente
}

$data_verificacao = date('d/m/Y H:i');
$status_class = strtolower($status_verificacao);
if ($status_class === 'bloqueado') $status_class = 'expirado'; // Reutiliza a cor vermelha

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Carteirinha</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #1a1a2e; --secondary: #16213e; --accent: #0f3460; --highlight: #e94560; --text: #f0f0f0; --text-secondary: #b0b0b0; --success: #00cc88; --warning: #ffaa00; --danger: #ff5574; --card-border: #2a3a5c; }
        body { font-family: 'Segoe UI', sans-serif; background-color: var(--primary); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 1rem; }
        .verification-card { background: var(--secondary); width: 100%; max-width: 400px; border-radius: 16px; text-align: center; border: 1px solid var(--card-border); box-shadow: 0 10px 30px rgba(0,0,0,0.3); overflow: hidden; }
        .confirmation-message { padding: 1rem; background-color: var(--success); color: white; font-weight: bold; text-align: center; }
        .status-header { padding: 2.5rem 1.5rem; transition: background-color 0.3s ease; }
        .status-header.valido { background-color: var(--success); }
        .status-header.bloqueado { background-color: var(--danger); }
        .status-header.expirado { background-color: var(--danger); }
        .status-header.invalido { background-color: var(--warning); }
        .status-header i { font-size: 4rem; color: white; }
        .status-header h1 { font-size: 2.2rem; font-weight: 700; color: white; margin: 1rem 0 0.5rem; text-transform: uppercase; }
        .status-header p { font-size: 1.1rem; color: white; opacity: 0.9; margin: 0; }
        .aluno-info { padding: 1.5rem; text-align: left; background-color: var(--primary); }
        .aluno-info p { margin: 0 0 1rem 0; font-size: 0.95rem; display: flex; justify-content: space-between; border-bottom: 1px solid var(--card-border); padding-bottom: 1rem; align-items: flex-start; }
        .aluno-info p:last-child { margin-bottom: 0; border-bottom: none; }
        .aluno-info .label { font-weight: 600; color: var(--text-secondary); }
        .aluno-info .value { font-weight: 500; text-align: right; }
        .presence-actions { padding: 0 1.5rem 1.5rem; display: flex; gap: 1rem; }
        .presence-actions .btn { flex-grow: 1; padding: 0.8rem; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .btn-presente { background-color: var(--success); color: white; }
        .btn-falta { background-color: var(--danger); color: white; }
        .btn:hover { opacity: 0.9; }
        .footer { padding: 1rem; font-size: 0.9rem; color: var(--text-secondary); display: flex; align-items: center; justify-content: space-between; }
        .footer-logo { display: flex; align-items: center; gap: 0.5rem; font-weight: bold; }
        .footer-logo img { max-height: 20px; filter: brightness(0) invert(1); }
        @media (max-width: 400px) {
            .status-header h1 { font-size: 1.8rem; }
            .status-header p { font-size: 1rem; }
        }
    </style>
</head>
<body>
    <div class="verification-card">
        <?php if ($confirmacao_presenca): ?>
            <div class="confirmation-message">
                <i class="fas fa-check-circle"></i> <?php echo $confirmacao_presenca; ?>
            </div>
        <?php endif; ?>

        <div class="status-header <?php echo $status_class; ?>">
            <?php if ($status_verificacao === 'VÁLIDO'): ?>
                <i class="fas fa-check-circle"></i>
            <?php elseif ($status_verificacao === 'EXPIRADO' || $status_verificacao === 'BLOQUEADO'): ?>
                <i class="fas fa-times-circle"></i>
            <?php else: ?>
                <i class="fas fa-question-circle"></i>
            <?php endif; ?>
            <h1><?php echo $status_verificacao; ?></h1>
            <p><?php echo $mensagem; ?></p>
        </div>

        <?php if ($aluno): ?>
        <div class="aluno-info">
            <p><span class="label">Nome</span><span class="value"><?php echo htmlspecialchars($aluno['nome_completo']); ?></span></p>
            <p><span class="label">Plano</span><span class="value"><?php echo htmlspecialchars($aluno['plano_nome'] ?? 'N/A'); ?></span></p>
            <p><span class="label">Vencimento</span><span class="value"><?php echo date('d/m/Y', strtotime($aluno['proximo_vencimento'])); ?></span></p>
            <?php if (!empty($turmas_aluno)): ?>
                <p>
                    <span class="label">Turmas</span>
                    <span class="value">
                        <?php 
                            $turmas_formatadas = [];
                            foreach($turmas_aluno as $turma) {
                                $turmas_formatadas[] = htmlspecialchars($turma['tipo'] . ' (' . $turma['dias_semana'] . ' ' . $turma['horario_inicio'] . ')');
                            }
                            echo implode('<br>', $turmas_formatadas);
                        ?>
                    </span>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($aluno && $aluno['status'] === 'ativo'): ?>
        <div class="presence-actions">
            <form method="POST" style="flex-grow: 1;">
                <input type="hidden" name="aluno_id" value="<?php echo $aluno_id; ?>">
                <input type="hidden" name="usuario_id_aluno" value="<?php echo $aluno['usuario_id']; ?>">
                <input type="hidden" name="status_presenca" value="presente">
                <button type="submit" name="marcar_presenca" class="btn btn-presente"><i class="fas fa-user-check"></i> Marcar Presença</button>
            </form>
            <form method="POST" style="flex-grow: 1;">
                <input type="hidden" name="aluno_id" value="<?php echo $aluno_id; ?>">
                <input type="hidden" name="usuario_id_aluno" value="<?php echo $aluno['usuario_id']; ?>">
                <input type="hidden" name="status_presenca" value="falta">
                <button type="submit" name="marcar_presenca" class="btn btn-falta"><i class="fas fa-user-times"></i> Marcar Falta</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="footer">
            <div class="footer-logo">
                <?php if ($logo_path): ?>
                    <img src="<?php echo $logo_path; ?>" alt="Logo">
                <?php endif; ?>
                <span><?php echo htmlspecialchars($nome_academia); ?></span>
            </div>
            <span><?php echo $data_verificacao; ?></span>
        </div>
    </div>
</body>
</html>