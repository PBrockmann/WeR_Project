<?php
session_start();

/* ----------------------------------------------------------------------------- */
function email_local_part(string $email): string {
    $local = preg_replace('/@.*/','',$email);
    $safe  = preg_replace('/[^A-Za-z0-9_.-]/','', (string)$local);
    return $safe !== '' ? $safe : 'user';
}
function json_path(string $baseDir, string $email): string {
    return rtrim($baseDir,'/').'/'.email_local_part($email).'.json';
}
function ensure_dir(string $dir): void { if (!is_dir($dir)) { @mkdir($dir, 0775, true); } }

function safe_lower(string $s): string {
    if (function_exists('mb_strtolower')) { return mb_strtolower($s, 'UTF-8'); }
    return strtolower($s);
}
function load_seed_keywords(string $seedFile): array {
    if (!is_file($seedFile)) return [];
    $lines = file($seedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_values(array_map('trim', $lines));
}
function scan_all_user_keywords(string $baseDir): array {
    $collected = [];
    if (!is_dir($baseDir)) return $collected;
    $files = glob(rtrim($baseDir,'/').'/*.json', GLOB_NOSORT) ?: [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f); if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (!empty($j['keywords']) && is_array($j['keywords'])) {
            foreach ($j['keywords'] as $kw) { $kw = trim((string)$kw); if ($kw !== '') $collected[] = $kw; }
        }
    }
    return $collected;
}
function merge_all_keywords(array $seed, array $scanned, array $user): array {
    $all = array_merge($seed, $scanned, $user);
    $seen = []; $uniq = [];
    foreach ($all as $k) {
        $k = trim((string)$k); if ($k === '') continue;
        $low = safe_lower($k);
        if (!isset($seen[$low])) { $seen[$low] = true; $uniq[] = $k; }
    }
    usort($uniq, function($a,$b){ return safe_lower($a) <=> safe_lower($b); });
    return $uniq;
}
function scan_all_participants(string $baseDir): array {
    $collected = [];
    if (!is_dir($baseDir)) return $collected;
    $files = glob(rtrim($baseDir, '/').'/*.json', GLOB_NOSORT) ?: [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (empty($j['infos']) || !is_array($j['infos'])) continue;
        $firstname = trim($j['infos']['firstname'] ?? '');
        $lastname  = trim($j['infos']['lastname'] ?? '');
        // Construire participant = "Lastname Firstname"
        if ($firstname !== '' || $lastname !== '') {
            $participant = trim($lastname.' '.$firstname);
            if ($participant !== '') {
                $collected[] = $participant;
            }
        }
    }
    $collected = array_unique($collected);
    usort($collected, function($a,$b){ return safe_lower($a) <=> safe_lower($b); });
    return $collected;
}
function scan_all_institutions(string $baseDir): array {
    $collected = [];
    if (!is_dir($baseDir)) return $collected;
    $files = glob(rtrim($baseDir, '/').'/*.json', GLOB_NOSORT) ?: [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $j = json_decode($raw, true);
        if (empty($j['infos']) || !is_array($j['infos'])) continue;
        $institution = trim($j['infos']['institution'] ?? '');
        if ($institution !== '') {
            $collected[] = $institution;
        }
    }
    $collected = array_unique($collected);
    usort($collected, function($a,$b){ return safe_lower($a) <=> safe_lower($b); });
    return $collected;
}
function load_user_json(string $path): array {
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) { $j = json_decode($raw, true); if (is_array($j)) return $j; }
    }
    return [];
}

/* ----------------------------------------------------------------------------- */
if (empty($_SESSION['auth_email'])) { header('Location: login.php'); exit; }

/* ----------------------------------------------------------------------------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
function check_csrf(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(400); exit('Invalid CSRF token.'); }
}

/* ----------------------------------------------------------------------------- */
$USERS_DIR = __DIR__ . '/users';
$FACES_DIR = __DIR__ . '/faces';
$SEED_FILE = __DIR__ . '/conf/seed_keywords.txt';

ensure_dir($USERS_DIR);
ensure_dir($FACES_DIR);

/* ----------------------------------------------------------------------------- */
$email = $_SESSION['auth_email'];
$login = email_local_part($email);

/* ----------------------------------------------------------------------------- */
$user_file = json_path($USERS_DIR, $email);
$face_file   = $FACES_DIR . '/' . $login . '.png';

$all_json = is_file($user_file) ? load_user_json($user_file) : [];
$gdprAccepted = !empty($all_json['gdpr']['accepted']);

$face_exists = is_file($face_file);
$face_url = 'faces/'.$login.'.png';

$current_firstname   = isset($all_json['infos']['firstname'])   ? (string)$all_json['infos']['firstname']   : '';
$current_lastname   = isset($all_json['infos']['lastname'])   ? (string)$all_json['infos']['lastname']   : '';
$current_institution   = isset($all_json['infos']['institution'])   ? (string)$all_json['infos']['institution']   : '';
$current_phone = isset($all_json['infos']['phone']) ? (string)$all_json['infos']['phone'] : '';

