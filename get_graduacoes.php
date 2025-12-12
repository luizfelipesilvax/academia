<?php
session_start();
header('Content-Type: application/json');

// Garante que apenas usuários logados possam acessar.
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso não autorizado.']);
    exit;
}

// Verifica se o ID da modalidade foi fornecido.
if (!isset($_GET['modalidade_id']) || !is_numeric($_GET['modalidade_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID da modalidade inválido.']);
    exit;
}

$modalidade_id = intval($_GET['modalidade_id']);
$usuario_id_logado = $_SESSION['usuario_id'];

// Conectar ao banco de dados
require_once 'database.php';

// Buscar graduações para a modalidade específica do usuário logado.
// A verificação de usuario_id não é necessária aqui, pois as graduações são globais por modalidade.
$stmt = $db->prepare("
    SELECT id, nome, ordem 
    FROM graduacoes 
    WHERE modalidade_id = :modalidade_id ORDER BY ordem, nome
");
$stmt->bindValue(':modalidade_id', $modalidade_id, SQLITE3_INTEGER);

$resultado = $stmt->execute();
$graduacoes = [];
while ($row = $resultado->fetchArray(SQLITE3_ASSOC)) {
    $graduacoes[] = $row;
}

$db->close();
echo json_encode($graduacoes);
?>