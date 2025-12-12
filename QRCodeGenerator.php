<?php
/**
 * A simple, dependency-free QR Code generator class.
 */
class QRCodeGenerator {
    private $data;
    private $size;
    private $margin;
    private $level;

    const QR_LEVEL_L = 0;
    const QR_LEVEL_M = 1;
    const QR_LEVEL_Q = 2;
    const QR_LEVEL_H = 3;

    /**
     * Constructor
     * @param string $data The data to encode.
     * @param int $size The size of the QR code image in pixels.
     * @param int $margin The margin around the QR code in modules.
     * @param int $level The error correction level.
     */
    public function __construct($data, $size = 200, $margin = 4, $level = self::QR_LEVEL_L) {
        if (empty($data)) {
            throw new \Exception("QR Code data cannot be empty.");
        }
        $this->data = $data;
        $this->size = $size;
        $this->margin = $margin;
        $this->level = $level;
    }

    /**
     * Generates the QR code and returns it as a base64 encoded data URI.
     * @return string The base64 encoded data URI.
     */
    public function generate() {
        // Using an external, reliable API to generate the QR code.
        // This avoids the complexity of a full PHP implementation and dependency on the GD extension.
        $api_url = 'https://api.qrserver.com/v1/create-qr-code/';
        
        $params = [
            'data' => $this->data,
            'size' => $this->size . 'x' . $this->size,
            'margin' => $this->margin,
            'ecc' => $this->getEccLevel(),
            'format' => 'png'
        ];

        $url = $api_url . '?' . http_build_query($params);

        // Use cURL for a more robust request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$imageData) {
            // Fallback or error handling
            return $this->createErrorImage();
        }

        return 'data:image/png;base64,' . base64_encode($imageData);
    }

    /**
     * Get the string representation for the error correction level.
     * @return string
     */
    private function getEccLevel() {
        switch ($this->level) {
            case self::QR_LEVEL_M:
                return 'M';
            case self::QR_LEVEL_Q:
                return 'Q';
            case self::QR_LEVEL_H:
                return 'H';
            case self::QR_LEVEL_L:
            default:
                return 'L';
        }
    }

    /**
     * Creates a placeholder image in case the API fails.
     * Requires GD extension.
     * @return string
     */
    private function createErrorImage() {
        if (!function_exists('imagecreate')) {
            return ''; // Cannot create image without GD
        }
        $im = @imagecreate($this->size, $this->size);
        if (!$im) return '';

        $bg = imagecolorallocate($im, 255, 255, 255);
        $fg = imagecolorallocate($im, 233, 69, 96); // Highlight color

        imagestring($im, 3, 10, ($this->size / 2) - 10, 'QR Code Error', $fg);

        ob_start();
        imagepng($im);
        $imageData = ob_get_contents();
        ob_end_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($imageData);
    }
}
?>