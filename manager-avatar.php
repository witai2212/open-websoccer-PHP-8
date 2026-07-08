<?php
/******************************************************

  Dynamic SVG manager avatar generated from avatar_key.

******************************************************/

$key = isset($_GET['key']) ? (string) $_GET['key'] : 'manager';
$initials = isset($_GET['initials']) ? (string) $_GET['initials'] : 'M';
$key = preg_replace('/[^a-zA-Z0-9_.-]/', '', $key);
if ($key === '') {
    $key = 'manager';
}
$initials = preg_replace('/[^\p{L}\p{N}]/u', '', $initials);
if ($initials === '') {
    $initials = 'M';
}
if (function_exists('mb_substr')) {
    $initials = mb_substr($initials, 0, 2, 'UTF-8');
    $initials = mb_strtoupper($initials, 'UTF-8');
} else {
    $initials = strtoupper(substr($initials, 0, 2));
}

$hash = sha1($key);
function avatar_hex($hash, $offset) {
    return '#' . substr($hash, $offset, 6);
}
function avatar_int($hash, $offset, $min, $max) {
    $value = hexdec(substr($hash, $offset, 2));
    return $min + ($value % (($max - $min) + 1));
}

$bg = avatar_hex($hash, 0);
$jacket = avatar_hex($hash, 6);
$shirt = avatar_hex($hash, 12);
$skinOptions = array('#f1c27d', '#e0ac69', '#c68642', '#8d5524', '#ffdbac');
$hairOptions = array('#2c1b18', '#5a3825', '#8b5a2b', '#d6a85c', '#3b3024', '#6f4e37');
$skin = $skinOptions[avatar_int($hash, 18, 0, count($skinOptions) - 1)];
$hair = $hairOptions[avatar_int($hash, 20, 0, count($hairOptions) - 1)];
$eyeY = avatar_int($hash, 22, 57, 61);
$mouthY = avatar_int($hash, 24, 79, 84);
$bg2 = avatar_hex($hash, 26);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=604800');

$initialsEscaped = htmlspecialchars($initials, ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96" role="img" aria-label="Manager Avatar">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="<?php echo $bg; ?>"/>
      <stop offset="1" stop-color="<?php echo $bg2; ?>"/>
    </linearGradient>
  </defs>
  <rect width="96" height="96" rx="16" fill="url(#bg)"/>
  <circle cx="48" cy="45" r="25" fill="<?php echo $skin; ?>"/>
  <path d="M24 43c2-19 15-28 31-24 11 3 17 11 18 24-7-8-14-12-25-12-10 0-18 4-24 12z" fill="<?php echo $hair; ?>"/>
  <circle cx="38" cy="<?php echo $eyeY; ?>" r="2.5" fill="#1d1d1d"/>
  <circle cx="58" cy="<?php echo $eyeY; ?>" r="2.5" fill="#1d1d1d"/>
  <path d="M39 <?php echo $mouthY; ?>c5 5 13 5 18 0" fill="none" stroke="#6b3328" stroke-width="3" stroke-linecap="round"/>
  <path d="M17 96c5-22 17-32 31-32s26 10 31 32z" fill="<?php echo $jacket; ?>"/>
  <path d="M36 66l12 16 12-16v30H36z" fill="<?php echo $shirt; ?>" opacity="0.92"/>
  <circle cx="75" cy="22" r="14" fill="rgba(255,255,255,0.88)"/>
  <text x="75" y="27" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="13" font-weight="700" fill="#333"><?php echo $initialsEscaped; ?></text>
</svg>
