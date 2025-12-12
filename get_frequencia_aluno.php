<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso não autorizado.']);
    exit;
}

if (!isset($_GET['aluno_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do aluno não fornecido.']);
    exit;
}

$aluno_id = $_GET['aluno_id'];
$usuario_id_logado = $_SESSION['usuario_id'];

require_once 'database.php';

// 1. Buscar os dias de treino do aluno a partir da sua turma
$stmt_aluno = $db->prepare("
    SELECT t.dias_semana, a.data_inicio
    FROM alunos a
    JOIN turmas t ON a.turma_id = t.id
    WHERE a.id = :aluno_id AND a.usuario_id = :usuario_id
");
$stmt_aluno->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
$stmt_aluno->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);
$resultado_aluno = $stmt_aluno->execute();
$aluno_info = $resultado_aluno->fetchArray(SQLITE3_ASSOC);

// 2. Buscar os registros de presença
$stmt_presenca = $db->prepare("
    SELECT data_presenca, status
    FROM presencas
    WHERE aluno_id = :aluno_id AND usuario_id = :usuario_id
");
$stmt_presenca->bindValue(':aluno_id', $aluno_id, SQLITE3_TEXT);
$stmt_presenca->bindValue(':usuario_id', $usuario_id_logado, SQLITE3_INTEGER);

$resultado_presenca = $stmt_presenca->execute();
$registros_presenca = [];
while ($row = $resultado_presenca->fetchArray(SQLITE3_ASSOC)) {
    $registros_presenca[] = $row;
}

$db->close();

echo json_encode([
    'dias_treino' => $aluno_info['dias_semana'] ?? '',
    'data_inicio' => $aluno_info['data_inicio'] ?? null,
    'registros' => $registros_presenca
]);
?>