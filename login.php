<?php
declare(strict_types=1);
session_start();

/* ---------------- CONFIG ---------------- */
// Whitelist file (one email per line). Protect conf/ with .htaccess or place it outside webroot.
$WHITELIST_FILE     = __DIR__ . '/conf/whitelist.txt';
$OTP_TTL_SECONDS    = 10 * 60;     // OTP validity window: 10 minutes
$MAX_SENDS_PER_HOUR = 3;           // anti-spam: max codes/hour per session
$MAX_TRIES          = 5;           // max OTP attempts
$SHOW_DEBUG_OTP     = false;       // DEV ONLY: echo OTP if mail() fails

/* ---------------- UTILS ---------------- */
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
function check_csrf(): void {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(400); exit('Invalid CSRF token.');
    }
}
/** Load whitelist: one email per line; ignores empty lines and lines starting with # */
function load_whitelist(string $filepath): array {
    static $cache = null; if ($cache !== null) return $cache;
    $allowed = [];
    if (!is_file($filepath)) return $cache = $allowed;
    $lines = @file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $cache = $allowed;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $email = $line;
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $allowed[] = $email;
    }
    return $cache = array_values(array_unique($allowed));
}

/* ---------------- OPTIONAL: LOGOUT (from welcome.php link) ---------------- */
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

/* ---------------- STATE ---------------- */
$error = '';
$info  = '';

/* ---------------- STEP 1: Request OTP ---------------- */
if (($_POST['step'] ?? '') === 'request_otp') {
    check_csrf();
    $email = trim((string)($_POST['email'] ?? ''));

    $WHITELIST = load_whitelist($WHITELIST_FILE);
    
    // Case-insensitive whitelist check; keep the exact casing from whitelist as login
    $found = false;
    foreach ($WHITELIST as $w) {
        if (strcasecmp(trim($w), trim($email)) === 0) {
            $email = trim($w); // normalize to whitelist canonical casing
            $found = true;
            break;
        }
    }
    if (!$found) {
        $error = "Email not allowed.";
    } else {

        // Basic session-scoped rate limit
        $_SESSION['sent_count']    = $_SESSION['sent_count']    ?? 0;
        $_SESSION['first_send_at'] = $_SESSION['first_send_at'] ?? time();
        if (time() > ($_SESSION['first_send_at'] + 3600)) {
            $_SESSION['sent_count'] = 0;
            $_SESSION['first_send_at'] = time();
        }
        if ($_SESSION['sent_count'] >= $MAX_SENDS_PER_HOUR) {
            $error = "Too many codes sent recently. Please wait.";
        } else {
            $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $_SESSION['otp_hash']       = hash('sha256', $otp);
            $_SESSION['otp_expires_at'] = time() + $OTP_TTL_SECONDS;
            $_SESSION['otp_email']      = $email;
            $_SESSION['otp_tries']      = 0;
            $_SESSION['sent_count']++;

            $subject = "Your login code as Project participant";
            $message = "
            <html>
              <body>
                <p>Hello,</p>
                <p>Your login code (valid " . ($OTP_TTL_SECONDS/60) . " min): 
                  <strong>$otp</strong>
                </p>
                <p>This is an automated message. Do not reply.</p>
              </body>
            </html>
            ";
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: no-reply@org\r\n";
            $sent = @mail($email, $subject, $message, $headers);

            if ($sent) {
                $info = "A code has been sent to $email.";
            } else {
                $error = "mail() failed. Please check your mail configuration.";
                if ($SHOW_DEBUG_OTP) { $info = "DEV OTP: $otp"; }
            }
        }
    }
}

