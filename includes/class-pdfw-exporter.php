<?php

if (! defined('ABSPATH')) {
    exit;
}

class PDFW_Exporter
{
    public static function html_to_pdf(string $html): array
    {
        // Load only the first available Dompdf autoloader (prefer Composer)
        $autoload_candidates = [
            PDFW_PLUGIN_DIR . 'vendor/autoload.php',
            PDFW_PLUGIN_DIR . 'lib/dompdf/autoload.inc.php',
        ];
        foreach ($autoload_candidates as $autoload) {
            if (file_exists($autoload)) {
                require_once $autoload;
                break;
            }
        }

        if (! class_exists('\Dompdf\Dompdf')) {
            return [
                'ok' => false,
                'error' => 'Biblioteca PDF ausente. Instale via Composer ou inclua `lib/dompdf` no plugin.',
                'content' => '',
            ];
        }

        try {
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('isHtml5ParserEnabled', true);
            if (defined('ABSPATH')) {
                $options->setChroot(wp_normalize_path((string) ABSPATH));
            }

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
