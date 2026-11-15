<?php
// Envia por e-mail (SMTP via PHPMailer) a planilha de pagamento dos intermitentes pro RH de
// Beneficios, com o .xlsx anexado. Servidor/conta vem do ambiente (SMTP_*); porta 465 = SMTPS.
// Destinatario fixo "beneficios@empresa.com.br" (regra de negocio, nao segredo - por
// isso direto no codigo).

require __DIR__ . '/db.php';
require __DIR__ . '/../lib/phpmailer/src/Exception.php';
require __DIR__ . '/../lib/phpmailer/src/PHPMailer.php';
require __DIR__ . '/../lib/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$pdo = conectarBanco();
$usuarioId = verificarSessao($pdo);
// So RH usa Beneficios; o servidor confirma de novo (acao real, nao confiar so na guarda client-side).
exigirPapel($pdo, $usuarioId, ['rh', 'administrador']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo nao permitido']);
    exit;
}

if (!isset($_FILES['planilha']) || $_FILES['planilha']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Planilha nao enviada corretamente']);
    exit;
}

if (empty(SMTP_HOST) || empty(SMTP_USER) || empty(SMTP_PASSWORD)) {
    http_response_code(500);
    echo json_encode(['error' => 'SMTP nao configurado neste ambiente (SMTP_HOST/SMTP_USER/SMTP_PASSWORD vazios)']);
    exit;
}

$mesReferencia = trim($_POST['mes_referencia'] ?? '');
$dataPagamento = trim($_POST['data_pagamento'] ?? '');

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASSWORD;
    $mail->Port = (int) SMTP_PORT;
    // 465 = SSL/TLS implicito (SMTPS); outras portas (ex: 587) usam STARTTLS obrigatorio.
    $mail->SMTPSecure = ((int) SMTP_PORT === 465) ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;    $mail->CharSet = 'UTF-8';

    $mail->setFrom(SMTP_USER, SMTP_FROM_NOME);
    $mail->addAddress('beneficios@empresa.com.br');

    $mail->Subject = 'Relatorio de Pagamento - Intermitentes' . ($mesReferencia !== '' ? ' - ' . $mesReferencia : '');
    $mail->isHTML(true);
    $mail->Body = '<p>Segue em anexo o relatorio de pagamento dos colaboradores intermitentes'
        . ($mesReferencia !== '' ? ' referente a <b>' . htmlspecialchars($mesReferencia) . '</b>' : '') . '.</p>'
        . ($dataPagamento !== '' ? '<p>Data de pagamento: <b>' . htmlspecialchars($dataPagamento) . '</b></p>' : '')
        . '<p>E-mail gerado automaticamente pelo CGV.</p>';
    $mail->AltBody = 'Segue em anexo o relatorio de pagamento dos colaboradores intermitentes.';

    $mail->addAttachment($_FILES['planilha']['tmp_name'], 'relatorio_pagamento_intermitentes.xlsx');

    $mail->send();
    echo json_encode(['success' => true]);
} catch (PHPMailerException $e) {
    error_log('enviar-email-intermitente: ' . $mail->ErrorInfo);
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao enviar o e-mail. Tente novamente em instantes.']);
} catch (\Throwable $e) {
    error_log('enviar-email-intermitente: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno ao enviar o e-mail. Tente novamente em instantes.']);
}