/* ----------------------------------------------------------------------------- */
$current_keywords = array_values(array_filter(array_map('strval', $all_json['keywords'] ?? [])));
$seed_keywords    = load_seed_keywords($SEED_FILE);
$scanned_keywords = scan_all_user_keywords($USERS_DIR);
$all_keywords_for_select = merge_all_keywords($seed_keywords, $scanned_keywords, $current_keywords);
$scanned_participants = scan_all_participants($USERS_DIR);
$scanned_institutions = scan_all_institutions($USERS_DIR);

/* ----------------------------------------------------------------------------- */
if (($_POST['step'] ?? '') === 'gdpr_accept') {
    check_csrf();

    $accept = !empty($_POST['gdpr_accept']); // 1 si Agree, 0 si Decline

    if ($accept === true && is_file($user_file)) {
        // Simple PRG
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
        exit;
    }

    if ($accept === false) {
        // Purge maximale
        if (is_file($face_file)) { @unlink($face_file); clearstatcache(true, $face_file); }
        if (is_file($user_file)) { @unlink($user_file); clearstatcache(true, $user_file); }

        // PRG
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
        exit;
    }

    $all_json['email']      = $email;
    $all_json['updated_at'] = date('c');
    $all_json['gdpr'] = [
        'accepted' => true,
        'when'     => date('c'),
    ];
    $all_json['infos']   = [
        'firstname' => '',
        'lastname' => '',
        'institution' => '',
        'phone' => '',
    ];
    $all_json['keywords']   = [];

    @file_put_contents($user_file, json_encode($all_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $payload = json_encode($all_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (file_put_contents($user_file, $payload, LOCK_EX) === false) {
        http_response_code(500);
        exit('GDPR save failed');
    }
    clearstatcache(true, $user_file);

    // PRG
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

/* -------- Save keywords -------- */
if (($_POST['step'] ?? '') === 'save_keywords') {
    if (!$gdprAccepted || !is_file($user_file)) {
        http_response_code(403);
        exit('GDPR not accepted');
    }
    check_csrf();

    $all_json = load_user_json($user_file);

    // 1) clean + dedupe
    $posted = isset($_POST['keywords']) && is_array($_POST['keywords']) ? $_POST['keywords'] : [];
    $clean  = [];
    foreach ($posted as $k) {
        $k = trim((string)$k);
        if ($k !== '') $clean[] = $k;
    }
    // déduplique en ignorant la casse
    $seen = [];
    $uniq = [];
    foreach ($clean as $k) {
        $low = safe_lower($k);
        if (!isset($seen[$low])) { $seen[$low] = true; $uniq[] = $k; }
    }

    // 2) tri alphabétique naturel, insensible à la casse
    usort($uniq, function($a,$b){ return safe_lower($a) <=> safe_lower($b); });

    // 3) maj JSON
    $all_json['email']      = $email;
    $all_json['updated_at'] = date('c');
    $all_json['keywords']   = $uniq;

    // 4) écriture robuste + flush métadonnées
    $payload = json_encode($all_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $ok = file_put_contents($user_file, $payload, LOCK_EX);
    if ($ok === false) {
        $err = error_get_last();
        http_response_code(500);
        exit('Save failed: '.htmlspecialchars($err['message'] ?? 'unknown', ENT_QUOTES, 'UTF-8'));
    }
    clearstatcache(true, $user_file);

    // 5) exposer à la suite le nouvel état utilisateur
    $current_keywords = $uniq;

    $scanned_keywords = scan_all_user_keywords($USERS_DIR);
    $all_keywords_for_select = merge_all_keywords($seed_keywords, $scanned_keywords, $current_keywords);

}

/* -------- Save infos -------- */
if (($_POST['step'] ?? '') === 'save_infos') {
    if (!$gdprAccepted || !is_file($user_file)) {
        http_response_code(403);
        exit('GDPR not accepted');
    }
    check_csrf();
    $all_json = load_user_json($user_file);
    $firstname   = trim((string)($_POST['firstname']   ?? ''));
    $lastname   = trim((string)($_POST['lastname']   ?? ''));
    $institution   = trim((string)($_POST['institution']   ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));

    $all_json['updated_at'] = date('c');
    $all_json['infos']['firstname']   = $firstname;
    $all_json['infos']['lastname']   = $lastname;
    $all_json['infos']['institution']   = $institution;
    $all_json['infos']['phone'] = $phone;

    @file_put_contents($user_file, json_encode($all_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod($user_file, 0644);

    $current_firstname=$firstname; 
    $current_lastname=$lastname; 
    $current_institution=$institution; 
    $current_phone=$phone;

    $scanned_participants = scan_all_participants($USERS_DIR);
    $scanned_institutions = scan_all_institutions($USERS_DIR);
}

?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>WeR_Project</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.css">

<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">

<script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css">

<script src="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/js/jquery.dataTables.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/datatables/1.10.21/css/jquery.dataTables.min.css">

<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>

<script src="d3_graph.js"></script>
<link rel="stylesheet" href="d3_graph.css">

<style>
.face1 {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  object-fit: cover;
  border: 4px solid #ccc;
  cursor: pointer
}
.select2-selection__choice {
  border-radius: 12px;
  padding: 2px 8px;
}
.container { 
  margin-left: 0;
}
.panel-title .glyphicon { 
  margin-left: 6px; 
}
#infosForm .control-label {
  text-align: left; 
}
#tabSearch .select2-container .select2-selection--multiple,
#tabMyKeywords .select2-container .select2-selection--multiple,
#keywords .select2-container .select2-selection--multiple,
.keywords .select2-container .select2-selection--multiple {
  min-height: 250px;
}
#tabSearch .select2-container .select2-selection--multiple .select2-selection__rendered,
#tabMyKeywords .select2-container .select2-selection--multiple .select2-selection__rendered,
#keywords .select2-container .select2-selection--multiple .select2-selection__rendered,
.keywords .select2-container .select2-selection--multiple .select2-selection__rendered {
  max-height: 250px;
  overflow-y: auto;
  overflow-x: hidden;
}
.viz-wrapper {
  position: relative;
  width: 100%;        /* prend toute la largeur de la col-sm-8 */
  max-width: 720px;   /* limite haute ~ ton ancien 720px */
  margin: 10px 0;
}
.viz-wrapper::before {
  content: "";
  display: block;
  padding-top: 100%;  /* 100% => carré (1:1) */
}
#viz {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  border: 1px solid #ddd;
  border-radius: 4px;
}
#viz .muted {
  opacity: 0.7;
  font-style: italic;
}
#vizOpen {
  position: absolute;
  top: 5px;
  right: 5px;
  width: 20px;
  height: 20px;
  cursor: pointer;
  z-index: 10;
}
#deletePhotoBtn {
  position: absolute;
  top: -4px;
  right: -4px;
  width: 24px;
  height: 24px;
  cursor: pointer;
  color: darkgray;
}
#photoContainer div {
  opacity: 0;
  transition: opacity 0.2s;
}
#photoContainer:hover div {
  opacity: 1;
}
#usersTableDialog {
  width: 1280px !important;
}
</style>