/* ---------------- STEP 2: Verify OTP ---------------- */
if (($_POST['step'] ?? '') === 'verify_otp') {
    check_csrf();
    $code = trim((string)($_POST['otp'] ?? ''));
    if (empty($_SESSION['otp_hash']) || empty($_SESSION['otp_email'])) {
        $error = "No pending code. Please request a new one.";
    } elseif (time() > ($_SESSION['otp_expires_at'] ?? 0)) {
        $error = "Code expired. Please request a new one.";
        unset($_SESSION['otp_hash'], $_SESSION['otp_expires_at'], $_SESSION['otp_email'], $_SESSION['otp_tries']);
    } else {
        $_SESSION['otp_tries'] = ($_SESSION['otp_tries'] ?? 0) + 1;
        if ($_SESSION['otp_tries'] > $MAX_TRIES) {
            $error = "Too many attempts. Please request a new code.";
            unset($_SESSION['otp_hash'], $_SESSION['otp_expires_at'], $_SESSION['otp_email'], $_SESSION['otp_tries']);
        } else {
            if (hash_equals($_SESSION['otp_hash'], hash('sha256', $code))) {
                // Success
                $_SESSION['auth_email'] = $_SESSION['otp_email'];
                unset($_SESSION['otp_hash'], $_SESSION['otp_expires_at'], $_SESSION['otp_email'], $_SESSION['otp_tries']);
                session_regenerate_id(true);
                header('Location: welcome.php'); exit;
            } else {
                $error = "Incorrect code. Attempts left: " . max(0, $MAX_TRIES - ($_SESSION['otp_tries'] ?? 0));
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Login (Project)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/js/bootstrap.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.4.1/css/bootstrap.css">

<style>
body{background:#f7f7f9}
.panel{box-shadow:none}
.panel-heading .small{float:right}
.form-box{max-width:500px;margin:40px auto}
.help-muted{color:#777}
</style>
</head>
<body>

<div class="container form-box">
  <div class="panel panel-default">
    <div class="panel-heading">
      <strong>Email sign-in (Project)</strong>
      <span class="small"><a href="login.php?logout=1">Reset session</a></span>
    </div>
    <div class="panel-body">

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($info): ?>
        <div class="alert alert-info"><?= htmlspecialchars($info) ?></div>
      <?php endif; ?>

      <?php if (empty($_SESSION['otp_hash'])): ?>
        <!-- STEP 1: Request OTP -->
        <form method="post" class="form-horizontal" autocomplete="one-time-code" novalidate>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
          <input type="hidden" name="step" value="request_otp">

          <div class="form-group">
            <label for="email" class="col-sm-4 control-label">Email address</label>
            <div class="col-sm-8">
              <div class="input-group">
                <span class="input-group-addon"><i class="glyphicon glyphicon-envelope"></i></span>
                <input type="email" name="email" id="email" class="form-control" placeholder="example@domain.com" required>
              </div>
              <p class="help-block help-muted">Only emails listed in a whitelist are allowed.</p>
            </div>
          </div>

          <div class="form-group">
            <div class="col-sm-8 col-sm-offset-4">
              <button type="submit" class="btn btn-primary btn-block">Send code</button>
            </div>
          </div>
        </form>

      <?php else: ?>
        <!-- STEP 2: Verify OTP -->
        <form method="post" class="form-horizontal" novalidate>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
          <input type="hidden" name="step" value="verify_otp">

          <div class="form-group">
            <label for="otp" class="col-sm-4 control-label">6-digit code</label>
            <div class="col-sm-8">
              <div class="input-group">
                <span class="input-group-addon"><i class="glyphicon glyphicon-lock"></i></span>
                <input type="text"
                       id="otp"
                       name="otp"
                       maxlength="6"
                       pattern="[0-9]{6}"
                       inputmode="numeric"
                       class="form-control text-center"
                       placeholder="123456"
                       required>
              </div>
              <p class="help-block help-muted">The code is valid for <?= (int)($OTP_TTL_SECONDS/60) ?> minutes.</p>
            </div>
          </div>

          <div class="form-group">
            <div class="col-sm-8 col-sm-offset-4">
              <button type="submit" class="btn btn-success btn-block">Verify code</button>
            </div>
          </div>
        </form>

        <hr>
        <p class="text-center">
          <a href="login.php" class="btn btn-default btn-sm">Request a new code</a>
        </p>
      <?php endif; ?>

    </div>
  </div>

</div>

</body>
</html>
