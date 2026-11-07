<?php

namespace App\Billing;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PdfRenderer
{
    public function render(array $data): string
    {
        return $this->renderView('documents.billing', $data);
    }

    public function renderView(string $view, array $data): string
    {
        if (! is_file(public_path('images/cgms-logo.png'))) {
            throw new RuntimeException('The billing logo is missing. Restore public/images/cgms-logo.png before rendering documents.');
        }
        $directory = storage_path('app/private/pdf');
        File::ensureDirectoryExists($directory);
        $options = new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'defaultMediaType' => 'print',
            'chroot' => public_path('images'),
            'tempDir' => $directory,
            'fontCache' => $directory,
        ]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('letter');
        $pdf->loadHtml(view($view, [...$data, 'pdf' => true])->render(), 'UTF-8');
        $pdf->render();
        $bytes = $pdf->output();
        if (! str_starts_with($bytes, '%PDF-') || ! str_contains($bytes, '%%EOF')) {
            throw new RuntimeException('PDF rendering did not produce a complete document.'.($view === 'documents.billing' ? ' No invoice was saved.' : ''));
        }

        return $bytes;
    }
}