</head>
<body>

<div class="container" style="max-width:1380px;">
  <div class="page-header">
    <h1 class="h3">
      Welcome, <?= htmlspecialchars($email) ?>
      <small style="display:block;margin-top:6px;">
        <a href="login.php?logout=1">Log out</a>
        · <a href="#" id="openGdpr">Privacy settings</a>
      </small>
    </h1>
  </div>

  <div class="row">
    <!-- LEFT -->
    <div class="col-sm-4">

      <!-- My photo -->
      <div class="panel panel-default">
        <div class="panel-heading"><strong>My photo</strong></div>
        <div class="panel-body text-center">
         <div id="photoContainer" style="position:relative; display:inline-block;">
          <img id="userPhoto" class="face1" src="<?= $face_exists ? htmlspecialchars($face_url.'?'.time()) : 'faces/default.png' ?>" alt="User face">
          <div id="deletePhotoBtn" title="Remove photo">
             <span class="glyphicon glyphicon-remove"></span>
          </div>
          <input id="fileInput" type="file" accept="image/*" style="display:none;">
        </div>
       </div>
      </div>

      <!-- Accordion group for My infos (Bootstrap standard, collapsed by default) -->
      <div class="panel-group" id="accordionLeft" role="tablist" aria-multiselectable="true">

        <!-- My infos (collapse) -->
        <div class="panel panel-default">
          <div class="panel-heading" role="tab" id="headingInfos">
            <strong>
              <a class="collapsed" role="button" data-toggle="collapse" data-parent="#accordionLeft" style="color: #333;"
                 href="#infosCollapse" aria-expanded="false" aria-controls="infosCollapse">
                My infos
                <span class="glyphicon glyphicon-chevron-right pull-right" aria-hidden="true"></span>
              </a>
            </strong>
          </div>
          <div id="infosCollapse" class="panel-collapse collapse" role="tabpanel" aria-labelledby="headingInfos">
            <div class="panel-body">
              <form id="infosForm" method="post" class="form-horizontal">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="step" value="save_infos">

                <div class="form-group">
                  <label class="col-sm-3 control-label">Firstname</label>
                  <div class="col-sm-9">
                    <input id="firstname" name="firstname" class="form-control" type="text"
                           value="<?= htmlspecialchars($current_firstname) ?>"
                           pattern="[\-A-Za-z' ]+">
                  </div>
                </div>

                <div class="form-group">
                  <label class="col-sm-3 control-label">Lastname</label>
                  <div class="col-sm-9">
                    <input id="lastname" name="lastname" class="form-control" type="text"
                           value="<?= htmlspecialchars($current_lastname) ?>"
                           pattern="[\-A-Z' ]+">
                  </div>
                </div>

                <div class="form-group">
                  <label class="col-sm-3 control-label" for="institution">Institution</label>
                  <div class="col-sm-9">
                    <input id="institution" name="institution" class="form-control" type="text" 
                           value="<?= htmlspecialchars($current_institution) ?>" 
                           placeholder="e.g., LSCE">
                  </div>
                </div>

                <div class="form-group">
                  <label class="col-sm-3 control-label" for="phone">Phone</label>
                  <div class="col-sm-9">
		    <input id="phone"
                           name="phone"
                           class="form-control"
                           value="<?= htmlspecialchars($current_phone) ?>"
                           type="text"
                           placeholder="e.g., +33 6 12 34 56 78"
                           pattern="\+?[0-9 ]{6,20}">
                  </div>
                </div>

                <div class="form-group">
                  <label class="col-sm-3 control-label">Email</label>
                  <div class="col-sm-9">
                    <input class="form-control" type="text" value="<?= htmlspecialchars($email) ?>" readonly>
                  </div>
                </div>

                <div class="form-group">
                  <div class="col-sm-9 col-sm-offset-3">
                    <button class="btn btn-success" type="submit">Save infos</button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>

      </div><!-- /panel-group -->

      <!-- Keywords (NO accordion) -->
      <div class="panel panel-default">
        <div class="panel-heading"><strong>Keywords</strong></div>
        <div class="panel-body" style="padding-top:0;">
          <ul class="nav nav-tabs" role="tablist" style="margin-top:10px;">
            <li role="presentation" class="active"><a href="#tabSearch" aria-controls="tabSearch" role="tab" data-toggle="tab">Search</a></li>
            <li role="presentation"><a href="#tabMyKeywords" aria-controls="tabMyKeywords" role="tab" data-toggle="tab">My keywords</a></li>
          </ul>

          <div class="tab-content" style="margin-top:15px;">
            <div role="tabpanel" class="tab-pane active" id="tabSearch">
              <select id="select2Search" multiple style="width:100%"></select>

              <div class="radio" id="searchModeRadios" style="margin-top:8px;">
                <label style="margin-right:14px;">
                  <input type="radio" name="searchMode" id="searchModeAnd" value="and" checked> AND (all keywords)
                </label>
                <label>
                  <input type="radio" name="searchMode" id="searchModeOr" value="or"> OR (any keyword)
                </label>
              </div>
            

            </div>

            <!-- My keywords TAB -->
            <div role="tabpanel" class="tab-pane" id="tabMyKeywords">
              <form id="keywordsForm" method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="step" value="save_keywords">

                <select id="select2Keywords" name="keywords[]" multiple style="width:100%"></select>
                <button class="btn btn-primary" type="submit">Save keywords</button>
              </form>
            </div>
          </div>

        </div>
      </div>

      <div style="margin-top:10px;">
        <button type="button" class="btn btn-default" id="btnShowUsersTable">
          Show selected participants 
        </button>
      </div>

    </div>

    <!-- RIGHT -->
    <div class="col-sm-8">
      <div class="viz-wrapper">
        <img id="vizOpen" src="linkOpen.png" title="Open the graph in a new window" />
        <div id="viz"></div>
      </div>
    </div>
  </div><!-- /row -->
