<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\OcrExternoService;

$ocr = new OcrExternoService();
$assertions = 0;

$semChave = $ocr->testar('cloudmersive', null, 'placa');
if (($semChave['success'] ?? true) !== false || (int)($semChave['status'] ?? 0) !== 503) {
    throw new RuntimeException('OCR sem chave não foi bloqueado antes da chamada externa.');
}
++$assertions;

$tmp = tempnam(sys_get_temp_dir(), 'appauto_ocr_');
if ($tmp === false) {
    throw new RuntimeException('Não foi possível criar fixture temporária.');
}
file_put_contents($tmp, 'arquivo que não é imagem');
try {
    $ocr->analisarUpload([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => filesize($tmp),
        'name' => 'documento.txt',
    ], 'documento');
    throw new RuntimeException('Upload inválido não foi rejeitado.');
} catch (\RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'JPG')) {
        throw $e;
    }
}
++$assertions;
@unlink($tmp);

echo "service_validation: {$assertions} assertions passed\n";
