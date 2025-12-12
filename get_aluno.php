<?php
session_start();

header('Content-Type: application/json');

// Garante que apenas usuários logados possam acessar este endpoint.
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Acesso não autorizado.']);
    exit;
}

// Verificar se o ID do aluno foi fornecido
if (!isset($_GET['id']) || !ctype_alnum($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do aluno inválido.']);
    exit;
}

$aluno_id = $_GET['id'];
$usuario_id_logado = $_SESSION['usuario_id'];

// Conectar ao banco de dados
$db_path = 'academia.db';
$db = new SQLite3($db_path);

// Buscar todos os dados do aluno, garantindo que ele pertença ao usuário logado.
$stmt = $db->prepare("SELECT * FROM alunos WHERE id = :id AND usuario_id = :usuario_id");
$stmt->bindValue(':id', $aluno_id, SQLITE3_TEXT);
$stmt->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$resultado = $stmt->execute();
$aluno = $resultado->fetchArray(SQLITE3_ASSOC);

if ($aluno) {
    // Buscar as graduações do aluno
    $aluno['graduacoes'] = [];
    $stmt_grad = $db->prepare("SELECT graduacao_id FROM alunos_graduacoes WHERE aluno_id = :aluno_id");
    $stmt_grad->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
    $resultado_grad = $stmt_grad->execute();
    while ($row = $resultado_grad->fetchArray(SQLITE3_ASSOC)) {
        $aluno['graduacoes'][] = $row['graduacao_id'];
    }

    // Buscar as turmas do aluno
    $aluno['turmas'] = [];
    $stmt_turmas = $db->prepare("SELECT turma_id FROM alunos_turmas WHERE aluno_id = :aluno_id");
    $stmt_turmas->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
    $res_turmas = $stmt_turmas->execute();
    while ($row = $res_turmas->fetchArray(SQLITE3_ASSOC)) {
        $aluno['turmas'][] = $row['turma_id'];
    }
}

$db->close();

echo json_encode($aluno);
?>