</div><!-- /container -->

<script>
// ---------- Shared data ----------
var USER_FILE  = <?php echo json_encode($login, JSON_UNESCAPED_UNICODE); ?>;
var CSRF_TOKEN = <?php echo json_encode($_SESSION['csrf']); ?>;

// ---------- Utilities for tag colors ----------
function hueFromString(str){
  var s = (str || '').toLowerCase(), h = 0 >>> 0;
  for (var i = 0; i < s.length; i++){ h = (h * 31 + s.charCodeAt(i)) >>> 0; }
  return h % 360;
}
function chipColor(keyword, saturation){
  var h = hueFromString(keyword);
  var sat = (typeof saturation === 'number') ? saturation : 70;
  return 'hsla(' + h + ', ' + sat + '%, 80%, 1)';
}

// ---------- Select2 (Search + My keywords) ----------
(function($){
  $(function(){
    var userKeywords = <?php echo json_encode(array_values($current_keywords), JSON_UNESCAPED_UNICODE); ?>;
    var allKeywords  = <?php echo json_encode(array_values($all_keywords_for_select), JSON_UNESCAPED_UNICODE); ?>;
    var allParticipants  = <?php echo json_encode(array_values($scanned_participants), JSON_UNESCAPED_UNICODE); ?>;
    var allInstitutions = <?php echo json_encode(array_values($scanned_institutions), JSON_UNESCAPED_UNICODE); ?>;

    //------------------------------------------------------------------
    // --- My keywords ---
    var $my = $('#select2Keywords').empty();
    // Load ALL keywords (seed + scanned + user) as options…
    allKeywords.forEach(function(k){ $my.append(new Option(k, k, false, false)); });
    // …then select the user's ones
    (userKeywords || []).forEach(function(k){
      var exists = $my.find('option').filter(function(){ return $(this).text().toLowerCase() === String(k).toLowerCase(); }).length > 0;
      if (exists) {
        $my.find('option').filter(function(){ return $(this).text().toLowerCase() === String(k).toLowerCase(); }).prop('selected', true);
      } else {
        $my.append(new Option(k, k, true, true));
      }
    });

    $my.select2({
      tags: true,
      multiple: true,
      allowClear: true,
      width: '100%',
      placeholder: 'Add keywords (press Enter to add a tag).',
      // NO color in dropdown results:
      templateResult: function (data) { return data.text; },
      // Color only on chips (selected tags):
      templateSelection: function (data, container) {
        var txt = (data.text || data.id || '').trim();
        $(container).css({ backgroundColor: chipColor(txt, 70), color: '#000' });
        return data.text;
      }
    });

    $('#keywordsForm').on('submit', function(){
      var $search = $('#tabMyKeywords .select2-search__field');
      var pending = ($search.val() || '').trim();
      if (pending !== '') {
        var exists = $my.find('option').filter(function(){ return $(this).text().toLowerCase() === pending.toLowerCase(); }).length > 0;
        if (!exists) { var opt = new Option(pending, pending, true, true); $my.append(opt); }
        else { var v = $my.val() || []; if (v.indexOf(pending) === -1) { v.push(pending); $my.val(v); } }
        $my.trigger('change'); $search.val('');
      }
    });

    //------------------------------------------------------------------
    var $search = $('#select2Search').empty();

    // --- Group 1 : Keywords ---
    var $groupKeywords = $('<optgroup label="1. Keywords"></optgroup>');
    allKeywords.forEach(function(k) {
      // new Option(text, value, defaultSelected, selected)
      var opt = new Option(k, k, false, false);
      $groupKeywords.append(opt);
    });
    $search.append($groupKeywords);

    // --- Group 2 : Participants ---
    var $groupParticipants = $('<optgroup label="2. Participants"></optgroup>');
    allParticipants.forEach(function(k) {
      var opt = new Option(k, k, false, false);
      $groupParticipants.append(opt);
    });
    $search.append($groupParticipants);

    // --- Group 3 : Institutions ---
    var $groupInstitutions = $('<optgroup label="3. Institutions"></optgroup>');
    allInstitutions.forEach(function(k) {
      var opt = new Option(k, k, false, false);
      $groupInstitutions.append(opt);
    });
    $search.append($groupInstitutions);

    // ---
    $search.select2({
      tags: true,            // allow creating from Search too
      multiple: true,
      allowClear: true,
      width: '100%',
      placeholder: 'Search a keyword',
      // NO color in dropdown results:
      templateResult: function (data) { return data.text; },
      // Color only on chips:
      templateSelection: function (data, container) {
        var txt = (data.text || data.id || '').trim();
        $(container).css({ backgroundColor: chipColor(txt, 70), color: '#000' });
        return data.text;
      }
    });

  });
})(jQuery);

