<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Exporter
{
    public static function html_to_pdf(string $html): array
    {
        $autoload = PDFW_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        if (! class_exists('\Dompdf\Dompdf')) {
            return [
                'ok' => false,
                'error' => 'Biblioteca PDF ausente. Rode `composer install` dentro do plugin para habilitar exportação PDF.',
                'content' => '',
            ];
        }

        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4');
            $dompdf->render();

            return [
                'ok' => true,
                'error' => '',
                'content' => $dompdf->output(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Falha ao gerar PDF: ' . $e->getMessage(),
                'content' => '',
            ];
        }
    }
}
