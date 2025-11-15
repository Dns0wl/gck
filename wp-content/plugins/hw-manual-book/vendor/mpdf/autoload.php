<?php
declare(strict_types=1);

if (class_exists('Mpdf\\Mpdf')) {
    return;
}

$vendorPath = __DIR__ . '/mpdf/mpdf.php';
if (file_exists($vendorPath)) {
    require_once $vendorPath;
    return;
}

// Minimal fallback notice to avoid fatal errors when the bundled package is missing.
class_alias('HWMB_Mpdf_Placeholder', 'Mpdf\\Mpdf');
class HWMB_Mpdf_Placeholder
{
    public function __construct(array $config = [])
    {
        if (function_exists('is_admin') && is_admin()) {
            add_action('admin_notices', static function () {
                echo '<div class="notice notice-error"><p>HW Manual Book membutuhkan paket mPDF lengkap. Silakan salin folder mpdf resmi ke wp-content/plugins/hw-manual-book/vendor/mpdf/mpdf/.</p></div>';
            });
        }
    }

    public function SetDisplayMode($mode): void {}
    public function SetTitle($title): void {}
    public function WriteHTML($html, $mode = 0): void {}
    public function Output($name = '', $dest = 'S')
    {
        return '';
    }
}