// ---------- Photo Croppie ----------
(function($){
  var croppie = null;
  var $modal;

  $(document).on('click', '#userPhoto', function(e){
    e.preventDefault();
    $('#fileInput').trigger('click');
  });

  $(document).on('change', '#fileInput', function(){
    var f = this.files && this.files[0];
    if (!f) return;

    $modal = $('#cropModal');
    var reader = new FileReader();
    reader.onload = function(ev){
      if (croppie) { croppie.croppie('destroy'); croppie = null; }
      croppie = $('#cropArea').croppie({
        viewport: { width: 200, height: 200, type: 'circle' },  // smaller viewport
        boundary: { width: 500, height: 500 },
        showZoomer: true,
        enableExif: true,
      });
      croppie.croppie('bind', { url: ev.target.result }).then(function(){
        var $slider = $('#cropArea').find('.cr-slider');
        if ($slider.length) {
          var min = parseFloat($slider.attr('min')) || 0;
          $slider.attr({'min':0.1, 'max':5.0, 'step':0.001});
        }
      });
      $modal.modal('show');
    };
    reader.readAsDataURL(f);
  });

  $(document).on('click', '#savePhoto', function(){
    if (!croppie) return;
    croppie.croppie('result', { type:'base64', size:'viewport', format:'png' })
      .then(function(resp){
        $.ajax({
          method: 'POST',
          url: 'savePhoto.php',
          data: { user: USER_FILE, img: resp, csrf: CSRF_TOKEN },
          timeout: 20000
        })
        .done(function(){
          $('#userPhoto').attr('src', 'faces/' + USER_FILE + '.png?' + new Date().getTime());
          $('#cropModal').modal('hide');
          location.reload();
        })
        .fail(function(jqXHR){
          console.error('savePhoto.php error:', jqXHR.status, jqXHR.responseText);
          alert('Photo upload failed (' + jqXHR.status + '). Check server logs / savePhoto.php.');
        });
      });
  });

  $(document).on('click', '#deletePhotoBtn', function(){
    $.ajax({
      method: 'POST',
      url: 'savePhoto.php',
      data: { user: USER_FILE, reset: 1, csrf: CSRF_TOKEN },
      timeout: 10000
    })
    .done(function(){
      // recharge la photo par défaut
      $('#userPhoto').attr('src', 'faces/default.png?' + Date.now());
      location.reload();
    })
    .fail(function(jqXHR){
      console.error('delete photo error:', jqXHR.status, jqXHR.responseText);
      alert('Photo deletion failed (' + jqXHR.status + ').');
    });
  });


  $(document).on('hidden.bs.modal', '#cropModal', function(){
    if (croppie) { croppie.croppie('destroy'); croppie = null; }
    $('#cropArea').empty();
    var fi = document.getElementById('fileInput'); if (fi) fi.value = '';
  });
})(jQuery);
</script>

