<?php

// Dossier des faces
$facesDir = __DIR__ . '/faces';
if (!is_dir($facesDir)) {
    @mkdir($facesDir, 0775, true);
}

// Sanitize user (évite les caractères dangereux dans le nom de fichier)
$user = isset($_POST['user']) ? preg_replace('~[^a-zA-Z0-9._-]+~', '_', $_POST['user']) : '';
if ($user === '') {
    http_response_code(400);
    exit('Invalid user');
}

$file = $facesDir . '/' . $user . '.png';

// --- RESET (supprimer la photo utilisateur) -------------------------------
if (isset($_POST['reset'])) {
    if (is_file($file)) {
        @unlink($file);
    }
    clearstatcache(true, $file);
    // Réponse simple ; côté client, mets l’avatar sur faces/default.png?ts=...
    exit('reset ok');
}

// --- UPLOAD (base64 PNG depuis Croppie) -----------------------------------
$img = $_POST['img'] ?? '';
if (strpos($img, 'data:image/png;base64,') !== 0) {
    http_response_code(400);
    exit('Invalid image data');
}
// Extraire la charge utile et décoder
$payload = substr($img, strpos($img, ',') + 1);
$data = base64_decode($payload);
if ($data === false) {
    http_response_code(400);
    exit('Base64 decode failed');
}

// Créer l’image GD
$im = @imageCreateFromString($data);
if (!$im) {
    http_response_code(400);
    exit('Invalid image');
}

// Tes traitements
imagefilter($im, IMG_FILTER_GRAYSCALE);
imagesavealpha($im, true);

// Sauvegarde PNG
$success = imagepng($im, $file);
imagedestroy($im);

clearstatcache(true, $file);

// Réponse identique à ta version initiale
echo $success ? $file : 'Unable to save the file.';

?>
