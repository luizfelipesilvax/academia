<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Gera uma imagem de QR Code para um aluno e a salva em um arquivo temporário.
 *
 * @param int $aluno_id O ID do aluno.
 * @return string|null O caminho para o arquivo PNG temporário ou null em caso de falha.
 */
function gerarQrCodeComoArquivo($aluno_id) {
    // Monta a URL que será embutida no QR Code
    $url_verificacao = 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/verificar_carteirinha.php?id=' . $aluno_id;

    // Define o caminho do arquivo temporário
    $caminho_temporario = sys_get_temp_dir() . '/qrcode_' . uniqid() . '.png';

    try {
        // Gera o QR Code e salva diretamente no arquivo
        (new \chillerlan\QRCode\QRCode)->render($url_verificacao, $caminho_temporario);
        return $caminho_temporario;
    } catch (\Exception $e) {
        error_log('Erro ao gerar QR Code: ' . $e->getMessage());
        return null;
    }
}

?>