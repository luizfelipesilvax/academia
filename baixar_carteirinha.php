<?php
session_start();

// Incluir os arquivos necessários
require_once 'vendor/autoload.php';
require_once 'gerar_pdf_carteirinha.php';

// Garante que apenas usuários logados possam baixar o PDF.
if (!isset($_SESSION['usuario_id'])) {
    header("Location: login.php");
    exit;
}

// Verificar se o ID do aluno foi fornecido
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo "ID do aluno inválido.";
    exit;
}

$aluno_id = (int)$_GET['id'];

// Gerar o PDF e obter o caminho do arquivo temporário
$caminho_pdf = gerarPdfCarteirinha($aluno_id);

if ($caminho_pdf && file_exists($caminho_pdf)) {
    // Definir os cabeçalhos para forçar o download
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="carteirinha-aluno-' . $aluno_id . '.pdf"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($caminho_pdf));
    readfile($caminho_pdf); // Lê o arquivo e o envia para o output
    unlink($caminho_pdf); // Apaga o arquivo temporário após o download
    exit;
} else {
    http_response_code(500);
    echo "Erro ao gerar o PDF da carteirinha.";
    exit;
}