<!-- ================================================================================ -->
<div class="modal fade" id="cropModal" role="dialog">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">Change your photo</h4>
      </div>
      <div class="modal-body">
	<div id="cropArea"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-dismiss="modal" id="savePhoto">Save</button>
        <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="cropModal2" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title">Crop My photo</h4>
      </div>
      <div class="modal-body"><div id="cropArea"></div></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
        <button id="savePhoto" type="button" class="btn btn-primary">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- ================================================================================ -->
<script>
(function($){

  // -------------------------------------------------------------
  function normKw(s){ 
    return String(s || '').trim().toLowerCase(); 
  }
  
  function getSelectedSearchKeywords(){
    var $s   = $('#select2Search');
    var vals = $s.val() || [];
    // on normalise les chaînes, comme avant
    return vals
      .map(function(v){ return normKw(v); })
      .filter(function(v){ return v.length > 0; });
  }

  function hasKeywords(user, wanted, mode){
    var texts = [];
  
    if (Array.isArray(user.keywords)) {
      texts = texts.concat(user.keywords);
    }
 
    var fullName = [user.infos.lastname || '', user.infos.firstname || ''].join(' ').trim();
    if (fullName.length > 0) {
      texts.push(fullName);
    }
    var institution  = user.infos.institution.trim();
    if (institution.length > 0) {
      texts.push(institution);
    }
  
    texts = texts.map(normKw).filter(Boolean);
  
    if (!Array.isArray(wanted) || wanted.length === 0) return true;
  
    var matchedCount = 0;
  
    wanted.forEach(function(w){
      //var re = new RegExp(w + '\\b', 'ig');
      var re;
      if (w.startsWith('^')) {
        re = new RegExp(w, 'ig');      // pas de \b
      } else {
        re = new RegExp(w + '\\b', 'ig');
      }
      var found = texts.some(function(txt){
        return re.test(txt);
      });
  
      if (found) {
        matchedCount += 1;
      }
    });
  
    // mode OR : au moins un mot-clé matché
    if (mode === 'or') {
      return matchedCount >= 1;
    }
  
    // mode AND (par défaut) : tous les mots-clés doivent matcher
    return matchedCount === wanted.length;
  }

  // -------------------------------------------------------------
  function renderViz(){

    var wanted = getSelectedSearchKeywords();
    var $viz = $('#viz');

    $viz.empty();

    if (!Array.isArray(window.usersData) || window.usersData.length === 0){
      $viz.append('<div class="muted">No participants found</div>');
      return;
    }

    var list = window.usersData;

    if (wanted.length > 0){
      var mode = $('input[name="searchMode"]:checked').val() || 'and';
      list = list.filter(function(u){ return hasKeywords(u, wanted, mode); });
    }
    if (list.length === 0){
      $viz.append('<div class="muted">No participant matches the selected keywords.</div>');
      return;
    }

    window.currentSelectedUsers = list.slice();

    // for d3_graph
    var nodes = list.map(function(u){
      return {
        email:     u.email,
        firstname: u.infos.firstname || '',
        lastname:      u.infos.lastname || '',
        face:      u.face,
        keywords:  Array.isArray(u.keywords) ? u.keywords : []
      };
    });

    buildGraph(nodes, "#viz");
    window.usersFound = nodes;

  }

  // ---------- DataTable for visible users ----------
  var usersTable = null;
  
  function usersListToTableData(list){
    return (list || []).map(function(u){
      var kws = '';
      if (Array.isArray(u.keywords)) kws = u.keywords.join(', ');
      else if (Array.isArray(u.tags)) kws = u.tags.join(', ');
  
      return {
        email:   u.email  || '',
        firstname:   u.infos.firstname || '',
        lastname:   u.infos.lastname || '',
        institution:     u.infos.institution    || '',
        phone:   u.infos.phone  || '',
        keywords: kws
      };
    });
  }
  
  function openUsersTableModal(){
    var data = window.currentSelectedUsers || [];
  
    if (!Array.isArray(data) || data.length === 0){
      alert('No visible users to display.');
      return;
    }
    if (!$.fn.DataTable){
      console.error('DataTables is not loaded.');
      return;
    }
  
    var rows = usersListToTableData(data);
    var $table = $('#usersTable');
  
    if (usersTable) {
      usersTable.clear();
      usersTable.rows.add(rows);
      usersTable.draw();
    } else {
      usersTable = $table.DataTable({
        data: rows,
        columns: [
          { data: 'firstname',    title: 'Firstname'   },
          { data: 'lastname',    title: 'Lastname'   },
          { data: 'email',    title: 'Email'   },
          { data: 'institution',      title: 'Institution'     },
          { data: 'phone',    title: 'Phone'   },
          { data: 'keywords', title: 'Keywords'}
	],
	lengthMenu: [[5, 10], [5, 10]],
        pageLength: 5,
        order: [[0, 'asc']]
      });
    }
  
    $('#usersTableModal').modal('show');
  }

  // -------------------------------------------------------------
  // Hook on Select2 Search changes and initial render
  $(document).on('change', '#select2Search', function(){ renderViz(); });
  $(document).on('change', 'input[name="searchMode"]', function(){ renderViz(); });
  $(document).ready(function(){ renderViz(); });

  $(document).on('click', '#btnShowUsersTable', function(){ openUsersTableModal(); });

  // -------------------------------------------------------------
  $('#lastname').on('input', function() {
      let v = $(this).val();
      // Mettre en majuscules
      v = v.toUpperCase();
      // Supprimer tout caractère non autorisé (A-Z, espace, tiret, apostrophe)
      v = v.replace(/[^A-Z '-]/g, '');
      // Remplacer les espaces multiples par un seul espace
      v = v.replace(/\s+/g, ' ');
      // Remplacer les tirets multiples par un seul tiret
      v = v.replace(/-+/g, '-');
      // Supprimer les espaces autour des tirets ("JEAN - PIERRE" → "JEAN-PIERRE")
      v = v.replace(/\s*-\s*/g, '-');
      // Trim final
      v = v.trim();
      $(this).val(v);
  });

  // -------------------------------------------------------------
  $('#firstname').on('input', function() {
      let v = $(this).val();
      // Mettre en minuscules
      v = v.toLowerCase();
      // Remplacer les espaces multiples par un seul espace
      v = v.replace(/\s+/g, ' ');
      // Remplacer les tirets multiples par un seul tiret
      v = v.replace(/-+/g, '-');
      // Supprimer les espaces autour des tirets ("JEAN - PIERRE" → "JEAN-PIERRE")
      v = v.replace(/\s*-\s*/g, '-');
      // Majuscule après espace ou tiret
      v = v.replace(/(^|[\s-])([a-z])/g, function(m, sep, letter) {
        return sep + letter.toUpperCase();
      });
      $(this).val(v);
  });

  // -------------------------------------------------------------
  $('#phone').on('input', function() {
      let v = $(this).val()
          .replace(/[^0-9 +]/g, '')   // autorise chiffre + espace + plus
          .replace(/\+/g, (m, i) => i === 0 ? '+' : '') // un seul +
          .replace(/\s+/g, ' ');
  
      $(this).val(v.trim());
  });

  // -------------------------------------------------------------
  $('#infosCollapse')
    .on('show.bs.collapse', function(){
      $('#headingInfos .glyphicon')
        .removeClass('glyphicon-chevron-right').addClass('glyphicon-chevron-down');
    })
    .on('hide.bs.collapse', function(){
      $('#headingInfos .glyphicon')
        .removeClass('glyphicon-chevron-down').addClass('glyphicon-chevron-right');
    });

  $('.panel-heading').on('click', function(e) {
      // éviter double-click si on clique sur le vrai lien
      if (!$(e.target).closest('a').length) {
          $(this).find('[data-toggle="collapse"]').trigger('click');
      }
  });


  // -------------------------------------------------------------
  $('#vizOpen').on('click', function () {
      var newWindow = window.open('d3_graph.html');
      newWindow.usersFound = usersFound;
  });

})(jQuery);
</script>

<?php
// --- Expose usersData (login + JSON content) to JS ---
function read_users_directory($dir){
  global $FACES_DIR;

  $out = [];
  if (!is_dir($dir)) return $out;

  foreach (glob($dir . DIRECTORY_SEPARATOR . "*.json") as $file){
    $login = basename($file, ".json");
    $raw   = @file_get_contents($file);
    if ($raw === false) continue;

    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];

    // Injecte toujours le login
    $data['login'] = $login;

    // ---- AJOUT : photo / fallback ----
    $faceFile = $FACES_DIR . "/" . $login . ".png";
    if (is_file($faceFile)) {
        $data['face'] = "faces/" . $login . ".png";
    } else {
        $data['face'] = "faces/default.png";
    }

    $out[] = $data;
  }

  return $out;
}

$__users = read_users_directory(__DIR__ . DIRECTORY_SEPARATOR . "users");
?>

<script>
// usersData: array of objects { login, keywords?, ... }
window.usersData = <?php echo json_encode($__users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

$(function(){ 
  if (<?php echo json_encode((($_POST['step'] ?? '') === 'save_keywords')); ?>) { 
    $('a[href="#tabMyKeywords"]').tab('show');
  }
});
</script>

<!-- ================================================================================ -->
<?php
$gdprAccepted = !empty($all_json['gdpr']['accepted']);
?>

<div class="modal fade" id="gdprModal" tabindex="-1" role="dialog" aria-labelledby="gdprTitle"
     data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog" role="document">
    <form method="post" class="modal-content">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
      <input type="hidden" name="step" value="gdpr_accept">

      <div class="modal-header">
        <h4 class="modal-title" id="gdprTitle">Privacy & Data Consent</h4>
      </div>

      <div class="modal-body">
        <p style="margin-bottom:8px;">
          This platform stores the following personal information: your <strong>email address</strong>,
          your <strong>profile photo</strong> (if you choose to upload one), and the <strong>keywords</strong>
          that you personally select.
        </p>

        <ul style="margin:0 0 10px 18px;">
          <li><strong>Purpose</strong>: to manage your user profile and allow other participants to search and visualize shared data.</li>
          <li><strong>Legal basis</strong>: explicit consent (which can be withdrawn at any time).</li>
          <li><strong>Retention period</strong>: as long as your account remains active or until consent is withdrawn.</li>
          <li><strong>Your rights</strong>: access, rectification, deletion, and withdrawal of consent at any time.</li>
          <li><strong>Contact for data-related requests</strong>: <a href="mailto:dpo@example.org">dpo@example.org</a></li>
        </ul>

        <p class="text-muted" style="margin-bottom:12px;">
          For more details, please consult the <a href="privacy.html" target="_blank">Privacy Policy</a>.
        </p>

        <p class="text-warning" style="margin-bottom:10px;">
          If you choose to <strong>decline</strong>, any existing photo, personal information, and saved keywords associated with your account will be permanently deleted.
        </p>


        <div class="checkbox">
          <label>
            <input type="checkbox" name="gdpr_accept" value="1" required>
            I consent to the storage of my email, photo, and selected keywords as described above.
          </label>
        </div>
      </div>

      <div class="modal-footer">
        <button type="submit" class="btn btn-primary">I Agree</button>
        <button type="submit"
                name="gdpr_accept"
                value="0"
                formnovalidate
                class="btn btn-default">I Decline</button>
        <button type="button"
                class="btn btn-default"
                data-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
  $(document).on('click', '#openGdpr', function (e) {
    e.preventDefault();
    $('#gdprModal').modal({ backdrop: 'static', keyboard: false }).modal('show');
  });

  window._gdprAccepted = <?= $gdprAccepted ? 'true' : 'false' ?>;

  function applyGdprState(){
    if (window._gdprAccepted) {
      // enable
      $('#userPhoto,#upload,#savePhoto,#deletePhotoBtn')
        .prop('disabled', false).removeClass('disabled').css('pointer-events','');

      $('#infosForm :input').not('[type=hidden]').prop('disabled', false);
      $('#infosForm button[type="submit"]').prop('disabled', false).removeClass('disabled');

      $('#keywordsForm button[type="submit"]').prop('disabled', false).removeClass('disabled');

      var $my = $('#select2My');
      $my.prop('disabled', false).trigger('change.select2');
      // Optionnel: recharger tes valeurs si besoin (window._myKeywords)
    } else {
      // disable + vider UI locale
      $('#userPhoto,#upload,#savePhoto,#deletePhotoBtn')
        .prop('disabled', true).addClass('disabled')
        .attr('title','Accept the privacy terms to enable.');

      $('#infosForm :input').not('[type=hidden]').prop('disabled', true);
      $('#infosForm button[type="submit"]').prop('disabled', true).addClass('disabled')
        .attr('title','Accept the privacy terms to enable.');

      $('#keywordsForm button[type="submit"]').prop('disabled', true).addClass('disabled')
        .attr('title','Accept the privacy terms to enable.');

      var $my = $('#select2My');
      $my.val(null).trigger('change');           // vide la sélection visible
      $my.prop('disabled', true).trigger('change.select2');
    }
  }

  $(function(){
    applyGdprState();
  });
</script>

<div class="modal fade" id="usersTableModal" tabindex="-1" role="dialog" aria-labelledby="usersTableTitle">
  <div class="modal-dialog" id="usersTableDialog" role="document">
    <div class="modal-content">

      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title" id="usersTableTitle">Selected participants</h4>
      </div>

      <div class="modal-body">
        <table id="usersTable" class="display table table-striped table-bordered" cellspacing="0" width="100%">
          <thead>
            <tr>
              <th>Firstname</th>
              <th>Name</th>
              <th>Email</th>
              <th>Institution</th>
              <th>Phone</th>
              <th>Keywords</th>
            </tr>
          </thead>
          <tbody>
          </tbody>
        </table>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">
          Close
        </button>
      </div>

    </div>
  </div>
</div>

</body>
</html>
