<?php
session_start();
header('Content-Type: application/json');

// Garante que apenas o admin principal (ID 1) possa acessar este endpoint.
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_id'] != 1) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Acesso não autorizado.']);
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID do usuário inválido.']);
    exit;
}

$usuario_id = intval($_GET['id']);

require_once 'database.php';

$stmt = $db->prepare("SELECT modulo FROM usuario_permissoes WHERE usuario_id = :uid");
$stmt->bindValue(':uid', $usuario_id, SQLITE3_INTEGER);
$res = $stmt->execute();
$permissoes = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $permissoes[] = $row['modulo']; }

$db->close();
echo json_encode($permissoes);